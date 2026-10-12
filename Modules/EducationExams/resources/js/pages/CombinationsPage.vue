<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Layers, Plus } from 'lucide-vue-next';
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
import { currentOrganization } from '@/lib/session';
import { cleanTexts, textIn, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import { useExamsSetup } from '../setup';
import { examTone, readWeights } from '../lib';
import { marksShown } from '../labels';

/**
 * Final results that weigh several exams of a session for a class (term
 * 30 % + final 70 %). Made by people who manage exams; worked out from the
 * exams' results; published (.publish) once every exam is.
 */
const api = examsApi(currentOrganization().id);
const setup = useExamsSetup();
const router = useRouter();

const sessionId = ref('');
watch(() => setup.data.value, (data) => {
    if (data && !sessionId.value) sessionId.value = data.sessions.find((session) => session.status === 'open')?.id ?? data.sessions[0]?.id ?? '';
}, { immediate: true });
const list = useResource(() => (sessionId.value ? api.combinations({ session_id: sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
const exams = useResource(() => (sessionId.value ? api.exams({ session_id: sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
watch(sessionId, (id) => {
    if (!id) return;
    list.reload();
    exams.reload();
}, { immediate: true });
const rows = computed(() => list.data.value?.data ?? []);
const examName = (id) => textIn(exams.data.value?.data.find((exam) => exam.id === id)?.name) || '—';
const weightsLine = (row) => row.exams.map((item) => `${examName(item.exam_id)} ${marksShown(item.weight_bp)}%`).join(' + ');

// Making one.
const making = ref(false);
const form = reactive({ name: textsFor({}), level_id: '', grading_scheme_id: '', picked: {} });
const errors = ref({});
const saving = ref(false);
function open() {
    const byRule = setup.data.value?.schemes.find((scheme) => scheme.code === setup.rules.value.default_grading_scheme)?.id ?? '';
    Object.assign(form, { name: textsFor({}), level_id: setup.data.value?.levels[0]?.id ?? '', grading_scheme_id: byRule, picked: {} });
    errors.value = {};
    making.value = true;
}
const chosen = computed(() => Object.entries(form.picked).filter(([, item]) => item.on));
const weights = computed(() => readWeights(chosen.value.map(([, item]) => item.weight)));
const ready = computed(() => Object.keys(cleanTexts(form.name)).length && form.level_id && form.grading_scheme_id && weights.value.valid && weights.value.total === 10000);
function pick(exam) {
    form.picked = { ...form.picked, [exam.id]: { on: !form.picked[exam.id]?.on, weight: form.picked[exam.id]?.weight ?? '' } };
}
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.createCombination({
            name: cleanTexts(form.name), session_id: sessionId.value, level_id: form.level_id, grading_scheme_id: form.grading_scheme_id,
            exams: chosen.value.map(([examId], index) => ({ exam_id: examId, weight_bp: weights.value.weights[index] })),
        });
        toast.success(t('education_exams.combinations.made'));
        making.value = false;
        router.push({ name: 'exams-combination', params: { id: data.id } });
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
        <PageHeader :title="t('education_exams.combinations.title')" :description="t('education_exams.combinations.text')">
            <template v-if="setup.can('manage')" #actions>
                <AppButton variant="primary" :icon="Plus" :disabled="!sessionId || !(exams.data.value?.data.length)" @click="open">{{ t('education_exams.combinations.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="4" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <label class="mb-4 block sm:w-64">
                <span class="sr-only">{{ t('education_exams.exam.session') }}</span>
                <select v-model="sessionId" class="field-input">
                    <option v-for="session in setup.data.value.sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </label>
            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="Layers" compact :title="t('education_exams.combinations.empty')" :text="setup.can('manage') ? t('education_exams.combinations.empty_text') : ''" />
                <ul v-else class="divide-y divide-line">
                    <li v-for="row in rows" :key="row.id">
                        <RouterLink :to="{ name: 'exams-combination', params: { id: row.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ textIn(row.name) }}</span>
                                <span class="block text-[12.5px] text-muted">{{ setup.levelName(row.level_id) }} · {{ weightsLine(row) }}</span>
                            </span>
                            <AppBadge :tone="row.status === 'published' ? 'ok' : 'neutral'" dot>{{ t(`education_exams.combinations.statuses.${row.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <AppDialog :open="making" :title="t('education_exams.combinations.new')" :description="t('education_exams.combinations.form_text')" :icon="Layers" size="lg" @close="making = false">
            <form id="combination-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="ready && save()">
                <TranslatedFields v-model="form.name" :label="t('education_exams.combinations.name')" :errors="errors" class="sm:col-span-2" required />
                <AppField v-slot="{ id }" :label="t('education_exams.paper.level')" :error="errors.level_id?.[0] ?? null">
                    <select :id="id" v-model="form.level_id" class="field-input">
                        <option v-for="level in setup.data.value?.levels ?? []" :key="level.id" :value="level.id">{{ setup.levelName(level.id) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_exams.exam.scheme')" :error="errors.grading_scheme_id?.[0] ?? null">
                    <select :id="id" v-model="form.grading_scheme_id" class="field-input">
                        <option v-for="scheme in (setup.data.value?.schemes ?? []).filter((item) => item.is_active)" :key="scheme.id" :value="scheme.id">{{ textIn(scheme.name) }}</option>
                    </select>
                </AppField>
                <fieldset class="space-y-2 sm:col-span-2">
                    <legend class="mb-1 text-[13px] font-medium text-fg">{{ t('education_exams.combinations.exams') }}</legend>
                    <div v-for="exam in exams.data.value?.data ?? []" :key="exam.id" class="flex flex-wrap items-center gap-3 rounded-lg border border-line px-3 py-2">
                        <label class="flex min-w-0 flex-1 items-center gap-2.5 text-[13.5px]">
                            <input type="checkbox" class="size-4 accent-[var(--color-brand)]" :checked="form.picked[exam.id]?.on" @change="pick(exam)" />
                            <span class="truncate">{{ textIn(exam.name) }}</span>
                            <AppBadge :tone="examTone[exam.status]">{{ t(`education_exams.exam_statuses.${exam.status}`) }}</AppBadge>
                        </label>
                        <label v-if="form.picked[exam.id]?.on" class="flex items-center gap-1.5 text-[13px] text-muted">
                            <input v-model="form.picked[exam.id].weight" inputmode="decimal" class="field-input tabular h-9 w-20 text-center" dir="ltr" :aria-label="t('education_exams.combinations.weight_of', { exam: textIn(exam.name) })" />%
                        </label>
                    </div>
                    <p class="tabular text-[12.5px]" :class="weights.total === 10000 && weights.valid ? 'text-muted' : 'font-medium text-bad'" role="status">
                        {{ t('education_exams.combinations.total', { total: marksShown(weights.total) }) }}
                    </p>
                    <p v-if="errors.exams" class="text-[12.5px] text-bad">{{ errors.exams[0] }}</p>
                </fieldset>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="making = false">{{ t('education_exams.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="combination-form" :loading="saving" :disabled="!ready">{{ t('education_exams.combinations.make') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
