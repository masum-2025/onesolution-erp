<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { ArrowLeft, CircleCheck, Download, FileSpreadsheet, FileUp, Play, RotateCcw, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDateTime, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';
import { csvTemplate } from '../lib';

/**
 * Employees from a CSV file: download the template, check a file (nothing
 * saved yet), see every problem by row and column, then import (or cancel).
 * The import runs in the background; this page follows it until it is done.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);
const POLL_MS = 2000;

const unitId = ref(org.id);
const units = useResource(() => api('/api/organizations', { query: { per_page: 100 } }));
const workUnits = computed(() => (units.data.value?.data ?? []).filter((unit) => ['company', 'branch', 'department', 'personal'].includes(unit.type)));
const columns = useResource(() => hrm.importColumns(unitId.value));
watch(unitId, () => columns.reload());
const recent = useResource(() => hrm.imports());

const file = ref(null);
const fileInput = ref(null);
const checking = ref(false);
const fileError = ref(null);
const current = ref(null);
const skipInvalid = ref(false);
const acting = ref(false);
let timer = null;

const limits = computed(() => columns.data.value?.data ?? null);
const required = computed(() => (limits.value?.columns ?? []).filter((column) => column.required).map((column) => column.key));
const custom = computed(() => (limits.value?.columns ?? []).filter((column) => column.label));
const running = computed(() => ['queued', 'running'].includes(current.value?.status));
const progress = computed(() => (current.value?.valid_rows ? Math.round(((current.value.progress ?? 0) / current.value.valid_rows) * 100) : 0));
const canStart = computed(() => current.value?.status === 'checked' && current.value.valid_rows > 0 && (current.value.invalid_rows === 0 || skipInvalid.value));

function downloadTemplate() {
    const blob = new Blob([csvTemplate(limits.value?.columns)], { type: 'text/csv;charset=utf-8' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'employees-template.csv';
    link.click();
    URL.revokeObjectURL(link.href);
}

async function check() {
    if (!file.value) return;
    const body = new FormData();
    body.append('file', file.value);
    if (unitId.value !== org.id) body.append('unit_id', unitId.value);

    checking.value = true;
    fileError.value = null;
    try {
        current.value = (await hrm.importFile(body)).data;
        skipInvalid.value = false;
        recent.reload();
    } catch (error) {
        fileError.value = error.errors?.file?.[0] ?? error.message;
    } finally {
        checking.value = false;
    }
}

async function start() {
    acting.value = true;
    try {
        current.value = (await hrm.startImport(current.value.id, skipInvalid.value)).data;
        follow();
    } catch (error) {
        toast.error(error.message);
    } finally {
        acting.value = false;
    }
}

async function cancel() {
    const confirmed = await confirmAction({
        title: t('hrm.import_page.cancel_title'),
        message: t('hrm.import_page.cancel_text'),
        confirmLabel: t('hrm.import_page.cancel'),
        danger: true,
    });
    if (!confirmed) return;

    acting.value = true;
    try {
        current.value = (await hrm.cancelImport(current.value.id)).data;
        toast.success(t('hrm.import_page.cancelled'));
        recent.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        acting.value = false;
    }
}

/** Follows a running import until it is done (or the page is left). */
function follow() {
    clearTimeout(timer);
    if (!running.value) {
        if (current.value?.status === 'done') {
            toast.success(t('hrm.import_page.done_toast', { count: current.value.imported_rows }));
            recent.reload();
        }
        return;
    }
    timer = setTimeout(async () => {
        try {
            current.value = (await hrm.importStatus(current.value.id)).data;
        } catch {
            // A failed check is retried on the next tick (slow networks).
        }
        follow();
    }, POLL_MS);
}

async function open(item) {
    try {
        current.value = (await hrm.importStatus(item.id)).data;
        skipInvalid.value = false;
        follow();
    } catch (error) {
        toast.error(error.message);
    }
}

function again() {
    current.value = null;
    file.value = null;
    fileError.value = null;
    if (fileInput.value) fileInput.value.value = '';
}

onBeforeUnmount(() => clearTimeout(timer));

const statusTone = (status) => ({ checked: 'brand', queued: 'warn', running: 'warn', done: 'ok', cancelled: 'outline' })[status] ?? 'neutral';
const rowTone = (status) => ({ invalid: 'bad', failed: 'bad', skipped: 'outline' })[status] ?? 'neutral';
</script>

<template>
    <div class="mx-auto max-w-4xl">
        <PageHeader :title="t('hrm.import_page.title')" :description="t('hrm.import_page.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
            </template>
        </PageHeader>

        <!-- 1. Choose a file -->
        <section v-if="!current" class="card p-5 sm:p-6">
            <SkeletonRows v-if="columns.loading.value && !limits" :rows="4" />
            <ErrorState v-else-if="columns.error.value" compact :error="columns.error.value" @retry="columns.reload()" />
            <form v-else class="space-y-5" novalidate @submit.prevent="check">
                <ol class="space-y-2 text-[13px] text-fg-2">
                    <li>1. {{ t('hrm.import_page.how_template') }}</li>
                    <li>2. {{ t('hrm.import_page.how_fill', { columns: required.join(', ') }) }}</li>
                    <li>3. {{ t('hrm.import_page.how_save') }}</li>
                </ol>

                <details class="rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-fg-2">
                    <summary class="cursor-pointer font-medium text-fg">{{ t('hrm.import_page.columns_title') }}</summary>
                    <ul class="mt-2 space-y-1">
                        <li><span class="font-mono" dir="ltr">joined_on, date_of_birth</span> — {{ t('hrm.import_page.hint_dates') }}</li>
                        <li><span class="font-mono" dir="ltr">employment_type</span> — {{ t('hrm.import_page.hint_kinds') }}</li>
                        <li><span class="font-mono" dir="ltr">position_code, unit_code, manager_code</span> — {{ t('hrm.import_page.hint_codes') }}</li>
                        <li><span class="font-mono" dir="ltr">gender</span> — female, male, other, undisclosed</li>
                        <li v-for="column in custom" :key="column.key"><span class="font-mono" dir="ltr">{{ column.key }}</span> — {{ column.label }}</li>
                    </ul>
                </details>

                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('hrm.import_page.unit')" :hint="t('hrm.import_page.unit_hint')">
                        <select :id="id" v-model="unitId" class="field-input">
                            <option v-for="unit in workUnits" :key="unit.id" :value="unit.id">{{ '— '.repeat(Math.max(0, unit.depth - workUnits[0].depth)) }}{{ unit.display_name }}</option>
                        </select>
                    </AppField>
                    <div class="flex items-end">
                        <AppButton :icon="Download" @click="downloadTemplate">{{ t('hrm.import_page.template') }}</AppButton>
                    </div>
                </div>

                <AppField
                    v-slot="{ id }"
                    :label="t('hrm.import_page.file')"
                    :hint="t('hrm.import_page.file_hint', { rows: limits?.max_rows ?? 0, kb: limits?.max_kb ?? 0 })"
                    :error="fileError"
                >
                    <input :id="id" ref="fileInput" type="file" class="field-input" accept=".csv,text/csv" @change="file = $event.target.files[0] ?? null" />
                </AppField>

                <div class="flex justify-end border-t border-line pt-4">
                    <AppButton variant="primary" type="submit" :icon="FileUp" :loading="checking" :disabled="!file">{{ t('hrm.import_page.check') }}</AppButton>
                </div>
            </form>
        </section>

        <!-- 2. Findings, progress, result -->
        <section v-else class="card">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-4">
                <FileSpreadsheet class="size-5 text-muted" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14px] font-medium text-fg">{{ current.file_name }}</span>
                    <span class="block text-[12px] text-muted">{{ current.unit.name }}</span>
                </span>
                <AppBadge :tone="statusTone(current.status)" dot>{{ current.status_label }}</AppBadge>
            </header>

            <dl class="grid grid-cols-2 gap-4 border-b border-line px-5 py-4 text-[13px] sm:grid-cols-4">
                <div><dt class="text-muted">{{ t('hrm.import_page.rows') }}</dt><dd class="tabular text-[18px] font-semibold text-fg">{{ formatNumber(current.total_rows) }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.import_page.ready') }}</dt><dd class="tabular text-[18px] font-semibold text-ok">{{ formatNumber(current.valid_rows) }}</dd></div>
                <div><dt class="text-muted">{{ t('hrm.import_page.problems') }}</dt><dd class="tabular text-[18px] font-semibold" :class="current.invalid_rows ? 'text-bad' : 'text-fg'">{{ formatNumber(current.invalid_rows) }}</dd></div>
                <div v-if="current.status === 'done'"><dt class="text-muted">{{ t('hrm.import_page.imported') }}</dt><dd class="tabular text-[18px] font-semibold text-fg">{{ formatNumber(current.imported_rows) }}</dd></div>
            </dl>

            <div v-if="running" class="border-b border-line px-5 py-4" role="status" aria-live="polite">
                <p class="mb-2 text-[13px] text-fg-2">{{ t('hrm.import_page.running', { done: current.progress ?? 0, total: current.valid_rows }) }}</p>
                <div class="h-2 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="progress" aria-valuemin="0" aria-valuemax="100">
                    <div class="h-full rounded-full bg-brand transition-all" :style="{ inlineSize: `${progress}%` }" />
                </div>
                <p class="mt-2 text-[12px] text-faint">{{ t('hrm.import_page.running_note') }}</p>
            </div>

            <p v-if="current.status === 'done'" class="flex items-center gap-2 border-b border-line px-5 py-4 text-[13.5px] text-fg" role="status">
                <CircleCheck class="size-5 text-ok" aria-hidden="true" />
                {{ t('hrm.import_page.done', { imported: current.imported_rows, failed: current.failed_rows, skipped: current.invalid_rows }) }}
            </p>

            <div v-if="current.problems?.length" class="max-h-[28rem] overflow-y-auto">
                <table class="w-full text-start text-[12.5px]">
                    <caption class="sr-only">{{ t('hrm.import_page.problems') }}</caption>
                    <thead class="sticky top-0 bg-surface text-muted">
                        <tr>
                            <th scope="col" class="px-5 py-2 text-start font-medium">{{ t('hrm.import_page.row') }}</th>
                            <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('hrm.import_page.what') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in current.problems" :key="row.row_no" class="align-top">
                            <td class="px-5 py-2.5 whitespace-nowrap">
                                <span class="tabular font-medium text-fg">{{ formatNumber(row.row_no) }}</span>
                                <AppBadge v-if="row.status !== 'invalid'" :tone="rowTone(row.status)" class="ms-2">{{ t(`hrm.import_page.row_statuses.${row.status}`) }}</AppBadge>
                            </td>
                            <td class="px-2 py-2.5">
                                <span v-if="row.name" class="block font-medium text-fg">{{ row.name }}</span>
                                <ul class="space-y-0.5 text-fg-2">
                                    <li v-for="(message, column) in row.errors" :key="column">
                                        <span v-if="column !== 'row'" class="font-mono text-muted" dir="ltr">{{ column }}</span><template v-if="column !== 'row'">: </template>{{ message }}
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-4">
                <AppSwitch v-if="current.status === 'checked' && current.invalid_rows > 0 && current.valid_rows > 0" v-model="skipInvalid" :label="t('hrm.import_page.skip', { count: current.invalid_rows })" show-label />
                <span v-else />
                <div class="flex flex-wrap gap-2">
                    <template v-if="current.status === 'checked'">
                        <AppButton variant="ghost" :icon="X" :disabled="acting" @click="cancel">{{ t('hrm.import_page.cancel') }}</AppButton>
                        <AppButton variant="primary" :icon="Play" :loading="acting" :disabled="!canStart" @click="start">
                            {{ t('hrm.import_page.start', { count: current.valid_rows }) }}
                        </AppButton>
                    </template>
                    <AppButton v-else-if="current.status === 'queued'" variant="ghost" :icon="X" :disabled="acting" @click="cancel">{{ t('hrm.import_page.cancel') }}</AppButton>
                    <AppButton v-if="!running" :icon="RotateCcw" @click="again">{{ t('hrm.import_page.another') }}</AppButton>
                    <AppButton v-if="current.status === 'done'" variant="primary" :to="{ name: 'hrm' }">{{ t('hrm.import_page.to_list') }}</AppButton>
                </div>
            </footer>
        </section>

        <!-- Recent imports -->
        <section v-if="recent.data.value?.data?.length" class="card mt-6">
            <h2 class="border-b border-line px-5 py-3 text-[13px] font-medium text-fg-2">{{ t('hrm.import_page.recent') }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="item in recent.data.value.data" :key="item.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-start transition hover:bg-subtle/60" @click="open(item)">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13.5px] font-medium text-fg">{{ item.file_name }}</span>
                            <span class="block text-[12px] text-muted">{{ formatDateTime(item.created_at) }} · {{ item.unit.name }} · {{ t('hrm.import_page.recent_counts', { rows: item.total_rows, imported: item.imported_rows }) }}</span>
                        </span>
                        <AppBadge :tone="statusTone(item.status)" dot>{{ item.status_label }}</AppBadge>
                    </button>
                </li>
            </ul>
        </section>
    </div>
</template>
