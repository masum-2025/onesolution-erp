<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { ChevronRight, Hourglass, UserRound } from 'lucide-vue-next';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';

/**
 * A portal member's home (Phase 5C-4): the records the client linked to
 * them. The server decides what is theirs; anything else is never sent.
 */
const portal = useResource(() => api('/api/portal').then((response) => response.data));
const data = computed(() => portal.data.value);
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <PageHeader :title="t('portal.home.title')" :description="data ? t('portal.home.text', { org: data.organization }) : ''" />

        <SkeletonRows v-if="portal.loading.value && !data" :rows="3" avatar />
        <ErrorState v-else-if="portal.error.value" :error="portal.error.value" @retry="portal.reload()" />

        <template v-else-if="data">
            <EmptyState v-if="!data.records.length" :icon="UserRound" :title="t('portal.home.empty_title')" :text="t('portal.home.empty_text', { org: data.organization })" />

            <ul v-else class="space-y-3">
                <li v-for="record in data.records" :key="record.id">
                    <RouterLink
                        v-if="record.status === 'active'"
                        :to="record.page ?? `/portal/records/${record.id}`"
                        class="card flex items-center gap-4 p-4 transition hover:bg-subtle/60"
                    >
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                            <UserRound class="size-5" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[15px] font-semibold text-fg">{{ record.name }}</p>
                            <p class="text-[12.5px] text-muted">{{ record.kind }} · {{ t(`portal.record.relation.${record.relation}`) }}</p>
                        </div>
                        <ChevronRight class="size-4 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>

                    <div v-else class="card flex items-center gap-4 p-4" role="status">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-warn-soft text-warn">
                            <Hourglass class="size-5" aria-hidden="true" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-[14px] font-medium text-fg">{{ t('portal.home.pending', { org: data.organization }) }}</p>
                            <p class="text-[12.5px] text-muted">{{ record.kind }} · {{ t('portal.home.pending_text') }}</p>
                        </div>
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
