<script setup>
import { computed, ref } from 'vue';
import { ArrowLeft, CheckCircle2, Download, FileSpreadsheet, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { complianceApi } from '../api';

/**
 * The register from an Excel (.xlsx) or CSV file: checked first (nothing is
 * stored), the problems shown row by row, then imported. The file goes to
 * the server each time; it is never kept there.
 */
const org = currentOrganization();
const calls = complianceApi(org.id);
const COLUMNS = ['factory', 'service', 'provider', 'audit_type', 'last_audit_on', 'expires_on', 'result', 'certificate_no'];

const file = ref(null);
const checked = ref(null);
const done = ref(null);
const busy = ref(false);
const error = ref(null);
const problems = computed(() => (checked.value?.rows ?? []).filter((row) => Object.keys(row.errors).length));
const newProviders = computed(() => (checked.value?.rows ?? []).filter((row) => row.new_provider && !Object.keys(row.errors).length).length);

function pick(event) {
    file.value = event.target.files?.[0] ?? null;
    checked.value = null;
    done.value = null;
    error.value = null;
    if (file.value) check();
}
async function check() {
    busy.value = true;
    error.value = null;
    try {
        checked.value = (await calls.importFile(file.value, true)).data;
    } catch (caught) {
        error.value = caught.errors?.file?.[0] ?? caught.message;
    } finally {
        busy.value = false;
    }
}
async function importNow(skipInvalid) {
    busy.value = true;
    try {
        done.value = (await calls.importFile(file.value, false, skipInvalid)).data;
        toast.success(t('compliance.import.done_toast'));
    } catch (caught) {
        error.value = caught.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-4xl">
        <PageHeader :title="t('compliance.import.title')" :description="t('compliance.import.text')">
            <template #actions>
                <AppButton variant="ghost" :icon="ArrowLeft" :to="{ name: 'compliance' }">{{ t('compliance.register.title') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card mb-4 space-y-4 p-5">
            <ol class="space-y-1.5 text-[13.5px] text-fg-2">
                <li>1. <a :href="calls.templateUrl" class="inline-flex items-center gap-1 font-medium text-brand hover:underline" download><Download class="size-4" aria-hidden="true" />{{ t('compliance.import.template') }}</a></li>
                <li>2. {{ t('compliance.import.fill') }}</li>
                <li>3. {{ t('compliance.import.choose') }}</li>
            </ol>
            <details class="rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-fg-2">
                <summary class="cursor-pointer font-medium text-fg">{{ t('compliance.import.columns_title') }}</summary>
                <ul class="mt-2 space-y-1">
                    <li v-for="column in COLUMNS" :key="column"><span class="font-mono" dir="ltr">{{ column }}</span> — {{ t(`compliance.import.columns.${column}`) }}</li>
                </ul>
            </details>
            <label class="flex cursor-pointer flex-col items-center gap-2 rounded-xl border-2 border-dashed border-line px-4 py-8 text-center transition hover:bg-subtle/60">
                <FileSpreadsheet class="size-8 text-muted" aria-hidden="true" />
                <span class="text-[14px] font-medium">{{ file ? file.name : t('compliance.import.pick') }}</span>
                <span class="text-[12.5px] text-muted">{{ t('compliance.import.kinds') }}</span>
                <input type="file" class="sr-only" accept=".xlsx,.csv" @change="pick" />
            </label>
            <p v-if="busy && !checked" class="text-[13px] text-muted" role="status">{{ t('compliance.import.checking') }}</p>
            <p v-if="error" class="rounded-lg bg-bad-soft px-3 py-2 text-[13px] text-bad" role="alert">{{ error }}</p>
        </section>

        <section v-if="done" class="card flex items-start gap-3 p-5" role="status">
            <CheckCircle2 class="size-6 shrink-0 text-ok" aria-hidden="true" />
            <div class="space-y-2">
                <p class="text-[14px] font-medium">{{ t('compliance.import.done', { added: formatNumber(done.added), updated: formatNumber(done.updated) }) }}</p>
                <p v-if="done.unchanged || done.skipped" class="text-[13px] text-muted">{{ t('compliance.import.done_more', { unchanged: formatNumber(done.unchanged), skipped: formatNumber(done.skipped) }) }}</p>
                <AppButton size="sm" :to="{ name: 'compliance' }">{{ t('compliance.import.open_register') }}</AppButton>
            </div>
        </section>
        <section v-else-if="checked" class="card">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                <p class="text-[13.5px]">
                    {{ t('compliance.import.checked', { valid: formatNumber(checked.valid), total: formatNumber(checked.total) }) }}
                    <template v-if="newProviders"> · {{ t('compliance.import.new_providers', { count: newProviders }) }}</template>
                </p>
                <div class="flex flex-wrap gap-2">
                    <AppButton v-if="problems.length && checked.valid" :icon="Upload" :loading="busy" @click="importNow(true)">{{ t('compliance.import.skip_and_import', { count: checked.valid }) }}</AppButton>
                    <AppButton v-if="!problems.length" variant="primary" :icon="Upload" :loading="busy" @click="importNow(false)">{{ t('compliance.import.import', { count: checked.valid }) }}</AppButton>
                </div>
            </header>
            <p v-if="!problems.length" class="px-5 py-4 text-[13px] text-ok">{{ t('compliance.import.all_good') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in problems" :key="row.row_no" class="px-5 py-3 text-[13px]">
                    <p class="flex flex-wrap items-center gap-2">
                        <AppBadge tone="bad">{{ t('compliance.import.row', { row: row.row_no }) }}</AppBadge>
                        <span class="font-medium">{{ row.factory || '—' }} · {{ row.service || '—' }}</span>
                    </p>
                    <ul class="mt-1 space-y-0.5 text-[12.5px] text-bad">
                        <li v-for="(message, column) in row.errors" :key="column"><span class="font-mono" dir="ltr">{{ column }}</span>: {{ message }}</li>
                    </ul>
                </li>
            </ul>
        </section>
    </div>
</template>
