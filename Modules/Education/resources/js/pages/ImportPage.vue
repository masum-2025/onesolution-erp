<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowLeft, CircleAlert, Download, FileSpreadsheet, FileUp, RotateCcw, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import { currentOrganization } from '@/lib/session';
import { confirmAction } from '@/lib/dialogs';
import { downloadText, readCsv, toCsv } from '@/lib/csv';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { importColumns, PRIVATE_COLUMNS, prepareImport } from '../lib';

/**
 * Students from a spreadsheet (education.admit): the place they study in,
 * a CSV file read here in the browser (never stored), every row checked by
 * the server first, then the good rows admitted once. Private columns only
 * for people allowed to see private details.
 */
const MAX_ROWS = 2000;
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const router = useRouter();

const sensitive = computed(() => setup.can('view_sensitive'));
const columns = computed(() => importColumns(setup.fields('student'), sensitive.value));
const campuses = computed(() => setup.data.value?.campuses ?? []);

// Where they study.
const place = reactive({ unit_id: '', program_id: '', session_id: '', level_id: '', section_id: '' });
const programs = computed(() => (setup.data.value?.programs ?? []).filter((program) => program.is_active));
const sessions = computed(() => (place.program_id ? setup.sessionsOf(place.program_id).filter((session) => session.status !== 'closed') : []));
const levels = computed(() => (place.program_id ? setup.levelsOf(place.program_id).filter((level) => level.is_active) : []));
const sections = ref([]);
watch(setup.data, (data) => {
    if (!data) return;
    if (!place.unit_id) place.unit_id = campuses.value.some((campus) => campus.id === org.id) ? org.id : campuses.value[0]?.id ?? '';
    if (!place.program_id && programs.value.length === 1) place.program_id = programs.value[0].id;
}, { immediate: true });
watch(() => place.program_id, () => {
    if (!sessions.value.some((session) => session.id === place.session_id)) place.session_id = sessions.value.find((session) => session.status === 'open')?.id ?? '';
    if (!levels.value.some((level) => level.id === place.level_id)) place.level_id = '';
});
watch(() => [place.session_id, place.level_id, place.unit_id], async ([sessionId, levelId, unitId]) => {
    place.section_id = '';
    sections.value = [];
    if (!sessionId || !levelId) return;
    try {
        sections.value = (await education.list('sections', { session_id: sessionId, level_id: levelId })).data.filter((section) => section.is_active && (!unitId || section.unit_id === unitId));
    } catch {
        sections.value = [];
    }
});
const placed = computed(() => Boolean(place.program_id && place.session_id && place.level_id));

// The file.
const file = ref(null);
const prepared = ref(null);
const tooMany = ref(false);
async function choose(event) {
    const chosen = event.target.files?.[0];
    event.target.value = '';
    if (!chosen) return;
    const rows = readCsv(await chosen.text());
    tooMany.value = rows.length > MAX_ROWS;
    file.value = chosen.name;
    prepared.value = prepareImport(rows.slice(0, MAX_ROWS), columns.value);
    result.value = null;
    made.value = null;
}

function sample() {
    const today = new Date().toISOString().slice(0, 10);
    const example = {
        name: 'Rahim Uddin', name_local: 'রহিম উদ্দিন', gender: setup.list('gender')[0]?.key ?? '', date_of_birth: '2014-02-01', birth_registration_no: '20142692123456789',
        admitted_on: today, section: sections.value[0]?.name ?? '', guardian_name: 'Abdul Karim', guardian_phone: '01711000000', guardian_relation: 'father',
    };
    const second = { ...example, name: 'Nusrat Jahan', name_local: 'নুসরাত জাহান', gender: setup.list('gender')[1]?.key ?? '', birth_registration_no: '', guardian_name: 'Rafiq Islam', guardian_phone: '01911000000', guardian_relation: 'mother' };
    downloadText('students-sample.csv', toCsv([columns.value, ...[example, second].map((row) => columns.value.map((key) => row[key] ?? ''))]));
}

// Check, then admit.
const result = ref(null);
const made = ref(null);
const busy = ref(false);
const show = ref('all');
const body = (commit) => ({ ...(place.unit_id ? { unit_id: place.unit_id } : {}), program_id: place.program_id, session_id: place.session_id, level_id: place.level_id, section_id: place.section_id || null, commit, rows: prepared.value.rows });

async function send(commit) {
    busy.value = true;
    try {
        const { data } = await education.importStudents(body(commit));
        if (commit) {
            made.value = data.made;
            toast.success(t('education.import.done', { count: data.made }));
        }
        result.value = data;
    } catch (error) {
        const first = Object.values(error.errors ?? {})[0]?.[0];
        toast.error(first ?? error.message);
    } finally {
        busy.value = false;
    }
}

async function commit() {
    const confirmed = await confirmAction({ title: t('education.import.commit_title', { count: result.value.ok }), message: t('education.import.commit_text'), confirmLabel: t('education.import.commit', { count: result.value.ok }) });
    if (confirmed) send(true);
}

function again() {
    file.value = prepared.value = result.value = made.value = null;
    tooMany.value = false;
}

const shown = computed(() => (result.value?.rows ?? []).filter((row) => show.value === 'all' || row.status === show.value));
const statusTone = { ok: 'ok', duplicate: 'warn', error: 'bad' };
const columnLabel = (key) => setup.fields('student').find((field) => field.key === key)?.label_text ?? key;
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'education-students' }" :icon="ArrowLeft" class="mb-3">{{ t('education.import.back') }}</AppButton>
        <PageHeader :title="t('education.import.title')" :description="t('education.import.text')" />

        <section v-if="setup.data.value && !setup.can('admit')" class="card"><EmptyState :icon="Users" :title="t('education.import.no_access')" /></section>
        <div v-else class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
            <div class="space-y-5">
                <!-- 1. Where they study -->
                <section class="card p-5">
                    <h2 class="mb-4 flex items-center gap-2 text-[14px] font-semibold text-fg"><span class="grid size-6 place-items-center rounded-full bg-brand text-[12px] text-brand-fg">1</span>{{ t('education.import.step_place') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField v-if="campuses.length > 1" v-slot="{ id }" :label="t('education.fields.unit')" class="sm:col-span-2">
                            <select :id="id" v-model="place.unit_id" class="field-input" :disabled="Boolean(result)">
                                <option v-for="item in campuses" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.import.program')">
                            <select :id="id" v-model="place.program_id" class="field-input" :disabled="Boolean(result)">
                                <option value="">—</option>
                                <option v-for="item in programs" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.import.session')">
                            <select :id="id" v-model="place.session_id" class="field-input" :disabled="!place.program_id || Boolean(result)">
                                <option value="">—</option>
                                <option v-for="item in sessions" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="setup.levelWord(place.program_id, t('education.import.level'))">
                            <select :id="id" v-model="place.level_id" class="field-input" :disabled="!place.program_id || Boolean(result)">
                                <option value="">—</option>
                                <option v-for="item in levels" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.import.section')" optional>
                            <select :id="id" v-model="place.section_id" class="field-input" :disabled="!sections.length || Boolean(result)">
                                <option value="">{{ t('education.import.section_from_file') }}</option>
                                <option v-for="item in sections" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </AppField>
                    </div>
                </section>

                <!-- 2. The file -->
                <section class="card p-5" :class="placed ? '' : 'opacity-60'">
                    <h2 class="mb-4 flex items-center gap-2 text-[14px] font-semibold text-fg"><span class="grid size-6 place-items-center rounded-full bg-brand text-[12px] text-brand-fg">2</span>{{ t('education.import.step_file') }}</h2>
                    <label v-if="!prepared" class="flex cursor-pointer flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-line-strong px-4 py-8 text-center transition hover:border-brand/60 hover:bg-brand-soft/40"
                        :class="placed ? '' : 'pointer-events-none'">
                        <FileUp class="size-8 text-muted" aria-hidden="true" />
                        <span class="text-[14px] font-medium text-fg">{{ t('education.import.choose') }}</span>
                        <span class="text-[12.5px] text-muted">{{ t('education.import.sample_hint') }}</span>
                        <input type="file" accept=".csv,text/csv" class="sr-only" :disabled="!placed" @change="choose" />
                    </label>
                    <div v-else class="space-y-2 text-[13px]">
                        <p class="flex items-center gap-2 font-medium text-fg"><FileSpreadsheet class="size-4 text-muted" aria-hidden="true" />{{ t('education.import.read_count', { count: prepared.rows.length, file }) }}</p>
                        <p v-if="tooMany" class="text-warn">{{ t('education.import.too_many', { max: MAX_ROWS }) }}</p>
                        <p v-if="prepared.unknown.length" class="text-muted">{{ t('education.import.unknown', { columns: prepared.unknown.join(', ') }) }}</p>
                        <p v-if="!prepared.hasName" class="flex items-center gap-1.5 text-bad" role="alert"><CircleAlert class="size-4" aria-hidden="true" />{{ t('education.import.no_name') }}</p>
                        <p v-else-if="!prepared.rows.length" class="text-bad" role="alert">{{ t('education.import.empty') }}</p>
                        <div class="flex flex-wrap gap-2 pt-1">
                            <AppButton v-if="!result" variant="primary" :loading="busy" :disabled="!prepared.hasName || !prepared.rows.length" @click="send(false)">{{ busy ? t('education.import.checking') : t('education.import.check') }}</AppButton>
                            <AppButton variant="ghost" :icon="RotateCcw" @click="again">{{ t('education.import.again') }}</AppButton>
                        </div>
                    </div>
                </section>

                <!-- 3. Check and admit -->
                <section v-if="result" class="card">
                    <header class="flex flex-col gap-3 border-b border-line px-5 py-4 sm:flex-row sm:items-center">
                        <div class="flex-1">
                            <h2 class="flex items-center gap-2 text-[14px] font-semibold text-fg"><span class="grid size-6 place-items-center rounded-full bg-brand text-[12px] text-brand-fg">3</span>{{ t('education.import.step_check') }}</h2>
                            <p class="mt-1 text-[13px] text-muted">{{ t('education.import.result', { ok: result.ok, duplicates: result.duplicates, errors: result.errors }) }}</p>
                        </div>
                        <AppButton v-if="made === null && result.ok" variant="primary" :loading="busy" @click="commit">{{ t('education.import.commit', { count: result.ok }) }}</AppButton>
                        <AppButton v-if="made !== null" variant="primary" :icon="Users" @click="router.push({ name: 'education-students' })">{{ t('education.import.open_list') }}</AppButton>
                    </header>
                    <div class="border-b border-line px-5 py-3">
                        <AppSegmented v-model="show" size="sm" :label="t('education.import.step_check')"
                            :options="['all', 'error', 'duplicate'].map((value) => ({ value, label: t(`education.import.show.${value}`) }))" />
                    </div>
                    <EmptyState v-if="!shown.length" :icon="Users" :title="t('education.import.no_problems')" compact />
                    <ul v-else class="max-h-[60vh] divide-y divide-line overflow-y-auto">
                        <li v-for="row in shown" :key="row.line" class="flex flex-wrap items-start gap-3 px-5 py-2.5 text-[13px]">
                            <span class="tabular w-20 shrink-0 text-muted">{{ t('education.import.line', { line: row.line }) }}</span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium text-fg">{{ prepared.rows[row.line - 2]?.name || '—' }}</span>
                                <span v-for="(message, column) in row.errors" :key="column" class="block text-[12.5px] text-bad">{{ columnLabel(column.replace(/^extra\./, '')) }}: {{ message }}</span>
                            </span>
                            <AppBadge :tone="statusTone[row.status]">{{ t(`education.import.status.${row.status}`) }}</AppBadge>
                        </li>
                    </ul>
                </section>
            </div>

            <!-- The columns a file may have. -->
            <aside class="card h-max p-5">
                <h2 class="text-[13.5px] font-semibold text-fg">{{ t('education.import.columns') }}</h2>
                <p class="mb-3 mt-1 text-[12.5px] text-muted">{{ t('education.import.sample_hint') }}</p>
                <ul class="mb-4 flex flex-wrap gap-1.5">
                    <li v-for="key in columns" :key="key" class="rounded-md bg-subtle px-2 py-1 font-mono text-[11.5px] text-fg-2" dir="ltr">
                        {{ key }}<span v-if="key === 'name'" class="font-sans text-bad"> · {{ t('education.import.required_column') }}</span><span v-else-if="PRIVATE_COLUMNS.includes(key)" class="font-sans text-faint"> · {{ t('education.import.private_column') }}</span>
                    </li>
                </ul>
                <AppButton block :icon="Download" @click="sample">{{ t('education.import.sample') }}</AppButton>
            </aside>
        </div>
    </div>
</template>
