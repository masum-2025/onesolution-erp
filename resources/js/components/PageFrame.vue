<script setup>
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import { currentOrganization } from '@/lib/session';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * The frame of every module page, in one line: title, width, the loading /
 * error / empty states of its data, and Export / Print where the company's
 * rules allow them. A change here (how loading looks, what print carries)
 * reaches every module without touching them.
 *
 *   <PageFrame :title="t('x.title')" :resource="list" :empty="{ when: !rows.length, icon: Inbox, title: …, text: … }"
 *              :export="{ module: 'accounting', page: 'accounting.journals', columns, rows }">
 *       …the page's own content…
 *   </PageFrame>
 *
 * resource: a useResource() result (its loading, error and reload are used).
 * The content shows once there is data (or at once without a resource).
 */
const props = defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    // md (forms, a record), lg (most lists), xl (wide tables), full
    width: { type: String, default: 'lg' },
    resource: { type: Object, default: null },
    empty: { type: Object, default: null },
    export: { type: Object, default: null },
    skeletonRows: { type: Number, default: 5 },
});

const widths = { md: 'max-w-3xl', lg: 'max-w-5xl', xl: 'max-w-7xl', full: '' };
const loading = computed(() => props.resource && props.resource.loading.value && !props.resource.data.value);
const failed = computed(() => props.resource?.error.value ?? null);
const ready = computed(() => !props.resource || !!props.resource.data.value);
const exportOptions = computed(() => (props.export ? { title: props.title, ...props.export } : null));
const organization = computed(() => currentOrganization()?.name ?? '');
const today = formatDate(new Date().toISOString());
</script>

<template>
    <div class="mx-auto" :class="widths[width] ?? widths.lg">
        <!-- On paper: who, what and when, instead of the app's frame. -->
        <div class="mb-4 hidden border-b border-line pb-3 print:block">
            <p class="text-[13px] text-muted">{{ organization }}</p>
            <p class="text-[12px] text-muted">{{ t('core.export.printed_on', { date: today }) }}</p>
        </div>
        <PageHeader :title="title" :description="description">
            <template v-if="$slots.eyebrow" #eyebrow><slot name="eyebrow" /></template>
            <template v-if="$slots.actions || exportOptions" #actions>
                <slot name="actions" />
                <ExportMenu v-if="exportOptions" :options="exportOptions" />
            </template>
        </PageHeader>
        <slot name="top" />
        <SkeletonRows v-if="loading" :rows="skeletonRows" />
        <ErrorState v-else-if="failed && !ready" :error="failed" @retry="resource.reload()" />
        <template v-else-if="ready">
            <EmptyState v-if="empty?.when" :icon="empty.icon" :title="empty.title" :text="empty.text ?? ''" />
            <slot v-else />
        </template>
    </div>
</template>
