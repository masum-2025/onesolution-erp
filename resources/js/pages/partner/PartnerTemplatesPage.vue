<script setup>
import { computed } from 'vue';
import { RouterLink } from 'vue-router';
import { ChevronRight, Mail, MessageSquare } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';

/**
 * Partner console: every message the platform sends for the partner, and
 * whether the partner has reworded it (per channel and language).
 */
const list = useResource(() => api('/api/partner/templates'));
const rows = computed(() => list.data.value?.data ?? []);
</script>

<template>
    <div>
        <PageHeader :title="t('messaging.templates.title')" :description="t('messaging.templates.text')" />

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.key">
                    <RouterLink :to="`/partner/templates/${row.key}`" class="flex items-center gap-3 px-5 py-4 transition hover:bg-subtle/60">
                        <div class="min-w-0 flex-1">
                            <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                                {{ row.name }}
                                <AppBadge v-if="row.customized.length" tone="brand">{{ t('messaging.templates.customized', { count: row.customized.length }) }}</AppBadge>
                            </p>
                            <p class="mt-0.5 text-[12.5px] text-muted">{{ row.description }}</p>
                        </div>
                        <span class="flex shrink-0 gap-1.5 text-muted" :aria-label="row.channels.map((channel) => t(`messaging.channels.${channel}`)).join(', ')">
                            <Mail v-if="row.channels.includes('mail')" class="size-4" aria-hidden="true" />
                            <MessageSquare v-if="row.channels.includes('sms')" class="size-4" aria-hidden="true" />
                        </span>
                        <ChevronRight class="size-4 shrink-0 text-faint rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
