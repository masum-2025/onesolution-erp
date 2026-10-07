<script setup>
import { computed, reactive, ref } from 'vue';
import { GitBranch, Plus, Star } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { useCrmSetup } from '../setup';

/**
 * The company's pipelines and their stages: names in each language, the
 * chance of winning at each stage (it weights the deal board), order, on or
 * off. Every pipeline keeps its won and lost stages; one is the default.
 */
const crm = crmApi(currentOrganization().id);
const setup = useCrmSetup();
const pipelines = computed(() => setup.data.value?.pipelines ?? []);

const dialog = ref(null);
const form = reactive({ name: { en: '', bn: '' }, percent: '', sort_order: '', is_active: true, is_default: false });
const errors = ref({});
const saving = ref(false);
function open(kind, pipeline, stage = null) {
    dialog.value = { kind, pipeline, stage };
    const source = stage ?? (kind === 'pipeline' ? pipeline : null);
    Object.assign(form, {
        name: { en: source?.names?.en ?? '', bn: source?.names?.bn ?? '' }, percent: stage ? String(stage.probability_bp / 100) : '', sort_order: stage ? String(stage.sort_order) : '',
        is_active: source?.is_active ?? true, is_default: pipeline?.is_default ?? false,
    });
    errors.value = {};
}
async function save() {
    const { kind, pipeline, stage } = dialog.value;
    const name = Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim()));
    saving.value = true;
    errors.value = {};
    try {
        if (kind === 'pipeline') {
            if (pipeline) await crm.updatePipeline(pipeline.id, { name, is_active: form.is_active, ...(form.is_default ? { is_default: true } : {}), base_version: pipeline.version });
            else await crm.createPipeline({ name, is_default: form.is_default });
        } else {
            const body = { name, probability_bp: Math.round(Number(form.percent || 0) * 100), ...(form.sort_order !== '' ? { sort_order: Number(form.sort_order) } : {}) };
            if (stage) await crm.updateStage(pipeline.id, stage.id, { ...body, is_active: form.is_active, base_version: stage.version });
            else await crm.createStage(pipeline.id, body);
        }
        toast.success(t('crm.pipelines.saved'));
        dialog.value = null;
        setup.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('crm.pipelines.title')" :description="t('crm.pipelines.text')">
            <template #actions>
                <AppButton v-if="can('crm.manage')" variant="primary" :icon="Plus" @click="open('pipeline', null)">{{ t('crm.pipelines.add') }}</AppButton>
            </template>
        </PageHeader>
        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="6" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <div v-else class="grid gap-5">
            <section v-for="pipeline in pipelines" :key="pipeline.id" class="card">
                <header class="flex flex-wrap items-center justify-between gap-2 border-b border-line px-5 py-3">
                    <h2 class="flex items-center gap-2 text-[15px] font-semibold">
                        <GitBranch class="size-4" aria-hidden="true" />{{ pipeline.name }}
                        <AppBadge v-if="pipeline.is_default" tone="brand"><Star class="me-1 inline size-3" aria-hidden="true" />{{ t('crm.pipelines.default') }}</AppBadge>
                        <AppBadge v-if="!pipeline.is_active" tone="neutral">{{ t('crm.common.off') }}</AppBadge>
                    </h2>
                    <span v-if="can('crm.manage')" class="flex gap-1">
                        <AppButton size="sm" variant="ghost" @click="open('pipeline', pipeline)">{{ t('crm.common.edit') }}</AppButton>
                        <AppButton size="sm" variant="secondary" :icon="Plus" @click="open('stage', pipeline)">{{ t('crm.pipelines.add_stage') }}</AppButton>
                    </span>
                </header>
                <ol class="divide-y divide-line">
                    <li v-for="stage in pipeline.stages" :key="stage.id">
                        <button type="button" class="flex w-full items-center gap-3 px-5 py-2.5 text-start text-[13.5px] hover:bg-surface-2" :disabled="!can('crm.manage')" @click="open('stage', pipeline, stage)">
                            <span class="min-w-0 flex-1 font-medium" :class="[stage.is_active ? '' : 'text-muted line-through', { won: 'text-ok', lost: 'text-bad' }[stage.outcome] ?? '']">{{ stage.name }}</span>
                            <AppBadge v-if="stage.outcome !== 'open'" :tone="stage.outcome === 'won' ? 'ok' : 'bad'">{{ t(`crm.outcomes.${stage.outcome}`) }}</AppBadge>
                            <span class="tabular w-28 text-end text-[12.5px] text-muted">{{ t('crm.deals.chance', { percent: formatNumber(stage.probability_bp / 100) }) }}</span>
                        </button>
                    </li>
                </ol>
            </section>
        </div>

        <AppDialog :open="!!dialog" :title="t(dialog?.kind === 'pipeline' ? (dialog?.pipeline ? 'crm.pipelines.edit' : 'crm.pipelines.add') : (dialog?.stage ? 'crm.pipelines.edit_stage' : 'crm.pipelines.add_stage'))" :icon="GitBranch" @close="dialog = null">
            <form id="crm-pipeline" class="grid gap-4" novalidate @submit.prevent="save">
                <TranslatedFields v-model="form.name" :label="t('crm.common.name')" :errors="errors" required :maxlength="80" />
                <template v-if="dialog?.kind === 'stage'">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField v-slot="{ id }" :label="t('crm.pipelines.chance')" :hint="t('crm.pipelines.chance_hint')" :error="errors.probability_bp?.[0]">
                            <input :id="id" v-model="form.percent" inputmode="decimal" class="field-input tabular text-end" dir="ltr" placeholder="50" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('crm.fields.order')" :error="errors.sort_order?.[0]" optional>
                            <input :id="id" v-model="form.sort_order" inputmode="numeric" class="field-input tabular" dir="ltr" />
                        </AppField>
                    </div>
                </template>
                <AppSwitch v-if="dialog?.kind === 'pipeline'" v-model="form.is_default" :label="t('crm.pipelines.make_default')" show-label />
                <AppSwitch v-if="dialog?.stage || (dialog?.kind === 'pipeline' && dialog?.pipeline)" v-model="form.is_active" :label="t('crm.common.active')" show-label />
                <p v-if="errors.is_active" class="text-[12px] text-bad">{{ errors.is_active[0] }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="dialog = null">{{ t('crm.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="crm-pipeline" :loading="saving" :disabled="!form.name.en?.trim()">{{ t('crm.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
