<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { FileText, ImagePlus, LayoutTemplate, Plus, Sparkles } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, EmptyState, ErrorState, PageFrame, SkeletonRows, TranslatedFields, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { lettersApi } from '../api';
import { newBlock, templateTone } from '../lib';

/**
 * The company's letter designs as cards (active first), the ready-made
 * ones to add (a salary one only for people who may use salary), a new
 * design from scratch, and the images designs use: letterheads,
 * signatures, stamps, logos (private, shown through short-lived links).
 */
const org = currentOrganization();
const letters = lettersApi(org.id);
const router = useRouter();

const list = useResource(() => letters.templates());
const designs = computed(() => list.data.value?.data ?? []);
const options = useResource(() => letters.options());
const opts = computed(() => options.data.value?.data ?? null);
const canDesign = computed(() => Boolean(opts.value?.can?.design));
const salaryAllowed = computed(() => Boolean(opts.value?.salary?.allowed));
const used = computed(() => new Set(designs.value.map((item) => item.preset).filter(Boolean)));

// Ready-made designs.
const choosing = ref(false);
const adding = ref('');
async function addPreset(preset) {
    adding.value = preset.key;
    try {
        const { data } = await letters.addPreset(preset.key);
        toast.success(t('letters.designs.preset_added'));
        router.push({ name: 'letters-editor', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        adding.value = '';
    }
}

// A new design from scratch: name, kind and code; the editor does the rest.
const making = ref(false);
const form = reactive({ name: { en: '', bn: '' }, kind: 'other', code: '' });
const errors = ref({});
const saving = ref(false);
function openNew() {
    Object.assign(form, { name: { en: '', bn: '' }, kind: 'other', code: '' });
    errors.value = {};
    making.value = true;
}
async function create() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await letters.createTemplate({
            name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
            kind: form.kind,
            code: form.code.trim().toUpperCase(),
            bodies: { en: [{ ...newBlock('paragraph'), text: t('letters.designs.first_text') }] },
        });
        router.push({ name: 'letters-editor', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

// Images.
const assetList = useResource(() => letters.assets());
const assets = computed(() => assetList.data.value?.data ?? []);
const upload = reactive({ kind: 'signature', name: '', file: null });
const uploading = ref(false);
const fileInput = ref(null);
async function addAsset() {
    if (!upload.file || !upload.name.trim()) return;
    uploading.value = true;
    try {
        const body = new FormData();
        body.append('kind', upload.kind);
        body.append('name', upload.name.trim());
        body.append('file', upload.file);
        await letters.addAsset(body);
        toast.success(t('letters.assets.added'));
        Object.assign(upload, { name: '', file: null });
        if (fileInput.value) fileInput.value.value = '';
        assetList.reload();
    } catch (error) {
        toast.error(Object.values(error.errors ?? {})[0]?.[0] ?? error.message);
    } finally {
        uploading.value = false;
    }
}
async function toggleAsset(asset) {
    try {
        await letters.updateAsset(asset.id, { is_active: !asset.is_active });
        assetList.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('letters.designs.title')" :description="t('letters.designs.text')" width="full" :resource="list">
        <template #actions>
            <template v-if="canDesign">
                <AppButton :icon="Sparkles" @click="choosing = true">{{ t('letters.designs.ready_made') }}</AppButton>
                <AppButton variant="primary" :icon="Plus" @click="openNew">{{ t('letters.designs.new') }}</AppButton>
            </template>
        </template>

        <section v-if="!designs.length" class="card">
            <EmptyState :icon="LayoutTemplate" :title="t('letters.designs.empty')" :text="t('letters.designs.empty_text')">
                <AppButton v-if="canDesign" variant="primary" :icon="Sparkles" @click="choosing = true">{{ t('letters.designs.ready_made') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <li v-for="design in designs" :key="design.id">
                <RouterLink :to="{ name: 'letters-editor', params: { id: design.id } }" class="card flex h-full flex-col gap-3 p-4 transition hover:border-brand/40 hover:shadow-md">
                    <div class="flex items-start gap-3">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><FileText class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14.5px] font-semibold">{{ textIn(design.name) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t(`letters.kinds.${design.kind}`) }} · <span class="font-mono" dir="ltr">{{ design.code }}</span></span>
                        </span>
                        <AppBadge :tone="templateTone(design.status)" dot>{{ t(`letters.template_status.${design.status}`) }}</AppBadge>
                    </div>
                    <div class="mt-auto flex flex-wrap items-center gap-1.5 text-[12px] text-muted">
                        <span v-for="code in design.locales" :key="code" class="rounded-md bg-subtle px-1.5 py-0.5">{{ t(`letters.languages.${code}`) }}</span>
                        <span v-if="design.uses_salary" class="rounded-md bg-warn-soft px-1.5 py-0.5 text-warn">💰 {{ t('letters.salary.badge') }}</span>
                        <span class="ms-auto">{{ t('letters.designs.version', { version: design.version }) }} · {{ formatDate(design.updated_at, { dateStyle: 'medium' }) }}</span>
                    </div>
                </RouterLink>
            </li>
        </ul>

        <!-- Images. -->
        <section class="card mt-6">
            <header class="flex flex-wrap items-center gap-2 border-b border-line px-5 py-3">
                <h2 class="flex-1 text-[15px] font-semibold">{{ t('letters.assets.title') }}</h2>
                <span class="text-[12.5px] text-muted">{{ t('letters.assets.hint', { max: opts?.asset_max_kb ?? 1024 }) }}</span>
            </header>
            <form v-if="canDesign" class="flex flex-wrap items-end gap-2 border-b border-line px-5 py-3" @submit.prevent="addAsset">
                <AppField v-slot="{ id }" :label="t('letters.assets.kind')" class="w-40">
                    <select :id="id" v-model="upload.kind" class="field-input">
                        <option v-for="kind in ['letterhead', 'signature', 'stamp', 'logo']" :key="kind" :value="kind">{{ t(`letters.assets.kinds.${kind}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('letters.assets.name')" class="min-w-44 flex-1">
                    <input :id="id" v-model="upload.name" maxlength="80" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('letters.assets.file')" class="min-w-52 flex-1">
                    <input :id="id" ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="field-input" @change="upload.file = $event.target.files?.[0] ?? null" />
                </AppField>
                <AppButton type="submit" :icon="ImagePlus" :loading="uploading" :disabled="!upload.file || !upload.name.trim()">{{ t('letters.assets.add') }}</AppButton>
            </form>
            <SkeletonRows v-if="assetList.loading.value && !assetList.data.value" :rows="2" />
            <ErrorState v-else-if="assetList.error.value" compact :error="assetList.error.value" @retry="assetList.reload()" />
            <p v-else-if="!assets.length" class="px-5 py-6 text-center text-[13px] text-muted">{{ t('letters.assets.empty') }}</p>
            <ul v-else class="grid gap-3 p-4 sm:grid-cols-3 lg:grid-cols-5">
                <li v-for="asset in assets" :key="asset.id" class="rounded-xl border border-line p-2" :class="{ 'opacity-50': !asset.is_active }">
                    <div class="grid h-24 place-items-center rounded-lg bg-white"><img :src="asset.url" alt="" class="max-h-20 max-w-full object-contain" /></div>
                    <div class="mt-2 truncate text-[13px] font-medium">{{ asset.name }}</div>
                    <div class="flex items-center justify-between text-[12px] text-muted">
                        {{ t(`letters.assets.kinds.${asset.kind}`) }}
                        <button v-if="canDesign" type="button" class="font-medium text-brand-text hover:underline" @click="toggleAsset(asset)">{{ asset.is_active ? t('letters.assets.switch_off') : t('letters.assets.switch_on') }}</button>
                    </div>
                </li>
            </ul>
        </section>

        <!-- Ready-made designs. -->
        <AppDialog :open="choosing" :title="t('letters.designs.ready_made')" :description="t('letters.designs.ready_made_text')" :icon="Sparkles" size="lg" @close="choosing = false">
            <ul class="grid gap-2 sm:grid-cols-2">
                <li v-for="preset in opts?.presets ?? []" :key="preset.key" class="flex flex-col gap-2 rounded-xl border border-line p-3">
                    <div class="flex items-start gap-2">
                        <span class="flex-1 text-[14px] font-semibold">{{ textIn(preset.name) }}</span>
                        <span v-if="preset.uses_salary" :title="t('letters.salary.badge')">💰</span>
                    </div>
                    <p class="text-[12.5px] text-muted">{{ textIn(preset.description) }}</p>
                    <p v-if="preset.uses_salary && !salaryAllowed" class="text-[12px] text-warn">{{ t('letters.salary.not_allowed') }}</p>
                    <AppButton
                        size="sm"
                        class="mt-auto self-start"
                        :loading="adding === preset.key"
                        :disabled="(preset.uses_salary && !salaryAllowed) || Boolean(adding)"
                        @click="addPreset(preset)"
                    >
                        {{ used.has(preset.key) ? t('letters.designs.open_added') : t('letters.designs.add') }}
                    </AppButton>
                </li>
            </ul>
        </AppDialog>

        <!-- A new design. -->
        <AppDialog :open="making" :title="t('letters.designs.new')" :icon="Plus" @close="making = false">
            <form id="letters-new" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="create">
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('letters.designs.name')" :errors="errors" error-prefix="name" required :maxlength="80" />
                </div>
                <AppField v-slot="{ id }" :label="t('letters.designs.kind')" :error="errors.kind?.[0]">
                    <select :id="id" v-model="form.kind" class="field-input">
                        <option v-for="kind in opts?.kinds ?? []" :key="kind" :value="kind">{{ t(`letters.kinds.${kind}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('letters.designs.code')" :hint="t('letters.designs.code_hint')" :error="errors.code?.[0]">
                    <input :id="id" v-model="form.code" maxlength="10" class="field-input font-mono uppercase" dir="ltr" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="making = false">{{ t('letters.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="letters-new" :loading="saving" :disabled="!form.name.en?.trim() || form.code.trim().length < 2">{{ t('letters.designs.create') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
