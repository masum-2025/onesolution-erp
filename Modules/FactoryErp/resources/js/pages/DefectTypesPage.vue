<script setup>
import { computed, reactive, ref } from 'vue';
import { Pencil, Plus, ShieldAlert } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';

/**
 * The company's kinds of defect QC counts: a short code and a name in each
 * language. A kind no longer used is switched off (past counts keep it).
 * Set up with factory_erp.manage; others only read.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const list = useResource(() => api.defectTypes());
const types = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => setup.can('manage'));

const editing = ref(null);
const form = reactive({ code: '', name: {}, is_active: true });
const errors = ref({});
const busy = ref(false);
function open(type) {
    editing.value = type ?? {};
    Object.assign(form, { code: type?.code ?? '', name: { ...(type?.names ?? {}) }, is_active: type?.is_active ?? true });
    errors.value = {};
}
const ready = computed(() => form.code.trim() && (form.name.en ?? '').trim().length >= 2);
async function save() {
    busy.value = true;
    errors.value = {};
    const name = Object.fromEntries(Object.entries(form.name).map(([locale, text]) => [locale, (text ?? '').trim() || null]));
    try {
        if (editing.value?.id) await api.updateDefectType(editing.value.id, { code: form.code.trim(), name, is_active: form.is_active, base_version: editing.value.version });
        else await api.createDefectType({ code: form.code.trim(), name });
        toast.success(t('factory_erp.defects.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('factory_erp.conflict'));
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('factory_erp.defects.title')" :description="t('factory_erp.defects.text')">
            <template v-if="canManage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="open(null)">{{ t('factory_erp.defects.new') }}</AppButton>
            </template>
        </PageHeader>
        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!types.length" class="card"><EmptyState :icon="ShieldAlert" :title="t('factory_erp.defects.empty_title')" /></section>
        <ul v-else class="card divide-y divide-line">
            <li v-for="type in types" :key="type.id" class="flex items-center gap-3 px-5 py-3">
                <span class="grid h-9 min-w-16 place-items-center rounded-xl bg-subtle px-2 font-mono text-[12px] font-bold text-fg" dir="ltr">{{ type.code }}</span>
                <p class="min-w-0 flex-1 truncate text-[14px] font-medium text-fg">{{ type.name }}</p>
                <AppBadge :tone="type.is_active ? 'ok' : 'neutral'" dot>{{ type.is_active ? t('factory_erp.active') : t('factory_erp.off') }}</AppBadge>
                <AppButton v-if="canManage" size="sm" variant="ghost" :icon="Pencil" :aria-label="t('factory_erp.defects.edit_of', { code: type.code })" @click="open(type)" />
            </li>
        </ul>

        <AppDialog :open="editing !== null" :icon="ShieldAlert" :title="editing?.id ? t('factory_erp.defects.edit') : t('factory_erp.defects.new')" :description="t('factory_erp.defects.dialog_text')" @close="editing = null">
            <form class="grid gap-4" @submit.prevent="ready && save()">
                <AppField v-slot="{ id }" :label="t('factory_erp.defects.code')" :error="errors.code?.[0] ?? null">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase sm:w-48" dir="ltr" maxlength="20" placeholder="SKIP" />
                </AppField>
                <TranslatedFields v-model="form.name" :label="t('factory_erp.defects.name')" :errors="errors" required :maxlength="80" />
                <div v-if="editing?.id">
                    <AppSwitch v-model="form.is_active" :label="t('factory_erp.lines.in_use')" show-label />
                    <p class="mt-1 text-[12px] text-muted">{{ t('factory_erp.defects.in_use_hint') }}</p>
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
