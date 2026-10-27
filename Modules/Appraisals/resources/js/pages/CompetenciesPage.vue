<script setup>
import { computed, reactive, ref } from 'vue';
import { ListPlus, PenLine, Plus, Sparkles } from 'lucide-vue-next';
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
import { appraisalsApi } from '../api';

/**
 * The competencies people are reviewed on (each cycle takes those in use
 * when it opens). "Add the usual ones" adds the rule's list. Switched off,
 * never removed: past reviews keep them.
 */
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const list = useResource(() => calls.competencies());
const items = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => !!list.data.value?.meta?.can_manage);

const adding = ref(false);
async function addDefaults() {
    adding.value = true;
    try {
        toast.success((await calls.addDefaults()).message);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        adding.value = false;
    }
}

const editing = ref(null);
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name: { en: '', bn: '' }, is_active: true });
function edit(item = null) {
    Object.assign(form, item ? { code: item.code, name: { en: item.names.en ?? '', bn: item.names.bn ?? '' }, is_active: item.is_active } : { code: '', name: { en: '', bn: '' }, is_active: true });
    errors.value = {};
    editing.value = item ?? 'new';
}
async function save() {
    const body = { code: form.code.trim(), name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())) };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await calls.createCompetency(body);
        else await calls.updateCompetency(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('appraisals.competencies.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.code === 'version_conflict') {
            editing.value = null;
            list.reload();
        }
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('appraisals.competencies.title')" :description="t('appraisals.competencies.text')">
            <template v-if="canManage" #actions>
                <AppButton :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('appraisals.competencies.add_defaults') }}</AppButton>
                <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('appraisals.competencies.add') }}</AppButton>
            </template>
        </PageHeader>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="Sparkles" :title="t('appraisals.competencies.empty')" :text="t('appraisals.competencies.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="ListPlus" :loading="adding" @click="addDefaults">{{ t('appraisals.competencies.add_defaults') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1 text-[14px] font-medium">{{ item.name }} <span class="font-mono text-[12px] font-normal text-muted" dir="ltr">{{ item.code }}</span></span>
                    <AppBadge v-if="!item.is_active" tone="neutral">{{ t('appraisals.competencies.off') }}</AppBadge>
                    <AppButton v-if="canManage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('appraisals.competencies.edit')" @click="edit(item)" />
                </li>
            </ul>
        </section>
        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('appraisals.competencies.add') : t('appraisals.competencies.edit')" @close="editing = null">
            <form id="appraisals-competency" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('appraisals.competencies.code')" :error="errors.code?.[0]">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" />
                </AppField>
                <div class="sm:col-span-2">
                    <TranslatedFields v-model="form.name" :label="t('appraisals.competencies.name')" :errors="errors" required :maxlength="80" />
                </div>
                <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('appraisals.competencies.active')" show-label class="sm:col-span-3" />
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('appraisals.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="appraisals-competency" :loading="saving" :disabled="!form.code.trim() || !form.name.en?.trim()">{{ t('appraisals.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
