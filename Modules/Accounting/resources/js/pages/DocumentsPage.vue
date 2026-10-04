<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronLeft, ChevronRight, FilePlus, FileText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney, formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { daysUntilDue, documentTone, isCredit, statusKey, todayIn } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * Invoices and credit notes (sales) or bills and vendor credits
 * (purchases), newest first: status, overdue only, search. Each row shows
 * what is still due and when.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const side = computed(() => route.meta.side ?? 'sales');
const kinds = computed(() => (side.value === 'sales' ? ['invoice', 'credit_note'] : ['bill', 'vendor_credit']));
const writer = computed(() => can(side.value === 'sales' ? 'accounting.sell' : 'accounting.buy'));
const today = todayIn(session.me?.context?.settings?.timezone);

const search = ref('');
const status = ref(route.query.status ?? '');
const overdue = ref(false);
const page = ref(1);
const list = useResource(() =>
    books.documents({ side: side.value, q: search.value.trim(), status: status.value, overdue: overdue.value ? 1 : undefined, page: page.value, per_page: 25 }),
);
const documents = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const filtering = computed(() => search.value.trim() !== '' || status.value !== '' || overdue.value);

let timer = null;
watch([search, status, overdue], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        list.reload();
    }, 300);
});
watch(side, () => {
    page.value = 1;
    list.reload();
});
onBeforeUnmount(() => clearTimeout(timer));

function go(to) {
    page.value = to;
    list.reload();
}

function dueText(document) {
    if (isCredit(document.type) || !['posted', 'partly_paid'].includes(document.status)) return '';
    const days = daysUntilDue(document.due_date, today);
    if (days === 0) return t('accounting.documents.due_today');
    return days > 0 ? t('accounting.documents.due_in', { days: formatNumber(days) }) : t('accounting.documents.overdue_by', { days: formatNumber(-days) });
}
</script>

<template>
    <div>
        <PageHeader :title="t(`accounting.documents.${side}`)" :description="t(`accounting.documents.${side}_text`)">
            <template #actions>
                <template v-if="writer">
                    <AppButton :to="{ name: 'accounting-document-new', query: { type: kinds[1] } }">{{ t(`accounting.documents.new_${kinds[1]}`) }}</AppButton>
                    <AppButton variant="primary" :icon="FilePlus" :to="{ name: 'accounting-document-new', query: { type: kinds[0] } }">{{ t(`accounting.documents.new_${kinds[0]}`) }}</AppButton>
                </template>
            </template>
        </PageHeader>

        <BooksGate>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <AppField v-slot="{ id }" :label="t('accounting.documents.search')" sr-only-label class="flex-1">
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                        <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('accounting.documents.search')" autocomplete="off" />
                    </div>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.documents.filters.status')" sr-only-label class="sm:w-56">
                    <select :id="id" v-model="status" class="field-input">
                        <option value="">{{ t('accounting.documents.filters.all') }}</option>
                        <option value="open">{{ t('accounting.documents.filters.open') }}</option>
                        <option v-for="value in ['draft', 'pending_approval', 'rejected', 'paid', 'void']" :key="value" :value="value">{{ t(`accounting.doc_statuses.${value}`) }}</option>
                    </select>
                </AppField>
                <AppSwitch v-model="overdue" :label="t('accounting.documents.filters.overdue')" show-label />
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState
                    v-else-if="!documents.length"
                    :icon="FileText"
                    :title="filtering ? t('accounting.list.none_title') : t('accounting.documents.empty_title')"
                    :text="filtering ? t('accounting.list.none_text') : t('accounting.documents.empty_text')"
                    compact
                />
                <ul v-else class="divide-y divide-line">
                    <li v-for="document in documents" :key="document.id">
                        <RouterLink :to="{ name: 'accounting-document', params: { id: document.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ document.party_name }}</span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    {{ t(`accounting.kinds.${document.type}`) }}
                                    <span v-if="document.number" class="font-mono" dir="ltr">{{ document.number }}</span>
                                    · {{ formatDate(document.issue_date) }}
                                    <template v-if="dueText(document)"> · <span :class="dueText(document) && daysUntilDue(document.due_date, today) < 0 ? 'text-bad' : ''">{{ dueText(document) }}</span></template>
                                </span>
                            </span>
                            <span class="shrink-0 text-end">
                                <span class="tabular block text-[13.5px] font-medium text-fg">{{ formatMoney({ amount: isCredit(document.type) ? -document.total_minor : document.total_minor, currency: document.currency }) }}</span>
                                <span v-if="document.status === 'partly_paid'" class="tabular block text-[12px] text-muted">{{ t('accounting.documents.balance') }}: {{ formatMoney({ amount: document.balance_minor, currency: document.currency }) }}</span>
                            </span>
                            <AppBadge :tone="documentTone(document.status)" dot class="hidden shrink-0 sm:inline-flex">
                                {{ t(statusKey(document.type, document.status)) }}
                            </AppBadge>
                        </RouterLink>
                    </li>
                </ul>
                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('accounting.list.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </BooksGate>
    </div>
</template>
