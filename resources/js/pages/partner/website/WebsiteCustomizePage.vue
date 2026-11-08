<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { RouterLink } from 'vue-router';
import { Copy, Link2, Monitor, Palette, RefreshCw, Send, Smartphone, X } from 'lucide-vue-next';
import { AppButton, AppSegmented, PageFrame, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { t } from '@/lib/i18n';
import { formatDateTime } from '@/lib/format';
import WebsiteNav from './WebsiteNav.vue';
import { changedMeanwhile, useWebsite } from './useWebsite';

/**
 * "Customize": the whole site in a large live preview with a Customize
 * button, as in the design preview: template, slider effect and time,
 * product section style, colours. Every choice is saved to the drafts at
 * once (the same API as Settings and the page editor) and shown in the
 * preview; visitors see it after Publish. Template and colours are the
 * owner's; slider and product are the writers'. Visitors never get this
 * button: it lives in the console only.
 */
const { resource, site, choices, can, apply } = useWebsite();

// Ready-made colour pairs (main, second); "Own" picks the main colour freely.
const PALETTES = [
    { primary: '#2B4C9B', accent: '#23A562' },
    { primary: '#6D28D9', accent: '#DB2777' },
    { primary: '#047857', accent: '#65A30D' },
    { primary: '#BE123C', accent: '#D97706' },
    { primary: '#0369A1', accent: '#0D9488' },
];

const open = ref(true);
const busy = ref(null);
const device = ref('desktop');
const devices = computed(() => [
    { value: 'desktop', label: t('website.editor.desktop'), icon: Monitor },
    { value: 'phone', label: t('website.editor.phone'), icon: Smartphone },
]);

const home = computed(() => site.value?.pages?.find((page) => page.is_home) ?? null);
const firstBlock = (type) => home.value?.blocks?.find((block) => block.type === type) ?? null;
const slider = computed(() => firstBlock('hero_slider'));
const product = computed(() => firstBlock('product'));
const colors = computed(() => site.value?.settings?.colors ?? {});
const ownColor = ref('#2B4C9B');
watch(colors, (value) => { if (value?.primary) ownColor.value = value.primary; }, { immediate: true });
const paletteOn = (palette) => (colors.value.primary ?? '#2B4C9B').toUpperCase() === palette.primary && (colors.value.accent ?? '#23A562').toUpperCase() === palette.accent;

// The live preview: a signed link (an hour) to the drafts' home page.
const previewBase = ref(null);
const previewAt = ref(0);
const previewKey = ref(0);
async function refreshPreview() {
    try {
        if (!previewBase.value || Date.now() - previewAt.value > 50 * 60 * 1000) {
            previewBase.value = (await api('/api/partner/website/preview-link', { method: 'POST' })).data.url;
            previewAt.value = Date.now();
        }
        previewKey.value++;
    } catch (error) {
        toast.error(error.message);
    }
}
watch(site, (value) => { if (value && !previewBase.value) refreshPreview(); }, { immediate: true });
const previewUrl = computed(() => (previewBase.value ? `${previewBase.value}&${new URLSearchParams({ lang: site.value?.default_locale ?? 'en' })}` : null));

/** One save at a time; the answer carries the whole site, then the preview reloads. */
async function save(key, request) {
    busy.value = key;
    try {
        const response = await request();
        if (response.data?.site !== undefined) apply(response.data);
        else await resource.reload();
        refreshPreview();
    } catch (error) {
        toast.error(error.message);
        if (changedMeanwhile(error)) resource.reload();
    } finally {
        busy.value = null;
    }
}

const saveSite = (key, body) => save(key, () => api('/api/partner/website', { method: 'PATCH', body: { base_version: site.value.version, ...body } }));
const saveBlock = (key, block, changes) => save(key, () => api(`/api/partner/website/blocks/${block.id}`, {
    method: 'PATCH',
    body: { base_version: block.version, data: { ...block.data, ...changes } },
}));

function setTemplate(template) {
    if (template !== site.value.template) saveSite('template', { template });
}
function setColors(pair) {
    saveSite('colors', { settings: { ...site.value.settings, colors: { ...colors.value, ...pair } } });
}
function setEffect(effect) {
    if (slider.value && effect !== slider.value.data.effect) saveBlock('effect', slider.value, { effect });
}
// The slide time saves when the handle is let go, not at every step.
const seconds = ref(7);
watch(slider, (value) => { if (value) seconds.value = value.data.seconds; }, { immediate: true });
function setSeconds() {
    if (slider.value && Number(seconds.value) !== slider.value.data.seconds) saveBlock('seconds', slider.value, { seconds: Number(seconds.value) });
}
function setStyle(style) {
    if (product.value && style !== product.value.data.style) saveBlock('style', product.value, { style });
}

async function publish() {
    const first = site.value.status !== 'published' || !site.value.published_at;
    const ok = await confirmAction({
        title: t(first ? 'website.overview.publish_first_title' : 'website.overview.publish_title'),
        message: t(first ? 'website.overview.publish_first_text' : 'website.overview.publish_text'),
        confirmLabel: t('website.overview.publish'),
    });
    if (!ok) return;
    await save('publish', async () => {
        const response = await api('/api/partner/website/publish', { method: 'POST' });
        toast.success(response.message);
        return response;
    });
}

// A look link (owner): the public site with this panel for whoever holds it; the link is shown once.
const lookUrl = ref(null);
// The server checks the address (the platform's own site may have none set but still be served).
const live = computed(() => site.value?.status === 'published');
async function makeLook() {
    busy.value = 'look';
    try {
        const response = await api('/api/partner/website/look-link', { method: 'POST' });
        lookUrl.value = response.data.url;
        site.value.look_until = response.data.until;
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
async function copyLook() {
    try {
        await navigator.clipboard.writeText(lookUrl.value);
        toast.success(t('website.customize.share_copied'));
    } catch {
        toast.error(lookUrl.value);
    }
}
async function endLook() {
    const ok = await confirmAction({ title: t('website.customize.share_end'), message: t('website.customize.share_end_confirm'), confirmLabel: t('website.customize.share_end'), danger: true });
    if (!ok) return;
    busy.value = 'look';
    try {
        toast.success((await api('/api/partner/website/look-link', { method: 'DELETE' })).message);
        lookUrl.value = null;
        site.value.look_until = null;
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

function onKey(event) {
    if (event.key === 'Escape') open.value = false;
}
window.addEventListener('keydown', onKey);
onBeforeUnmount(() => window.removeEventListener('keydown', onKey));

const option = 'rounded-lg border px-2 py-2 text-[12.5px] font-medium transition disabled:cursor-not-allowed disabled:opacity-50';
const optionClass = (on) => [option, on ? 'border-brand bg-brand text-brand-fg' : 'border-line bg-subtle/60 text-fg hover:bg-subtle'];
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.customize.text')" :resource="resource" width="full">
        <WebsiteNav />

        <div v-if="site" class="card relative overflow-hidden">
            <div class="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2">
                <AppSegmented v-model="device" size="sm" icon-only :label="t('website.editor.device')" :options="devices" />
                <span class="flex-1" />
                <span class="text-[12px] text-muted">{{ t('website.customize.drafts') }}</span>
                <AppButton size="sm" variant="ghost" :icon="RefreshCw" :aria-label="t('website.editor.reload')" @click="refreshPreview" />
                <AppButton v-if="can.publish" size="sm" variant="primary" :icon="Send" :loading="busy === 'publish'" @click="publish">{{ t('website.overview.publish') }}</AppButton>
            </div>

            <div class="grid place-items-center bg-subtle/60 p-2">
                <iframe
                    v-if="previewUrl"
                    :key="previewKey"
                    :src="previewUrl"
                    :title="t('website.editor.preview_title')"
                    class="h-[78vh] rounded-lg border border-line bg-white transition-[width]"
                    :class="device === 'phone' ? 'w-[390px] max-w-full' : 'w-full'"
                    referrerpolicy="no-referrer"
                />
                <p v-else class="p-10 text-[13px] text-muted">{{ t('core.states.loading') }}</p>
            </div>

            <!-- The Customize button and its panel, over the preview -->
            <div class="absolute bottom-4 end-4 z-10 flex flex-col items-end gap-3">
                <section
                    v-if="open"
                    id="customize-panel"
                    class="max-h-[68vh] w-[min(320px,calc(100vw-48px))] overflow-auto rounded-2xl border border-line bg-surface p-4 shadow-2xl"
                    :aria-label="t('website.customize.button')"
                >
                    <div class="mb-3 flex items-center justify-between">
                        <h2 class="text-[13px] font-semibold text-fg">{{ t('website.customize.button') }}</h2>
                        <button type="button" class="rounded-md p-1 text-muted hover:bg-subtle" :aria-label="t('core.actions.close')" @click="open = false">
                            <X class="size-4" aria-hidden="true" />
                        </button>
                    </div>

                    <!-- Template -->
                    <h3 class="mb-1.5 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('website.customize.template') }}</h3>
                    <div class="grid grid-cols-3 gap-1.5">
                        <button v-for="template in choices.templates" :key="template" type="button" :class="optionClass(site.template === template)" :disabled="!can.settings || !!busy" :aria-pressed="site.template === template" @click="setTemplate(template)">
                            {{ t(`website.templates.${template}`) }}
                        </button>
                    </div>

                    <!-- Slider -->
                    <h3 class="mt-4 mb-1.5 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('website.customize.effect') }}</h3>
                    <template v-if="slider">
                        <div class="grid grid-cols-3 gap-1.5">
                            <button v-for="effect in choices.effects" :key="effect" type="button" :class="optionClass(slider.data.effect === effect)" :disabled="!can.write || !!busy" :aria-pressed="slider.data.effect === effect" @click="setEffect(effect)">
                                {{ t(`website.options.${effect}`) }}
                            </button>
                        </div>
                        <label class="mt-3 block text-[11px] font-semibold tracking-wide text-muted uppercase" for="slide-seconds">
                            {{ t('website.customize.seconds', { n: seconds }) }}
                        </label>
                        <input id="slide-seconds" v-model="seconds" type="range" min="3" max="15" step="1" class="mt-1 w-full accent-brand" :disabled="!can.write || !!busy" @change="setSeconds" />
                    </template>
                    <p v-else class="text-[12px] text-muted">
                        {{ t('website.customize.no_section', { section: t('website.types.hero_slider') }) }}
                        <RouterLink v-if="home" :to="`/partner/website/pages/${home.id}`" class="font-medium text-brand-text hover:underline">{{ t('website.customize.add_it') }}</RouterLink>
                    </p>

                    <!-- Product section -->
                    <h3 class="mt-4 mb-1.5 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('website.customize.product') }}</h3>
                    <div v-if="product" class="grid grid-cols-3 gap-1.5">
                        <button v-for="style in choices.product_styles" :key="style" type="button" :class="optionClass(product.data.style === style)" :disabled="!can.write || !!busy" :aria-pressed="product.data.style === style" @click="setStyle(style)">
                            {{ t(`website.options.${style}`) }}
                        </button>
                    </div>
                    <p v-else class="text-[12px] text-muted">
                        {{ t('website.customize.no_section', { section: t('website.types.product') }) }}
                        <RouterLink v-if="home" :to="`/partner/website/pages/${home.id}`" class="font-medium text-brand-text hover:underline">{{ t('website.customize.add_it') }}</RouterLink>
                    </p>

                    <!-- Colours -->
                    <h3 class="mt-4 mb-1.5 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('website.customize.colors') }}</h3>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-for="(palette, index) in PALETTES"
                            :key="palette.primary"
                            type="button"
                            class="size-8 rounded-lg border-2 transition disabled:opacity-50"
                            :class="paletteOn(palette) ? 'border-fg' : 'border-transparent'"
                            :style="{ background: `linear-gradient(135deg, ${palette.primary} 50%, ${palette.accent} 50%)` }"
                            :aria-label="t('website.customize.palette', { n: index + 1 })"
                            :aria-pressed="paletteOn(palette)"
                            :disabled="!can.settings || !!busy"
                            @click="setColors(palette)"
                        />
                        <label class="flex items-center gap-1.5 text-[12px] text-muted">
                            {{ t('website.customize.own') }}
                            <input v-model="ownColor" type="color" class="size-8 cursor-pointer rounded border border-line" :disabled="!can.settings || !!busy" @change="setColors({ primary: ownColor.toUpperCase() })" />
                        </label>
                    </div>

                    <!-- Show it to someone: a look link (owner) -->
                    <template v-if="can.settings">
                        <h3 class="mt-4 mb-1.5 text-[11px] font-semibold tracking-wide text-muted uppercase">{{ t('website.customize.share_title') }}</h3>
                        <p class="text-[12px] leading-relaxed text-muted">{{ t('website.customize.share_text') }}</p>
                        <p v-if="!live" class="mt-2 text-[12px] text-muted">{{ t('website.customize.share_needs_live') }}</p>
                        <template v-else>
                            <div v-if="lookUrl" class="mt-2 flex items-center gap-1.5">
                                <input :value="lookUrl" readonly class="field-input h-8 min-w-0 flex-1 text-[12px]" :aria-label="t('website.customize.share_title')" @focus="$event.target.select()" />
                                <AppButton size="sm" variant="secondary" :icon="Copy" @click="copyLook">{{ t('website.customize.share_copy') }}</AppButton>
                            </div>
                            <p v-if="lookUrl" class="mt-1 text-[11.5px] text-warn">{{ t('website.customize.share_once') }}</p>
                            <p v-if="site.look_until" class="mt-1 text-[11.5px] text-muted">{{ t('website.customize.share_until', { date: formatDateTime(site.look_until) }) }}</p>
                            <div class="mt-2 flex flex-wrap gap-1.5">
                                <AppButton size="sm" variant="secondary" :icon="Link2" :loading="busy === 'look'" :disabled="!!busy" @click="makeLook">{{ t(site.look_until ? 'website.customize.share_new' : 'website.customize.share_make') }}</AppButton>
                                <AppButton v-if="site.look_until" size="sm" variant="ghost" :disabled="!!busy" @click="endLook">{{ t('website.customize.share_end') }}</AppButton>
                            </div>
                        </template>
                    </template>

                    <p class="mt-4 text-[11.5px] leading-relaxed text-muted">
                        {{ can.settings ? t('website.customize.note') : t('website.customize.note_writer') }}
                        <RouterLink to="/partner/website/settings" class="font-medium text-brand-text hover:underline">{{ t('website.nav.settings') }}</RouterLink>
                    </p>
                </section>

                <button
                    type="button"
                    class="flex items-center gap-2 rounded-full bg-fg px-5 py-3 text-[14px] font-semibold text-canvas shadow-xl hover:bg-fg/90"
                    :aria-expanded="open"
                    aria-controls="customize-panel"
                    @click="open = !open"
                >
                    <Palette class="size-4" aria-hidden="true" />{{ t('website.customize.button') }}
                </button>
            </div>
        </div>
        <p v-else-if="resource.data.value" class="card p-6 text-[13px] text-muted">
            {{ t('website.customize.no_site') }}
            <RouterLink to="/partner/website" class="font-medium text-brand-text hover:underline">{{ t('website.nav.overview') }}</RouterLink>
        </p>
    </PageFrame>
</template>
