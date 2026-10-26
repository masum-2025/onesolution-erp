<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, ReceiptText, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ClaimDetail from '../components/ClaimDetail.vue';
import ClaimEditor from '../components/ClaimEditor.vue';
import { expensesApi } from '../api';
import { amountText, monthText, statusTone } from '../lib';

/**
 * An employee's own claims (the login HR linked to them): what is still a
 * draft, waiting or owed to them, a new claim (also from the header "New"
 * menu, ?new=1), drafts opened to edit and send, the others to follow.
 */
const route = useRoute();
const router = useRouter();
const org = currentOrganization();
const calls = expensesApi(org.id);
const me = useResource(() => calls.me());
const data = computed(() => me.data.value?.data ?? null);
const kinds = computed(() => data.value?.categories ?? []);

const editing = ref(null); // 'new' | a draft's detail
const viewing = ref(null); // a claim id

function startNew() {
    editing.value = 'new';
}
onMounted(() => {
    if (route.query.new) {
        editing.value = 'new';
        router.replace({ query: { ...route.query, new: undefined } });
    }
});

async function open(item) {
    if (item.status !== 'draft') {
        viewing.value = item.id;
        return;
    }
    editing.value = (await calls.myClaim(item.id)).data;
}
function editFromDetail(claim) {
    viewing.value = null;
    editing.value = claim;
}
function sent() {
    editing.value = null;
    me.reload();
}

const when = (item) => formatDate(item.submitted_at ?? item.created_at, { dateStyle: 'medium' });
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('expenses.me.title')" :description="t('expenses.me.text')">
            <template v-if="data && kinds.length" #actions>
                <AppButton variant="primary" :icon="Plus" @click="startNew">{{ t('expenses.me.new') }}</AppButton>
            </template>
        </PageHeader>

        <section v-if="me.error.value?.code === 'not_linked'" class="card">
            <EmptyState :icon="UserX" :title="t('expenses.me.not_linked')" :text="t('expenses.me.not_linked_text')" />
        </section>
        <ErrorState v-else-if="me.error.value" :error="me.error.value" @retry="me.reload()" />
        <SkeletonRows v-else-if="!data" :rows="4" />
        <template v-else>
            <section class="grid gap-4 sm:grid-cols-3" :aria-label="t('expenses.me.summary')">
                <article class="card p-4">
                    <p class="text-[13px] font-medium text-fg-2">{{ t('expenses.me.owed') }}</p>
                    <p class="mt-2 tabular text-[26px] font-semibold leading-none">{{ amountText(data.summary.owed_minor, data.currency) }}</p>
                    <p class="mt-2 text-[12px] text-muted">{{ t('expenses.me.owed_text') }}</p>
                </article>
                <article class="card p-4">
                    <p class="text-[13px] font-medium text-fg-2">{{ t('expenses.me.waiting') }}</p>
                    <p class="mt-2 tabular text-[26px] font-semibold leading-none">{{ formatNumber(data.summary.waiting) }}</p>
                    <p class="mt-2 text-[12px] text-muted">{{ t('expenses.me.waiting_text') }}</p>
                </article>
                <article class="card p-4">
                    <p class="text-[13px] font-medium text-fg-2">{{ t('expenses.me.drafts') }}</p>
                    <p class="mt-2 tabular text-[26px] font-semibold leading-none">{{ formatNumber(data.summary.drafts) }}</p>
                    <p class="mt-2 text-[12px] text-muted">{{ t('expenses.me.drafts_text') }}</p>
                </article>
            </section>

            <section v-if="!kinds.length" class="card">
                <EmptyState :icon="ReceiptText" :title="t('expenses.me.no_kinds')" :text="t('expenses.me.no_kinds_text')" compact />
            </section>

            <section class="card" aria-labelledby="expenses-mine">
                <h2 id="expenses-mine" class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ t('expenses.me.claims') }}</h2>
                <EmptyState v-if="!data.claims.length" :icon="ReceiptText" :title="t('expenses.me.empty')" :text="t('expenses.me.empty_text')" compact>
                    <AppButton v-if="kinds.length" variant="primary" :icon="Plus" @click="startNew">{{ t('expenses.me.new') }}</AppButton>
                </EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in data.claims" :key="item.id">
                        <button type="button" class="grid w-full gap-1 px-5 py-3 text-start hover:bg-subtle sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center sm:gap-4" @click="open(item)">
                            <span class="min-w-0">
                                <span class="block truncate text-[14px] font-medium">{{ item.title }}</span>
                                <span class="block text-[12.5px] text-muted">
                                    <span v-if="item.number" class="font-mono" dir="ltr">{{ item.number }} · </span>{{ when(item) }} · {{ t('expenses.common.lines', { count: item.line_count }) }}
                                    <template v-if="item.status === 'approved' && item.pay_method === 'payroll'"> · {{ t('expenses.detail.with_salary', { month: monthText(item.pay_period) }) }}</template>
                                    <template v-if="item.decision_note && item.status === 'rejected'"> · “{{ item.decision_note }}”</template>
                                </span>
                            </span>
                            <span class="flex items-center gap-3">
                                <span class="tabular text-[14px] font-semibold">{{ amountText(item.total_minor, item.currency) }}</span>
                                <AppBadge :tone="statusTone(item.status)">{{ t(`expenses.status.${item.status}`) }}</AppBadge>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </template>

        <ClaimEditor
            :open="editing !== null"
            :claim="editing === 'new' ? null : editing"
            :categories="kinds"
            :limits="data?.limits ?? {}"
            :currency="data?.currency ?? 'BDT'"
            :calls="calls"
            @close="editing = null"
            @changed="me.reload()"
            @sent="sent"
        />
        <ClaimDetail :open="viewing !== null" :claim-id="viewing" own :calls="calls" @close="viewing = null" @changed="me.reload()" @edit="editFromDetail" />
    </div>
</template>
