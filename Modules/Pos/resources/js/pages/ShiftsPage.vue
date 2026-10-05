<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Clock } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { posApi } from '../api';
import { shiftTone } from '../lib';

/** Shifts at the counters, newest first; open, waiting for review, closed. */
const pos = posApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const status = ref(route.query.status ?? '');
const list = useResource(() => pos.shifts(status.value ? { status: status.value } : {}));
watch(status, () => {
    router.replace({ query: status.value ? { status: status.value } : {} });
    list.reload();
});
const registers = useResource(() => pos.registers());
const registerName = (id) => (registers.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '';
const shifts = computed(() => list.data.value?.data ?? []);
</script>

<template>
    <div>
        <PageHeader :title="t('pos.shifts.title')" :description="t('pos.shifts.text')" />
        <select v-model="status" class="field-input mb-4 w-auto" :aria-label="t('pos.shifts.status')">
            <option value="">{{ t('pos.shifts.all') }}</option>
            <option v-for="value in ['open', 'pending_review', 'closed']" :key="value" :value="value">{{ t(`pos.shift_status.${value}`) }}</option>
        </select>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!shifts.length" :icon="Clock" :title="t('pos.shifts.empty')" :text="t('pos.shifts.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="shift in shifts" :key="shift.id">
                    <RouterLink :to="{ name: 'pos-shift', params: { id: shift.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ registerName(shift.register_id) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ formatDateTime(shift.opened_at) }}<template v-if="shift.closed_at"> → {{ formatDateTime(shift.closed_at) }}</template></span>
                        </span>
                        <AppBadge :tone="shiftTone(shift.status)" dot>{{ t(`pos.shift_status.${shift.status}`) }}</AppBadge>
                        <span v-if="shift.variance_minor" class="tabular text-[12.5px]" :class="shift.variance_minor < 0 ? 'text-bad' : 'text-ok'">{{ formatMoney({ amount: shift.variance_minor, currency: shift.currency }) }}</span>
                        <span class="tabular w-32 text-end text-[14px] font-semibold">{{ formatMoney({ amount: shift.sales_minor - shift.returns_minor, currency: shift.currency }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
