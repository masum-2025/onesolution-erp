<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, Award, Check, IdCard, ImagePlus, Mail, Plus, Sparkles } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { i18n, t } from '@/lib/i18n';
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textLocales, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { educationApi } from '../api';

/**
 * The institution's designs for ID cards, certificates and letters: the
 * ready-made ones to start from, its own (opened in the designer), and the
 * images they use (logos, signatures, backgrounds). Designing needs
 * education.manage; people who issue documents see the list.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const router = useRouter();
const KIND_ICONS = { id_card: IdCard, certificate: Award, letter: Mail };

const list = useResource(() => education.templates());
const templates = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const canDesign = computed(() => meta.value?.can?.design === true);
const made = computed(() => new Set(meta.value?.made_presets ?? []));

const applying = ref(null);
async function apply(key) {
    applying.value = key;
    try {
        const { data } = await education.applyDocumentPreset(key);
        toast.success(t('education.designs.added'));
        router.push({ name: 'education-designer', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        applying.value = null;
    }
}

// A design from an empty page.
const creating = ref(false);
const form = reactive({ name: {}, kind: 'certificate', locale: i18n.locale, size: 'a4', orientation: 'portrait' });
const errors = ref({});
const saving = ref(false);
function openNew() {
    Object.assign(form, { name: textsFor({}), kind: 'certificate', locale: i18n.locale, size: 'a4', orientation: 'portrait' });
    errors.value = {};
    creating.value = true;
}
function kindChanged() {
    if (form.kind === 'id_card') Object.assign(form, { size: 'id_card', orientation: 'landscape' });
    else if (form.size === 'id_card') Object.assign(form, { size: 'a4', orientation: 'portrait' });
}
async function create() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await education.createTemplate({
            kind: form.kind, name: cleanTexts(form.name), locale: form.locale,
            page: { size: form.size, orientation: form.orientation }, layout: { background: { color: '#ffffff' }, elements: [] },
        });
        toast.success(t('education.designs.made'));
        router.push({ name: 'education-designer', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

// Images.
const assets = useResource(() => education.assets({ all: 1 }));
const adding = ref(false);
const asset = reactive({ kind: 'logo', name: '', file: null });
const assetErrors = ref({});
function openAsset() {
    Object.assign(asset, { kind: 'logo', name: '', file: null });
    assetErrors.value = {};
    adding.value = true;
}
function pick(event) {
    asset.file = event.target.files?.[0] ?? null;
    if (asset.file && !asset.name) asset.name = asset.file.name.replace(/\.[^.]+$/, '').slice(0, 80);
}
async function upload() {
    saving.value = true;
    assetErrors.value = {};
    try {
        await education.addAsset(asset.kind, asset.name.trim(), asset.file);
        toast.success(t('education.designs.image_saved'));
        adding.value = false;
        assets.reload();
    } catch (error) {
        assetErrors.value = error.errors ?? {};
        if (error.data?.field) assetErrors.value = { ...assetErrors.value, [error.data.field]: [error.message] };
        if (!Object.keys(assetErrors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function toggleAsset(item) {
    try {
        await education.updateAsset(item.id, { is_active: !item.is_active });
        assets.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const statusTone = { draft: 'neutral', active: 'ok', retired: 'outline' };
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-documents' }" :icon="ArrowLeft" class="mb-3">{{ t('education.designs.back') }}</AppButton>
        <PageHeader :title="t('education.designs.title')" :description="t('education.designs.text')">
            <template v-if="canDesign" #actions>
                <AppButton variant="primary" :icon="Plus" @click="openNew">{{ t('education.designs.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <template v-else>
            <!-- Ready-made designs. -->
            <section v-if="canDesign" class="mb-8">
                <h2 class="flex items-center gap-2 text-[14.5px] font-semibold text-fg"><Sparkles class="size-4 text-brand-text" aria-hidden="true" />{{ t('education.designs.ready') }}</h2>
                <p class="mb-3 text-[12.5px] text-muted">{{ t('education.designs.ready_text') }}</p>
                <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="preset in meta?.presets ?? []" :key="preset.key" class="card flex items-start gap-3 p-4">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><component :is="KIND_ICONS[preset.kind]" class="size-5" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-semibold text-fg">{{ textIn(preset.name) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ textIn(preset.description) }}</span>
                        </span>
                        <AppBadge v-if="made.has(preset.key)" tone="ok" :icon="Check">{{ t('education.designs.added') }}</AppBadge>
                        <AppButton v-else size="sm" :loading="applying === preset.key" :disabled="applying !== null" @click="apply(preset.key)">{{ t('education.designs.use') }}</AppButton>
                    </li>
                </ul>
            </section>

            <!-- Designs here. -->
            <section class="mb-8">
                <h2 class="mb-3 text-[14.5px] font-semibold text-fg">{{ t('education.designs.mine') }}</h2>
                <div v-if="!templates.length" class="card">
                    <EmptyState :icon="IdCard" :title="t('education.designs.empty_title')" :text="t('education.designs.empty_text')" compact />
                </div>
                <ul v-else class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="item in templates" :key="item.id">
                        <RouterLink :to="{ name: 'education-designer', params: { id: item.id } }" class="card flex h-full items-start gap-3 p-4 transition hover:border-brand/40 hover:shadow-sm">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-subtle text-fg-2" aria-hidden="true"><component :is="KIND_ICONS[item.kind]" class="size-5" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-semibold text-fg">{{ item.name_text }}</span>
                                <span class="block text-[12.5px] text-muted">
                                    {{ t(`education.document_kinds.${item.kind}`) }} · {{ t(`education.page_sizes.${item.page.size}`) }} · {{ item.locale.toUpperCase() }}
                                </span>
                                <span class="block text-[12px] text-faint">{{ t('education.designs.elements', { count: item.elements }) }} · v{{ item.version }}<template v-if="item.updated_at"> · {{ formatDate(item.updated_at) }}</template></span>
                            </span>
                            <AppBadge :tone="statusTone[item.status]" dot>{{ t(`education.template_statuses.${item.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <!-- Images. -->
            <section>
                <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
                    <div>
                        <h2 class="text-[14.5px] font-semibold text-fg">{{ t('education.designs.images') }}</h2>
                        <p class="text-[12.5px] text-muted">{{ t('education.designs.images_text') }}</p>
                    </div>
                    <AppButton v-if="canDesign" size="sm" :icon="ImagePlus" @click="openAsset">{{ t('education.designs.add_image') }}</AppButton>
                </div>
                <p v-if="!(assets.data.value?.data ?? []).length" class="card px-5 py-4 text-[13px] text-muted">{{ t('education.designs.images_empty') }}</p>
                <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-6">
                    <li v-for="item in assets.data.value.data" :key="item.id" class="card overflow-hidden" :class="item.is_active ? '' : 'opacity-60'">
                        <div class="grid aspect-[4/3] place-items-center bg-[repeating-conic-gradient(#f1f5f9_0_25%,#fff_0_50%)] bg-[length:16px_16px] p-2">
                            <img :src="item.url" :alt="item.name" class="max-h-full max-w-full object-contain" loading="lazy" />
                        </div>
                        <div class="p-2.5">
                            <p class="truncate text-[12.5px] font-medium text-fg">{{ item.name }}</p>
                            <p class="text-[11.5px] text-muted">{{ t(`education.designs.image_kinds.${item.kind}`) }}</p>
                            <AppButton v-if="canDesign" size="sm" variant="ghost" class="mt-1 -ms-2" @click="toggleAsset(item)">{{ item.is_active ? t('education.designs.image_off') : t('education.designs.image_on') }}</AppButton>
                        </div>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="creating" :title="t('education.designs.new_title')" size="lg" @close="creating = false">
            <form id="education-new-design" class="space-y-4" novalidate @submit.prevent="create">
                <TranslatedFields v-model="form.name" :label="t('education.designs.name')" required :maxlength="80" :errors="errors" error-prefix="name" autofocus />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('education.designs.kind')">
                        <select :id="id" v-model="form.kind" class="field-input" @change="kindChanged">
                            <option v-for="kind in ['id_card', 'certificate', 'letter']" :key="kind" :value="kind">{{ t(`education.document_kinds.${kind}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.designs.language')">
                        <select :id="id" v-model="form.locale" class="field-input">
                            <option v-for="locale in textLocales()" :key="locale" :value="locale">{{ t(`core.languages.${locale}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.designs.page')">
                        <select :id="id" v-model="form.size" class="field-input">
                            <option v-for="size in ['id_card', 'a4', 'a5']" :key="size" :value="size">{{ t(`education.page_sizes.${size}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.designs.orientation')">
                        <select :id="id" v-model="form.orientation" class="field-input">
                            <option v-for="value in ['portrait', 'landscape']" :key="value" :value="value">{{ t(`education.orientations.${value}`) }}</option>
                        </select>
                    </AppField>
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="creating = false">{{ t('education.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="education-new-design" :loading="saving" :disabled="!(form.name.en ?? '').trim()">{{ t('education.designs.new') }}</AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="adding" :title="t('education.designs.add_image')" :description="t('education.designs.images_text')" :icon="ImagePlus" @close="adding = false">
            <form id="education-asset" class="space-y-4" novalidate @submit.prevent="upload">
                <AppField v-slot="{ id }" :label="t('education.designs.image_file')" :error="assetErrors.file?.[0] ?? null">
                    <input :id="id" type="file" accept="image/jpeg,image/png,image/webp" class="field-input py-1.5" @change="pick" />
                </AppField>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('education.designs.image_kind')">
                        <select :id="id" v-model="asset.kind" class="field-input">
                            <option v-for="kind in ['logo', 'signature', 'background', 'image']" :key="kind" :value="kind">{{ t(`education.designs.image_kinds.${kind}`) }}</option>
                        </select>
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('education.designs.image_name')" :error="assetErrors.name?.[0] ?? null">
                        <input :id="id" v-model="asset.name" class="field-input" maxlength="80" />
                    </AppField>
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adding = false">{{ t('education.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="education-asset" :loading="saving" :disabled="!asset.file || !asset.name.trim()">{{ t('education.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
