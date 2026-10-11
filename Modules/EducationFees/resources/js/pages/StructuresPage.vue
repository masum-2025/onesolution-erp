<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Archive, CircleCheck, Layers, Pencil, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';
import { amountText, parseAmount } from '../lib';

/**
 * Fee structures of a session: amounts per head, for everyone or narrowed
 * to a campus or a class (the most specific one fits each student). A
 * monthly head may be billed in some months only. Drafted, made active,
 * archived; bills keep their own amounts.
 */
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const sessionId = ref('');
watch(() => setup.data.value, (data) => {
    if (data && !sessionId.value) sessionId.value = data.sessions.find((session) => session.status === 'open')?.id ?? data.sessions[0]?.id ?? '';
}, { immediate: true });
const list = useResource(() => (sessionId.value ? api.structures({ session_id: sessionId.value }) : Promise.resolve({ data: [] })), { immediate: false });
watch(sessionId, () => list.reload(), { immediate: true });
const rows = computed(() => list.data.value?.data ?? []);
const tone = { draft: 'warn', active: 'ok', archived: 'outline' };
const levelName = (id) => {
    const level = setup.data.value?.levels.find((item) => item.id === id);
    return level ? `${textIn(level.program?.name)} · ${textIn(level.name)}` : '';
};
const campusName = (id) => setup.data.value?.campuses.find((campus) => campus.id === id)?.name ?? '';
const narrowing = (row) => [row.unit_id && campusName(row.unit_id), row.level_id && levelName(row.level_id)].filter(Boolean).join(' · ') || t('education_fees.structures.for_all');
const MONTHS = Array.from({ length: 12 }, (_, index) => index + 1);
const monthShort = (month) => formatDate(`2026-${String(month).padStart(2, '0')}-01T00:00:00Z`, { month: 'short', timeZone: 'UTC' });

// Editing.
const editing = ref(null);
const form = reactive({ name: '', unit_id: '', level_id: '', lines: {} });
const errors = ref({});
const saving = ref(false);
const heads = computed(() => (setup.data.value?.heads ?? []).filter((head) => head.is_active));
function open(row = null) {
    editing.value = row ?? {};
    const lines = {};
    for (const head of heads.value) {
        const line = row?.lines?.find((item) => item.head_id === head.id);
        lines[head.id] = { amount: line ? amountText(line.amount_minor, setup.currency.value) : '', months: line?.months ?? [] };
    }
    Object.assign(form, { name: row?.name ?? '', unit_id: row?.unit_id ?? '', level_id: row?.level_id ?? '', lines });
    errors.value = {};
}
function toggleMonth(headId, month) {
    const months = form.lines[headId].months;
    form.lines[headId].months = months.includes(month) ? months.filter((item) => item !== month) : [...months, month].sort((a, b) => a - b);
}
const linesPayload = computed(() => Object.entries(form.lines)
    .filter(([, line]) => line.amount !== '')
    .map(([headId, line]) => ({ head_id: headId, amount_minor: parseAmount(line.amount, setup.currency.value), months: line.months.length ? line.months : null })));
const invalid = computed(() => linesPayload.value.some((line) => !line.amount_minor));
async function save() {
    saving.value = true;
    errors.value = {};
    const body = { name: form.name.trim(), session_id: sessionId.value, unit_id: form.unit_id || null, level_id: form.level_id || null, lines: linesPayload.value };
    try {
        if (editing.value?.id) await api.updateStructure(editing.value.id, { ...body, base_version: editing.value.version });
        else await api.createStructure(body);
        toast.success(t('education_fees.structures.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
const busy = ref(null);
async function setStatus(row, status) {
    busy.value = row.id;
    try {
        await api.structureStatus(row.id, status, row.version);
        toast.success(t(status === 'active' ? 'education_fees.structures.activated' : 'education_fees.structures.archived'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_fees.structures.title')" :description="t('education_fees.structures.text')">
            <template v-if="setup.can('configure')" #actions>
                <AppButton variant="primary" :icon="Plus" :disabled="!sessionId || !heads.length" @click="open()">{{ t('education_fees.structures.add') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="5" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <label class="mb-4 block sm:w-64">
                <span class="sr-only">{{ t('education_fees.structures.session') }}</span>
                <select v-model="sessionId" class="field-input">
                    <option v-for="session in setup.data.value.sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
                </select>
            </label>
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
            <section v-else-if="!rows.length" class="card"><EmptyState :icon="Layers" :title="t('education_fees.structures.empty')" compact /></section>
            <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <article v-for="row in rows" :key="row.id" class="card flex flex-col p-5">
                    <header class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <h2 class="truncate text-[15px] font-semibold text-fg">{{ row.name }}</h2>
                            <p class="text-[12.5px] text-muted">{{ narrowing(row) }}</p>
                        </div>
                        <AppBadge :tone="tone[row.status]" dot>{{ t(`education_fees.structure_statuses.${row.status}`) }}</AppBadge>
                    </header>
                    <ul class="mt-3 flex-1 divide-y divide-line text-[13px]">
                        <li v-for="line in row.lines" :key="line.head_id" class="flex items-center justify-between gap-3 py-1.5">
                            <span class="min-w-0 truncate">{{ setup.headName(line.head_id) }}<span v-if="line.months?.length" class="text-muted"> · {{ line.months.map(monthShort).join(', ') }}</span></span>
                            <span class="tabular font-medium">{{ setup.money(line.amount_minor) }}</span>
                        </li>
                    </ul>
                    <footer v-if="setup.can('configure') && row.status !== 'archived'" class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                        <AppButton size="sm" :icon="Pencil" @click="open(row)">{{ t('education_fees.structures.edit') }}</AppButton>
                        <AppButton v-if="row.status === 'draft'" size="sm" variant="primary" :icon="CircleCheck" :loading="busy === row.id" @click="setStatus(row, 'active')">{{ t('education_fees.structures.activate') }}</AppButton>
                        <AppButton size="sm" variant="ghost" :icon="Archive" :loading="busy === row.id" @click="setStatus(row, 'archived')">{{ t('education_fees.structures.archive') }}</AppButton>
                    </footer>
                </article>
            </div>
        </template>

        <AppDialog :open="editing !== null" :title="editing?.id ? t('education_fees.structures.edit') : t('education_fees.structures.add')" :icon="Layers" size="lg" @close="editing = null">
            <form id="fee-structure" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="!invalid && save()">
                <AppField v-slot="{ id }" :label="t('education_fees.structures.name')" class="sm:col-span-3" :error="errors.name?.[0] ?? null">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_fees.structures.campus')" class="sm:col-span-1">
                    <select :id="id" v-model="form.unit_id" class="field-input">
                        <option value="">{{ t('education_fees.structures.every_campus') }}</option>
                        <option v-for="campus in setup.data.value?.campuses ?? []" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('education_fees.structures.level')" class="sm:col-span-2">
                    <select :id="id" v-model="form.level_id" class="field-input">
                        <option value="">{{ t('education_fees.structures.every_level') }}</option>
                        <option v-for="level in setup.data.value?.levels ?? []" :key="level.id" :value="level.id">{{ levelName(level.id) }}</option>
                    </select>
                </AppField>
                <fieldset class="sm:col-span-3">
                    <legend class="mb-2 text-[13px] font-medium text-fg">{{ t('education_fees.structures.lines') }}</legend>
                    <p v-if="!heads.length" class="text-[13px] text-muted">{{ t('education_fees.structures.no_heads') }}</p>
                    <ul class="divide-y divide-line rounded-xl border border-line">
                        <li v-for="head in heads" :key="head.id" class="px-3 py-2.5">
                            <div class="flex flex-wrap items-center gap-3">
                                <span class="min-w-0 flex-1 text-[13.5px]"><span class="font-mono text-muted" dir="ltr">{{ head.code }}</span> · {{ textIn(head.name) }} <span class="text-[12px] text-faint">({{ t(`education_fees.frequencies.${head.frequency}`) }})</span></span>
                                <input v-model="form.lines[head.id].amount" inputmode="decimal" class="field-input tabular w-36" :aria-label="textIn(head.name)"
                                    :class="form.lines[head.id].amount && !parseAmount(form.lines[head.id].amount, setup.currency.value) ? 'border-bad' : ''" />
                            </div>
                            <div v-if="head.frequency === 'monthly' && form.lines[head.id].amount" class="mt-2 flex flex-wrap items-center gap-1">
                                <span class="me-1 text-[12px] text-muted">{{ form.lines[head.id].months.length ? t('education_fees.structures.months') : t('education_fees.structures.every_month') }}:</span>
                                <button v-for="month in MONTHS" :key="month" type="button" class="rounded-lg border px-2 py-0.5 text-[12px] transition"
                                    :class="form.lines[head.id].months.includes(month) ? 'border-brand bg-brand-soft text-brand-text' : 'border-line text-muted hover:bg-subtle'"
                                    :aria-pressed="form.lines[head.id].months.includes(month)" @click="toggleMonth(head.id, month)">{{ monthShort(month) }}</button>
                            </div>
                        </li>
                    </ul>
                    <p v-if="errors.lines" class="mt-1 text-[12.5px] text-bad">{{ errors.lines[0] }}</p>
                </fieldset>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('education_fees.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="fee-structure" :loading="saving" :disabled="!form.name.trim() || !linesPayload.length || invalid">{{ t('education_fees.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
