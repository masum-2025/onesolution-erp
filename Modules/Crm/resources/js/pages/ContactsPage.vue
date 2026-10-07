<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Download, FileUp, Plus, Search, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { phoneText, readCsv } from '../lib';
import { useCrmSetup } from '../setup';
import ContactDialog from '../components/ContactDialog.vue';

/**
 * Contacts of this unit and the units below: search by name, number or
 * email; filter by tag, source or my own; add one, import a CSV (checked
 * first, duplicates skipped) or export the list (with the permission).
 */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useCrmSetup();
const filters = ref({ q: route.query.q ?? '', tag: route.query.tag ?? '', source: route.query.source ?? '', mine: route.query.mine === '1', page: Number(route.query.page ?? 1) });
const query = computed(() => Object.fromEntries(Object.entries({ ...filters.value, mine: filters.value.mine ? 1 : '', page: filters.value.page > 1 ? filters.value.page : '' }).filter(([, value]) => value !== '')));
const list = useResource(() => crm.contacts(query.value));
let timer = null;
watch(filters, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        router.replace({ query: query.value });
        list.reload();
    }, 250);
}, { deep: true });
const contacts = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const money = (amount) => formatMoney({ amount, currency: setup.currency.value });

const adding = ref(false);
function added(contact) {
    adding.value = false;
    router.push({ name: 'crm-contact', params: { id: contact.id } });
}

// Import: the CSV read here, checked by the server, then made.
const importing = ref(false);
const importRows = ref([]);
const importCheck = ref(null);
const importBusy = ref(false);
async function pickFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    if (file.size > 2 * 1024 * 1024) {
        toast.error(t('crm.import.too_big'));
        return;
    }
    importRows.value = readCsv(await file.text()).slice(0, 2000);
    importCheck.value = null;
    importing.value = true;
    await runImport(false);
}
async function runImport(commit) {
    if (!importRows.value.length) {
        toast.error(t('crm.import.empty'));
        return;
    }
    importBusy.value = true;
    try {
        const { data } = await crm.importContacts({ commit, rows: importRows.value });
        importCheck.value = data;
        if (commit) {
            toast.success(t('crm.import.done', { count: formatNumber(data.made) }));
            importing.value = false;
            list.reload();
        }
    } catch (error) {
        toast.error(error.message);
    } finally {
        importBusy.value = false;
    }
}
const exportHref = computed(() => crm.exportUrl({ q: filters.value.q, tag: filters.value.tag, source: filters.value.source, mine: filters.value.mine ? 1 : '' }));
</script>

<template>
    <div>
        <PageHeader :title="t('crm.contacts.title')" :description="t('crm.contacts.text')">
            <template #actions>
                <a v-if="can('crm.export')" :href="exportHref" download class="inline-flex h-9 items-center gap-2 rounded-[9px] px-3.5 text-[13.5px] font-medium text-fg-2 hover:bg-subtle hover:text-fg">
                    <Download class="size-4" aria-hidden="true" />{{ t('crm.contacts.export') }}
                </a>
                <label v-if="can('crm.edit')" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-[9px] border border-line-strong bg-surface px-3.5 text-[13.5px] font-medium shadow-xs hover:bg-subtle focus-within:ring-2 focus-within:ring-brand">
                    <FileUp class="size-4" aria-hidden="true" />{{ t('crm.contacts.import') }}
                    <input type="file" accept=".csv,text/csv" class="sr-only" @change="pickFile" />
                </label>
                <AppButton v-if="can('crm.edit')" variant="primary" :icon="Plus" @click="adding = true">{{ t('crm.contacts.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card mb-5 grid gap-3 p-4 sm:grid-cols-[minmax(0,2fr)_1fr_1fr_auto] sm:items-center">
            <label class="relative block">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="filters.q" type="search" class="field-input ps-9" :placeholder="t('crm.contacts.search')" :aria-label="t('crm.contacts.search')" />
            </label>
            <input v-model="filters.tag" class="field-input" :placeholder="t('crm.contacts.tag')" :aria-label="t('crm.contacts.tag')" />
            <select v-model="filters.source" class="field-input" :aria-label="t('crm.contacts.source')">
                <option value="">{{ t('crm.contacts.all_sources') }}</option>
                <option v-for="source in ['walk_in', 'phone', 'referral', 'website', 'facebook', 'event', 'pos', 'import']" :key="source" :value="source">{{ t(`crm.sources.${source}`) }}</option>
            </select>
            <AppSwitch v-model="filters.mine" :label="t('crm.contacts.mine')" show-label />
        </section>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!contacts.length" :icon="Users" :title="t('crm.contacts.empty')" :text="t('crm.contacts.empty_text')" compact>
                <AppButton v-if="can('crm.edit')" variant="primary" :icon="Plus" @click="adding = true">{{ t('crm.contacts.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="contact in contacts" :key="contact.id">
                    <RouterLink :to="{ name: 'crm-contact', params: { id: contact.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-[14px] font-semibold text-brand-text" aria-hidden="true">{{ contact.name.slice(0, 1) }}</span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ contact.name }}<template v-if="contact.company_name"> · <span class="text-muted">{{ contact.company_name }}</span></template></span>
                            <span class="block text-[12.5px] text-muted" dir="ltr">{{ phoneText(contact.phone) || contact.email || '—' }}</span>
                        </span>
                        <span class="flex flex-wrap gap-1">
                            <AppBadge v-for="tag in contact.tags.slice(0, 3)" :key="tag" tone="neutral">{{ tag }}</AppBadge>
                        </span>
                        <span v-if="contact.purchases" class="tabular w-36 text-end text-[13px]">
                            <b>{{ money(contact.spent_minor) }}</b>
                            <span class="block text-[12px] text-muted">{{ t('crm.contacts.purchases', { count: formatNumber(contact.purchases) }) }}<template v-if="contact.points"> · {{ t('crm.contacts.points', { count: formatNumber(contact.points) }) }}</template></span>
                        </span>
                    </RouterLink>
                </li>
            </ul>
            <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3 text-[13px]">
                <AppButton size="sm" variant="ghost" :disabled="filters.page <= 1" @click="filters.page--">{{ t('crm.common.previous') }}</AppButton>
                <span class="text-muted">{{ t('crm.common.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="filters.page >= meta.last_page" @click="filters.page++">{{ t('crm.common.next') }}</AppButton>
            </div>
        </section>

        <ContactDialog v-if="adding" @close="adding = false" @saved="added" />

        <AppDialog :open="importing" :title="t('crm.import.title')" :description="t('crm.import.text')" :icon="FileUp" size="lg" @close="importing = false">
            <SkeletonRows v-if="importBusy && !importCheck" :rows="3" />
            <template v-else-if="importCheck">
                <div class="mb-3 grid grid-cols-3 gap-2 text-center text-[13px]">
                    <p class="rounded-xl bg-subtle p-3"><b class="block text-[18px] text-ok">{{ formatNumber(importCheck.ok) }}</b>{{ t('crm.import.ok') }}</p>
                    <p class="rounded-xl bg-subtle p-3"><b class="block text-[18px] text-warn">{{ formatNumber(importCheck.duplicates) }}</b>{{ t('crm.import.duplicates') }}</p>
                    <p class="rounded-xl bg-subtle p-3"><b class="block text-[18px] text-bad">{{ formatNumber(importCheck.errors) }}</b>{{ t('crm.import.errors') }}</p>
                </div>
                <ul v-if="importCheck.errors" class="max-h-48 divide-y divide-line overflow-y-auto rounded-xl border border-line text-[12.5px]">
                    <li v-for="row in importCheck.rows.filter((row) => row.status === 'error').slice(0, 50)" :key="row.line" class="px-3 py-2">
                        <b>{{ t('crm.import.line', { line: formatNumber(row.line) }) }}</b> — {{ Object.values(row.errors).join(' ') }}
                    </li>
                </ul>
                <p class="mt-3 text-[12.5px] text-muted">{{ t('crm.import.columns') }}</p>
            </template>
            <template #footer>
                <AppButton variant="ghost" @click="importing = false">{{ t('crm.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="importBusy" :disabled="!importCheck?.ok" @click="runImport(true)">{{ t('crm.import.make', { count: formatNumber(importCheck?.ok ?? 0) }) }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
