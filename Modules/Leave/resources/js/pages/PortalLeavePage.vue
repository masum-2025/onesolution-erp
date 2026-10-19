<script setup>
import { computed } from 'vue';
import { CalendarOff } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { portalLeaveApi } from '../api';
import { daysText, statusTone } from '../lib';

/** A portal member's own leave (the employee records the client linked to them): what is left and their requests. Read only. */
const view = useResource(() => portalLeaveApi.show());
const people = computed(() => view.data.value?.data ?? []);

const date = (value) => formatDate(`${value}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const range = (item) => (item.from_date === item.to_date ? date(item.from_date) : `${date(item.from_date)} – ${date(item.to_date)}`);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('leave.portal.title')" :description="t('leave.portal.text')" />

        <SkeletonRows v-if="view.loading.value && !view.data.value" :rows="4" />
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <section v-else-if="!people.length" class="card">
            <EmptyState :icon="CalendarOff" :title="t('leave.portal.empty')" compact />
        </section>
        <template v-else>
        <section v-for="person in people" :key="person.employee.id" class="card" :aria-label="person.employee.name">
            <h2 class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ person.employee.name }}</h2>
            <ul class="grid gap-3 p-5 sm:grid-cols-3">
                <li v-for="row in person.balances.filter((item) => item.paid)" :key="row.type_id" class="rounded-xl border border-line p-3">
                    <p class="text-[12.5px] text-muted">{{ row.name }}</p>
                    <p class="mt-1 text-[20px] font-semibold tabular">{{ daysText(row.available_halves) }} <span class="text-[12.5px] font-normal text-muted">{{ t('leave.me.days_left') }}</span></p>
                </li>
            </ul>
            <ul v-if="person.requests.length" class="divide-y divide-line border-t border-line">
                <li v-for="item in person.requests" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-3">
                    <span class="text-[13.5px]">{{ item.type_name }} · {{ range(item) }} · {{ t('leave.common.days', { days: daysText(item.halves) }) }}</span>
                    <AppBadge :tone="statusTone(item.status)">{{ t(`leave.status.${item.status}`) }}</AppBadge>
                </li>
            </ul>
        </section>
        </template>
    </div>
</template>
