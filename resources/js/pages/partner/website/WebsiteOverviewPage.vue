<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Check, Eye, FileStack, Globe, History, LayoutTemplate, Lock, PowerOff, RotateCcw, Send, Sparkles } from 'lucide-vue-next';
import { AppBadge, AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import WebsiteNav from './WebsiteNav.vue';
import { changedMeanwhile, openPreview, useWebsite } from './useWebsite';

/**
 * The company website at a glance: whether the public sees it, its
 * template, its pages, and the published versions to go back to. Before
 * it exists: one button that starts it with ready-written pages.
 */
const { resource, site, choices, can, apply } = useWebsite();
const versions = useResource(() => api('/api/partner/website/versions').then((response) => response.data), { immediate: false });
const busy = ref(null);

const STATUS_TONES = { draft: 'neutral', published: 'ok', offline: 'warn' };
const sections = computed(() => (site.value?.pages ?? []).reduce((sum, page) => sum + page.blocks.length, 0));

async function run(key, request) {
    busy.value = key;
    try {
        const response = await request();
        apply(response.data);
        toast.success(response.message);
        if (key === 'publish' || key === 'restore') versions.reload();
    } catch (error) {
        toast.error(error.message);
        if (changedMeanwhile(error)) resource.reload();
    } finally {
        busy.value = null;
    }
}

function start() {
    run('start', () => api('/api/partner/website', { method: 'POST' }));
}

async function publish() {
    const first = !site.value.published_at;
    const confirmed = await confirmAction({
        title: t(first ? 'website.overview.publish_first_title' : 'website.overview.publish_title'),
        message: t(first ? 'website.overview.publish_first_text' : 'website.overview.publish_text'),
        confirmLabel: t('website.overview.publish'),
    });
    if (confirmed) run('publish', () => api('/api/partner/website/publish', { method: 'POST' }));
}

async function takeOffline() {
    const confirmed = await confirmAction({
        title: t('website.overview.offline_title'),
        message: t('website.overview.offline_text'),
        confirmLabel: t('website.overview.offline'),
        danger: true,
    });
    if (confirmed) run('offline', () => api('/api/partner/website/offline', { method: 'POST' }));
}

async function restore(version) {
    const confirmed = await confirmAction({
        title: t('website.overview.restore_title'),
        message: t('website.overview.restore_text', { date: formatDateTime(version.published_at) }),
        confirmLabel: t('website.overview.restore'),
    });
    if (confirmed) run('restore', () => api(`/api/partner/website/versions/${version.id}/restore`, { method: 'POST' }));
}

function chooseTemplate(template) {
    if (template === site.value.template) return;
    run('template', () => api('/api/partner/website', { method: 'PATCH', body: { base_version: site.value.version, template } }));
}

async function preview() {
    busy.value = 'preview';
    try {
        await openPreview();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

// The versions list is read once the site exists.
const showVersions = computed(() => !!site.value);
const stopWatching = watch(showVersions, (exists) => {
    if (!exists) return;
    versions.reload();
    queueMicrotask(() => stopWatching());
}, { immediate: true });
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.text')" :resource="resource" width="lg">
        <template #actions>
            <AppButton v-if="site" :icon="Eye" :loading="busy === 'preview'" @click="preview">{{ t('website.overview.preview') }}</AppButton>
            <AppButton v-if="site && can.publish" variant="primary" :icon="Send" :loading="busy === 'publish'" @click="publish">{{ t('website.overview.publish') }}</AppButton>
        </template>

        <WebsiteNav v-if="site" />

        <EmptyState v-if="!site" :icon="Sparkles" :title="t('website.overview.start_title')" :text="t('website.overview.start_text')">
            <AppButton v-if="can.create" variant="primary" :icon="Sparkles" :loading="busy === 'start'" @click="start">{{ t('website.overview.start') }}</AppButton>
            <p v-else class="text-[13px] text-muted">{{ t('website.overview.start_ask') }}</p>
        </EmptyState>

        <div v-else class="space-y-5">
            <!-- Who sees it -->
            <section class="card p-5">
                <div class="flex flex-wrap items-center gap-3">
                    <Globe class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <span class="block text-[15px] font-semibold text-fg">{{ t(`website.status.${site.status}`) }}</span>
                        <span class="block text-[13px] text-muted">
                            {{ site.published_at ? t('website.overview.published_at', { date: formatDateTime(site.published_at) }) : t('website.overview.never_published') }}
                        </span>
                    </span>
                    <AppBadge :tone="STATUS_TONES[site.status]" dot>{{ t(`website.status.${site.status}`) }}</AppBadge>
                    <AppButton v-if="can.publish && site.status === 'published'" size="sm" variant="danger-soft" :icon="PowerOff" :loading="busy === 'offline'" @click="takeOffline">
                        {{ t('website.overview.offline') }}
                    </AppButton>
                </div>
                <p v-if="site.address" class="mt-3 ps-8 text-[13px]">
                    <a :href="site.address" target="_blank" rel="noopener" class="font-mono text-brand-text hover:underline" dir="ltr">{{ site.address }}</a>
                </p>
                <p class="mt-3 ps-8 text-[12.5px] text-muted">{{ t('website.overview.drafts_note') }}</p>
            </section>

            <!-- Pages -->
            <RouterLink to="/partner/website/pages" class="card flex items-center gap-3 p-5 transition hover:bg-subtle/60">
                <FileStack class="size-5 shrink-0 text-muted" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block text-[14px] font-medium text-fg">{{ t('website.nav.pages') }}</span>
                    <span class="block text-[13px] text-muted">
                        {{ t('website.overview.counts', { pages: formatNumber(site.pages.length), sections: formatNumber(sections) }) }}
                    </span>
                </span>
            </RouterLink>

            <!-- Template -->
            <section class="card p-5">
                <h2 class="flex items-center gap-2 text-[14px] font-semibold text-fg">
                    <LayoutTemplate class="size-4 text-muted" aria-hidden="true" />{{ t('website.overview.template') }}
                </h2>
                <p class="mt-1 text-[13px] text-muted">{{ t('website.overview.template_text') }}</p>
                <div class="mt-4 grid gap-3 sm:grid-cols-3">
                    <button
                        v-for="template in choices.templates"
                        :key="template"
                        type="button"
                        class="relative rounded-xl border p-3 text-start transition"
                        :class="template === site.template ? 'border-brand ring-2 ring-brand/30' : 'border-line hover:border-fg/30'"
                        :disabled="!can.settings || busy === 'template'"
                        :aria-pressed="template === site.template"
                        @click="chooseTemplate(template)"
                    >
                        <span class="website-thumb" :data-template="template" aria-hidden="true"><i /><i /><i /></span>
                        <span class="mt-2 flex items-center gap-1.5 text-[13.5px] font-medium text-fg">
                            {{ t(`website.templates.${template}`) }}
                            <Check v-if="template === site.template" class="size-4 text-brand-text" aria-hidden="true" />
                        </span>
                        <span class="block text-[12px] text-muted">{{ t(`website.templates.${template}_text`) }}</span>
                    </button>
                </div>
                <p v-if="!can.settings" class="mt-3 flex items-center gap-2 text-[12.5px] text-muted">
                    <Lock class="size-3.5" aria-hidden="true" />{{ t('website.owner_only') }}
                </p>
            </section>

            <!-- Published versions -->
            <section v-if="showVersions" class="card">
                <h2 class="flex items-center gap-2 px-5 pt-5 text-[14px] font-semibold text-fg">
                    <History class="size-4 text-muted" aria-hidden="true" />{{ t('website.overview.history') }}
                </h2>
                <SkeletonRows v-if="versions.loading.value && !versions.data.value" :rows="2" />
                <ErrorState v-else-if="versions.error.value" compact :error="versions.error.value" @retry="versions.reload()" />
                <p v-else-if="!versions.data.value?.length" class="px-5 py-4 text-[13px] text-muted">{{ t('website.overview.history_empty') }}</p>
                <ul v-else class="mt-2 divide-y divide-line">
                    <li v-for="(version, index) in versions.data.value" :key="version.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="min-w-0 flex-1 text-[13.5px] text-fg">{{ formatDateTime(version.published_at) }}</span>
                        <AppBadge v-if="index === 0" tone="ok">{{ t('website.overview.latest') }}</AppBadge>
                        <AppButton v-else-if="can.publish" size="sm" :icon="RotateCcw" :loading="busy === 'restore'" @click="restore(version)">{{ t('website.overview.restore') }}</AppButton>
                    </li>
                </ul>
            </section>
        </div>
    </PageFrame>
</template>

<style scoped>
/* A tiny drawing of each template: header bar, hero, cards. */
.website-thumb {
    display: grid;
    gap: 4px;
    block-size: 72px;
    padding: 6px;
    border-radius: 8px;
    grid-template-rows: 8px 1fr 14px;
}
.website-thumb i { border-radius: 3px; }
.website-thumb[data-template='aurora'] { background: linear-gradient(135deg, #0b1437, #1e3a8a); }
.website-thumb[data-template='aurora'] i { background: rgb(255 255 255 / 0.18); }
.website-thumb[data-template='aurora'] i:nth-child(2) { background: linear-gradient(90deg, #60a5fa, #a78bfa); }
.website-thumb[data-template='corporate'] { background: #f8fafc; border: 1px solid #e2e8f0; }
.website-thumb[data-template='corporate'] i { background: #cbd5e1; }
.website-thumb[data-template='corporate'] i:nth-child(2) { background: #1d4ed8; }
.website-thumb[data-template='studio'] { background: #fff7ed; }
.website-thumb[data-template='studio'] i { background: #fed7aa; }
.website-thumb[data-template='studio'] i:nth-child(2) { background: linear-gradient(90deg, #f97316, #db2777); }
</style>
