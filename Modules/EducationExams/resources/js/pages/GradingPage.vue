<script setup>
import { computed, reactive, ref } from 'vue';
import { GraduationCap, Pencil, Plus, Trash2 } from 'lucide-vue-next';
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
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { bandsComplete, centiText, parseCenti, parsePercent, percentText } from '../lib';
import { marksShown, pointShown } from '../labels';

/**
 * Grading schemes: bands from a percentage up, each a letter, a grade
 * point and pass or fail. The country's published scales come in ready
 * (GPA 5, UGC 4, percentage); an institution adds or adjusts its own. The
 * one a new exam starts with is the rule education_exams.default_grading_scheme.
 */
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const schemes = computed(() => setup.data.value?.schemes ?? []);
const manage = computed(() => setup.can('manage'));
const range = (bands, index) => {
    const upper = index === 0 ? 10000 : bands[index - 1].min_percent_bp - 1;
    return `${marksShown(bands[index].min_percent_bp)}–${marksShown(Math.max(upper, bands[index].min_percent_bp))}%`;
};

const editing = ref(null);
const form = reactive({ code: '', name: textsFor({}), max_point: '', is_active: true, bands: [] });
const errors = ref({});
const saving = ref(false);
function open(scheme = null) {
    editing.value = scheme ?? {};
    Object.assign(form, {
        code: scheme?.code ?? '',
        name: textsFor(scheme?.name),
        max_point: scheme ? centiText(scheme.max_point_centi) : '5',
        is_active: scheme?.is_active ?? true,
        bands: (scheme?.bands ?? [{ min_percent_bp: 3300, letter: 'P', point_centi: 100, is_pass: true }, { min_percent_bp: 0, letter: 'F', point_centi: 0, is_pass: false }])
            .map((band) => ({ min: percentText(band.min_percent_bp), letter: band.letter, point: centiText(band.point_centi), is_pass: band.is_pass })),
    });
    errors.value = {};
}
const bandsPayload = computed(() => form.bands.map((band) => ({ min_percent_bp: parsePercent(band.min), letter: band.letter.trim(), point_centi: band.point === '' ? 0 : parseCenti(band.point), is_pass: band.is_pass })));
const bandsOk = computed(() => bandsComplete(bandsPayload.value) && bandsPayload.value.every((band) => band.letter && band.point_centi !== null));
const ready = computed(() => (editing.value?.id || /^[a-z0-9_]{2,30}$/.test(form.code)) && Object.keys(cleanTexts(form.name)).length && parseCenti(form.max_point) !== null && bandsOk.value);
const error = (field) => errors.value[field]?.[0] ?? null;

async function save() {
    saving.value = true;
    errors.value = {};
    const bands = [...bandsPayload.value].sort((a, b) => b.min_percent_bp - a.min_percent_bp);
    const body = { name: cleanTexts(form.name), max_point_centi: parseCenti(form.max_point), is_active: form.is_active, bands };
    try {
        if (editing.value?.id) await api.updateScheme(editing.value.id, { ...body, base_version: editing.value.version });
        else await api.createScheme({ ...body, code: form.code });
        toast.success(t('education_exams.grading.saved'));
        editing.value = null;
        setup.reload();
    } catch (caught) {
        errors.value = caught.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(caught.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.grading.title')" :description="t('education_exams.grading.text')">
            <template v-if="manage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="open()">{{ t('education_exams.grading.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <div v-else-if="!schemes.length" class="card"><EmptyState :icon="GraduationCap" :title="t('education_exams.grading.empty')" /></div>
        <div v-else class="grid grid-cols-1 gap-5 lg:grid-cols-2 xl:grid-cols-3">
            <section v-for="scheme in schemes" :key="scheme.id" class="card h-fit min-w-0">
                <header class="flex items-start gap-2 border-b border-line px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-semibold text-fg">{{ textIn(scheme.name) }}</span>
                        <span class="block font-mono text-[11.5px] text-muted" dir="ltr">{{ scheme.code }}</span>
                    </span>
                    <AppBadge v-if="scheme.code === setup.rules.value.default_grading_scheme" tone="brand" :title="t('education_exams.from_rule')">{{ t('education_exams.grading.default') }}</AppBadge>
                    <AppBadge v-if="!scheme.is_active" tone="outline">{{ t('education_exams.grading.inactive') }}</AppBadge>
                    <AppButton v-if="manage" size="icon-sm" variant="ghost" :icon="Pencil" :aria-label="t('education_exams.grading.edit')" @click="open(scheme)" />
                </header>
                <table class="w-full text-[13px]">
                    <thead class="text-[11.5px] text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-2 text-start font-medium">{{ t('education_exams.grading.range') }}</th>
                            <th scope="col" class="px-2 py-2 text-center font-medium">{{ t('education_exams.grading.letter') }}</th>
                            <th scope="col" class="px-5 py-2 text-end font-medium">{{ t('education_exams.grading.point') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="(band, index) in scheme.bands" :key="band.min_percent_bp">
                            <td class="tabular px-5 py-1.5 text-muted">{{ range(scheme.bands, index) }}</td>
                            <td class="px-2 py-1.5 text-center font-semibold" :class="band.is_pass ? 'text-fg' : 'text-bad'">
                                {{ band.letter }}<span v-if="!band.is_pass" class="ms-1 text-[11px] font-normal">({{ t('education_exams.grading.fail') }})</span>
                            </td>
                            <td class="tabular px-5 py-1.5 text-end">{{ pointShown(band.point_centi) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <AppDialog :open="editing !== null" :title="t(editing?.id ? 'education_exams.grading.edit' : 'education_exams.grading.new')" :description="t('education_exams.grading.form_text')" :icon="GraduationCap" size="lg" @close="editing = null">
            <form id="scheme-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
                <TranslatedFields v-model="form.name" :label="t('education_exams.grading.name')" :errors="errors" class="sm:col-span-2" required />
                <AppField v-slot="{ id, describedby }" :label="t('education_exams.grading.code')" :hint="t('education_exams.grading.code_hint')" :error="error('code')">
                    <input :id="id" v-model="form.code" maxlength="30" class="field-input font-mono" dir="ltr" :disabled="Boolean(editing?.id)" :aria-describedby="describedby" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_exams.grading.max_point')" :error="error('max_point_centi')">
                    <input :id="id" v-model="form.max_point" inputmode="decimal" class="field-input tabular" dir="ltr" />
                </AppField>
                <div class="flex items-center justify-between gap-4 rounded-lg bg-subtle/60 px-4 py-3 sm:col-span-2">
                    <span class="text-[13.5px] text-fg">{{ t('education_exams.grading.active') }}</span>
                    <AppSwitch v-model="form.is_active" :label="t('education_exams.grading.active')" />
                </div>
                <fieldset class="space-y-2 sm:col-span-2">
                    <legend class="mb-1 text-[13px] font-medium text-fg">{{ t('education_exams.grading.bands') }}</legend>
                    <div class="hidden grid-cols-[1fr_1fr_1fr_auto_auto] gap-2 text-[11.5px] text-muted sm:grid">
                        <span>{{ t('education_exams.grading.from_percent') }}</span><span>{{ t('education_exams.grading.letter') }}</span><span>{{ t('education_exams.grading.point') }}</span><span>{{ t('education_exams.grading.passes') }}</span><span />
                    </div>
                    <div v-for="(band, index) in form.bands" :key="index" class="grid grid-cols-[1fr_1fr_1fr_auto_auto] items-center gap-2">
                        <input v-model="band.min" inputmode="decimal" class="field-input tabular" dir="ltr" :aria-label="t('education_exams.grading.from_percent')" :class="band.min !== '' && parsePercent(band.min) === null ? 'border-bad' : ''" />
                        <input v-model="band.letter" maxlength="10" class="field-input" dir="ltr" :aria-label="t('education_exams.grading.letter')" />
                        <input v-model="band.point" inputmode="decimal" class="field-input tabular" dir="ltr" :aria-label="t('education_exams.grading.point')" />
                        <AppSwitch v-model="band.is_pass" :label="t('education_exams.grading.passes')" />
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :disabled="form.bands.length <= 1" :aria-label="t('education_exams.grading.remove_band')" @click="form.bands.splice(index, 1)" />
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-2 pt-1">
                        <AppButton size="sm" :icon="Plus" :disabled="form.bands.length >= 20" @click="form.bands.push({ min: '', letter: '', point: '', is_pass: true })">{{ t('education_exams.grading.add_band') }}</AppButton>
                        <p class="text-[12.5px]" :class="bandsOk ? 'text-muted' : 'text-bad'" role="status">{{ t(bandsOk ? 'education_exams.grading.bands_ok' : 'education_exams.grading.bands_rule') }}</p>
                    </div>
                    <p v-if="error('bands')" class="text-[12.5px] text-bad">{{ error('bands') }}</p>
                </fieldset>
                <p v-if="editing?.id" class="rounded-lg bg-warn-soft px-4 py-3 text-[12.5px] text-fg sm:col-span-2">{{ t('education_exams.grading.change_note') }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('education_exams.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="scheme-form" :loading="saving" :disabled="!ready">{{ t('education_exams.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
