<script setup>
import { computed, ref } from 'vue';
import { Copy, Eye, FileStack, Globe, Images, Megaphone, MoreHorizontal, Palette, PowerOff, Send, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppMenu from '@/components/AppMenu.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber, formatRelative } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import StartSiteForm from '../components/StartSiteForm.vue';
import { useSite } from '../useSite';

/**
 * The website at a glance: whether the public sees it, its address,
 * changes waiting to be published, and the way to pages, notices,
 * pictures and settings. Before it exists: the three steps to start it
 * (for people who may publish).
 */
const { web, resource, site, meta, canManage, canPublish, pick } = useSite();
const org = currentOrganization();

const colours = computed(() => ({ primary: site.value?.theme?.primary ?? '#0f6b4b', accent: site.value?.theme?.accent ?? '#f5b301' }));
const busy = ref(null);

async function preview() {
    // Opened at once (a click, so no popup blocker); the signed link follows.
    const tab = window.open('', '_blank');
    busy.value = 'preview';
    try {
        const { url } = (await web.preview()).data;
        if (tab) {
            tab.opener = null;
            tab.location.href = url;
        } else {
            window.location.href = url;
        }
    } catch (error) {
        tab?.close();
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function publish() {
    const first = !site.value.published_at;
    const confirmed = await confirmAction({
        title: t(first ? 'edu_website.overview.publish_first_title' : 'edu_website.overview.publish_title'),
        message: t(first ? 'edu_website.overview.publish_first_text' : 'edu_website.overview.publish_text'),
        confirmLabel: t('edu_website.overview.publish'),
    });
    if (!confirmed) return;
    await run('publish', () => web.publish(site.value.version), t('edu_website.overview.published'));
}

async function takeOffline() {
    const confirmed = await confirmAction({
        title: t('edu_website.overview.offline_title'),
        message: t('edu_website.overview.offline_text'),
        confirmLabel: t('edu_website.overview.offline'),
        danger: true,
    });
    if (!confirmed) return;
    await run('offline', () => web.offline(site.value.version), t('edu_website.overview.taken_offline'));
}

async function run(key, call, done) {
    busy.value = key;
    try {
        await call();
        toast.success(done);
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
        resource.reload();
    }
}

async function copy() {
    try {
        await navigator.clipboard.writeText(site.value.addresses.site);
        toast.success(t('edu_website.overview.copied'));
    } catch {
        toast.error(t('edu_website.overview.copy_failed'));
    }
}

const live = computed(() => site.value?.status === 'published');
const shortcuts = computed(() => [
    { to: '/website/pages', tone: 'bg-sky-100 text-sky-700 dark:bg-sky-500/15 dark:text-sky-300', icon: FileStack, title: t('edu_website.pages.title'), text: t('edu_website.overview.pages_count', { count: formatNumber(meta.value.page_count ?? 0), max: formatNumber(meta.value.max_pages?.value ?? 0) }) },
    { to: '/website/notices', tone: 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300', icon: Megaphone, title: t('edu_website.notices.title'), text: t('edu_website.overview.notices_text') },
    { to: '/website/media', tone: 'bg-violet-100 text-violet-700 dark:bg-violet-500/15 dark:text-violet-300', icon: Images, title: t('edu_website.media.title'), text: t('edu_website.overview.media_text') },
    { to: '/website/settings', tone: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300', icon: Palette, title: t('edu_website.settings.title'), text: t(`edu_website.templates.${site.value?.template ?? 'school'}`) },
]);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.overview.title')" :description="t('edu_website.overview.text')" />

        <ErrorState v-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <SkeletonRows v-else-if="!resource.data.value" :rows="4" />

        <template v-else-if="!site">
            <StartSiteForm v-if="canPublish" :web="web" :meta="meta" :organization-name="org?.name ?? ''" @started="resource.reload()" />
            <section v-else class="card">
                <EmptyState :icon="Globe" :title="t('edu_website.overview.none')" :text="t('edu_website.overview.none_text')" />
            </section>
        </template>

        <template v-else>
            <section class="card overflow-hidden" aria-labelledby="site-name">
                <div class="relative grid gap-6 overflow-hidden p-5 text-white sm:p-7 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]" :style="{ background: `linear-gradient(135deg, ${colours.primary} 0%, ${colours.primary}e6 55%, ${colours.accent}cc 140%)` }">
                    <span class="pointer-events-none absolute -end-16 -top-20 size-64 rounded-full bg-white/10" aria-hidden="true" />
                    <span class="pointer-events-none absolute -bottom-24 end-40 size-48 rounded-full bg-white/5" aria-hidden="true" />
                    <div class="relative min-w-0">
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-[12px] font-medium ring-1 ring-white/25">
                            <span class="size-2 rounded-full" :class="{ 'bg-emerald-300': live, 'bg-amber-300': site.status === 'offline', 'bg-white/60': site.status === 'draft' }" aria-hidden="true" />
                            {{ t(`edu_website.status.${site.status}`) }}
                        </span>
                        <h2 id="site-name" class="mt-3 text-[22px] font-bold leading-tight sm:text-[26px]">{{ pick(site.title) }}</h2>
                        <p v-if="pick(site.tagline)" class="mt-1 text-[14px] text-white/80">{{ pick(site.tagline) }}</p>
                        <div class="mt-3 flex min-w-0 items-center gap-1">
                            <a :href="site.addresses.site" target="_blank" rel="noopener" class="truncate rounded-md bg-black/15 px-2 py-1 font-mono text-[13px] hover:bg-black/25" dir="ltr">{{ site.addresses.site.replace(/^https?:\/\//, '') }}</a>
                            <button type="button" class="flex size-8 items-center justify-center rounded-md hover:bg-white/15" :aria-label="t('edu_website.overview.copy')" @click="copy">
                                <Copy class="size-4" aria-hidden="true" />
                            </button>
                        </div>
                        <p class="mt-2 text-[12.5px] text-white/75">
                            {{ site.published_at ? t('edu_website.overview.last_published', { when: formatRelative(site.published_at) }) : t('edu_website.overview.never_published') }}
                        </p>
                        <div class="mt-5 flex flex-wrap gap-2">
                            <button v-if="canPublish" type="button" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white px-4 text-[14px] font-semibold shadow-sm transition hover:bg-white/90 disabled:opacity-70" :style="{ color: colours.primary }" :disabled="busy === 'publish'" @click="publish">
                                <Send class="size-4" aria-hidden="true" />{{ t('edu_website.overview.publish') }}
                            </button>
                            <button v-if="canManage" type="button" class="inline-flex h-10 items-center gap-2 rounded-lg bg-white/15 px-4 text-[14px] font-medium ring-1 ring-white/30 transition hover:bg-white/25 disabled:opacity-70" :disabled="busy === 'preview'" @click="preview">
                                <Eye class="size-4" aria-hidden="true" />{{ t('edu_website.overview.preview') }}
                            </button>
                            <AppMenu
                                v-if="canPublish && live"
                                :label="t('edu_website.common.more')"
                                :items="[{ key: 'offline', label: t('edu_website.overview.offline'), icon: PowerOff, danger: true, onSelect: takeOffline }]"
                            >
                                <template #trigger="{ toggle, attrs }">
                                    <button type="button" class="flex size-10 items-center justify-center rounded-lg ring-1 ring-white/30 hover:bg-white/15" v-bind="attrs" @click="toggle(false)">
                                        <MoreHorizontal class="size-5" aria-hidden="true" />
                                    </button>
                                </template>
                            </AppMenu>
                        </div>
                    </div>

                    <!-- The site drawn small in its own colours (no picture to load). -->
                    <div class="relative hidden overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-black/10 lg:block" aria-hidden="true">
                        <div class="flex items-center gap-1.5 border-b border-gray-200 bg-gray-100 px-3 py-2">
                            <span class="size-2.5 rounded-full bg-red-400" /><span class="size-2.5 rounded-full bg-amber-400" /><span class="size-2.5 rounded-full bg-emerald-400" />
                            <span class="ms-2 truncate rounded bg-white px-2 py-0.5 font-mono text-[10px] text-gray-500">{{ site.addresses.site.replace(/^https?:\/\//, '') }}</span>
                        </div>
                        <div class="h-1.5" :style="{ background: colours.primary }" />
                        <div class="flex items-center gap-2 px-3 py-2">
                            <span class="flex size-6 items-center justify-center rounded text-[11px] font-bold text-white" :style="{ background: colours.primary }">{{ pick(site.title).slice(0, 1) }}</span>
                            <span class="truncate text-[11px] font-semibold text-gray-800">{{ pick(site.title) }}</span>
                        </div>
                        <div class="px-3 py-4" :style="site.template === 'school' ? { background: colours.primary } : { background: '#f9fafb' }">
                            <p class="truncate text-[13px] font-bold" :class="site.template === 'school' ? 'text-white' : 'text-gray-900'">{{ pick(site.title) }}</p>
                            <span class="mt-2 inline-block h-4 w-16 rounded-full" :style="{ background: site.template === 'school' ? colours.accent : colours.primary }" />
                        </div>
                        <div class="grid grid-cols-3 gap-1.5 p-3">
                            <span v-for="n in 3" :key="n" class="h-10 rounded-md" :style="site.template === 'school' ? { background: n === 2 ? colours.accent : colours.primary, opacity: 0.85 } : { background: '#f3f4f6' }" />
                        </div>
                    </div>
                </div>

                <div v-if="meta.has_changes && site.published_at" class="flex items-start gap-3 border-t border-warn/20 bg-warn-soft px-5 py-3 text-[13px] sm:px-6">
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    <p>{{ canPublish ? t('edu_website.overview.changes_waiting') : t('edu_website.overview.changes_waiting_ask') }}</p>
                </div>
                <div v-else-if="!site.published_at" class="flex items-start gap-3 border-t border-line bg-subtle px-5 py-3 text-[13px] sm:px-6">
                    <Globe class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                    <p>{{ canPublish ? t('edu_website.overview.not_live') : t('edu_website.overview.not_live_ask') }}</p>
                </div>
                <div v-else-if="site.status === 'offline'" class="flex items-start gap-3 border-t border-warn/20 bg-warn-soft px-5 py-3 text-[13px] sm:px-6">
                    <PowerOff class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                    <p>{{ t('edu_website.overview.is_offline') }}</p>
                </div>
            </section>

            <p v-if="!canManage" class="rounded-lg bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('edu_website.common.view_only') }}</p>

            <nav class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" :aria-label="t('edu_website.overview.sections')">
                <RouterLink v-for="item in shortcuts" :key="item.to" :to="item.to" class="card group flex items-start gap-3 p-4 transition hover:border-brand/40 hover:shadow-sm">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl transition group-hover:scale-105" :class="item.tone">
                        <component :is="item.icon" class="size-5" aria-hidden="true" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-[14px] font-semibold">{{ item.title }}</span>
                        <span class="block text-[12.5px] text-muted">{{ item.text }}</span>
                    </span>
                </RouterLink>
            </nav>
        </template>
    </div>
</template>
