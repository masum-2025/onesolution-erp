<script setup>
import { computed } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import ExportMenu from '@/components/ExportMenu.vue';
import GuideButton from '@/components/GuideButton.vue';
import { hasGuide } from '@/lib/guide';
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
 *
 * A record's page (its title comes with its data): the header shows once the
 * title is there, a back link goes in #before, and notFound ({ icon, title })
 * answers a 404:
 *
 *   <PageFrame :title="employee?.full_name ?? ''" :resource="record" :not-found="{ icon: UserX, title: … }">
 *       <template #before><AppButton … :icon="ArrowLeft">Back</AppButton></template>
 */
const props = defineProps({
    title: { type: String, default: '' },
    description: { type: String, default: '' },
    // md (forms, a record), lg (most lists), xl (wide tables), full
    width: { type: String, default: 'lg' },
    resource: { type: Object, default: null },
    empty: { type: Object, default: null },
    export: { type: Object, default: null },
    notFound: { type: Object, default: null },
    skeletonRows: { type: Number, default: 5 },
    // The page lays out its own paper (labels, a shipment sheet): no header, name or date on it.
    ownPaper: Boolean,
    // "How to?" guide: a translation key such as 'pharmacy.guide.sell' (see lib/guide.js),
    // where this page's settings live (a route) and a partner's video (https).
    guide: { type: String, default: '' },
    guideSettings: { type: [Object, String], default: null },
    guideVideo: { type: String, default: '' },
});

const widths = { md: 'max-w-3xl', lg: 'max-w-5xl', xl: 'max-w-7xl', full: '' };
const loading = computed(() => props.resource && props.resource.loading.value && !props.resource.data.value);
const failed = computed(() => props.resource?.error.value ?? null);
const ready = computed(() => !props.resource || !!props.resource.data.value);
const missing = computed(() => !!props.notFound && failed.value?.status === 404);
const exportOptions = computed(() => (props.export ? { title: props.title, ...props.export } : null));
const organization = computed(() => currentOrganization()?.name ?? '');
const today = formatDate(new Date().toISOString());
const guideShown = computed(() => hasGuide(props.guide));
</script>

<template>
    <div class="mx-auto" :class="widths[width] ?? widths.lg">
        <!-- On paper: who, what and when, instead of the app's frame. -->
        <div v-if="!ownPaper" class="mb-4 hidden border-b border-line pb-3 print:block">
            <p class="text-[13px] text-muted">{{ organization }}</p>
            <p class="text-[12px] text-muted">{{ t('core.export.printed_on', { date: today }) }}</p>
        </div>
        <slot name="before" />
        <PageHeader v-if="title" :title="title" :description="description" :class="ownPaper ? 'print:hidden' : ''">
            <template v-if="$slots.eyebrow" #eyebrow><slot name="eyebrow" /></template>
            <!-- Buttons are for the screen: never on paper. -->
            <template v-if="$slots.actions || exportOptions || guideShown" #actions>
                <span class="contents print:hidden">
                    <slot name="actions" />
                    <ExportMenu v-if="exportOptions" :options="exportOptions" />
                    <GuideButton v-if="guideShown" :guide="guide" :settings="guideSettings" :video="guideVideo" />
                </span>
            </template>
        </PageHeader>
        <slot name="top" />
        <SkeletonRows v-if="loading" :rows="skeletonRows" />
        <section v-else-if="missing" class="card">
            <EmptyState :icon="notFound.icon" :title="notFound.title" :text="notFound.text ?? ''" />
        </section>
        <ErrorState v-else-if="failed && !ready" :error="failed" @retry="resource.reload()" />
        <template v-else-if="ready">
            <EmptyState v-if="empty?.when" :icon="empty.icon" :title="empty.title" :text="empty.text ?? ''" />
            <slot v-else />
        </template>
    </div>
</template>
