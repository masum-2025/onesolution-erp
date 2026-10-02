<script setup>
import { computed, ref } from 'vue';
import { Check, CircleAlert, CircleCheck, Lock, Monitor, Moon, RotateCcw, Sun, TriangleAlert } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { ACCENTS, accentColor, allowedChoices, appearance, applyAppearance, isLocked, saveAppearance, TEMPLATES } from '@/lib/appearance';
import { readableOn } from '@/lib/brand';
import { loadMe } from '@/lib/session';
import { setTheme, theme } from '@/lib/theme';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * "My look": the person's own template, highlight color and readability.
 * Template and color may be locked by the organization (shown with where the
 * lock comes from); colour vision and contrast are always the person's.
 * A choice shows at once and is saved; the server's answer then applies.
 */
const saving = ref(null);
const details = computed(() => appearance.details);
const accents = computed(() => ['brand', ...Object.keys(ACCENTS)]);

function source(setting) {
    const detail = details.value?.[setting];
    if (!detail) return null;
    if (detail.source === 'user') return { kind: 'self' };
    if (detail.source === 'default') return { kind: 'default' };
    return { kind: 'inherited', name: detail.name, level: detail.level };
}

function lockText(setting) {
    const detail = details.value?.[setting];
    const name = detail?.level === 'platform' || !detail?.name ? t(`core.levels.${detail?.level ?? 'platform'}`) : detail.name;
    return t('appearance.locked_by', { name });
}

const canPick = (setting, value) => !isLocked(setting) && allowedChoices(setting).includes(value);

async function choose(setting, value) {
    if (saving.value) return;
    const before = { ...appearance };
    saving.value = setting;
    // Show it at once; the server's answer (which may differ under a lock) follows.
    applyAppearance({ ...before, details: undefined, [setting]: value });
    try {
        const message = await saveAppearance({ [setting]: value });
        await loadMe();
        toast.success(message);
    } catch (error) {
        applyAppearance({ ...before, details: undefined });
        toast.error(error.field?.(setting) ?? error.message);
    } finally {
        saving.value = null;
    }
}

const hasOwnChoice = computed(() => !!(details.value?.template?.own || details.value?.accent?.own));

async function reset() {
    saving.value = 'reset';
    try {
        const message = await saveAppearance({ template: null, accent: null });
        await loadMe();
        toast.success(message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        saving.value = null;
    }
}

const visionOptions = computed(() => [
    { value: 'standard', title: t('appearance.vision.standard'), text: t('appearance.vision.standard_text') },
    { value: 'blue_orange', title: t('appearance.vision.blue_orange'), text: t('appearance.vision.blue_orange_text') },
]);

const contrastOptions = computed(() => [
    { value: 'standard', label: t('appearance.contrast.standard') },
    { value: 'high', label: t('appearance.contrast.high') },
]);

const themeOptions = computed(() => [
    { value: 'light', label: t('core.theme.light'), icon: Sun },
    { value: 'dark', label: t('core.theme.dark'), icon: Moon },
    { value: 'system', label: t('core.theme.system'), icon: Monitor },
]);
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('appearance.title')" :description="t('appearance.description')">
            <template v-if="hasOwnChoice" #actions>
                <AppButton :icon="RotateCcw" :loading="saving === 'reset'" @click="reset">{{ t('appearance.reset') }}</AppButton>
            </template>
        </PageHeader>

        <!-- Template -->
        <section class="card p-5 sm:p-6" aria-labelledby="look-template">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="look-template" class="text-[15px] font-semibold text-fg">{{ t('appearance.template.title') }}</h2>
                    <p class="mt-1 text-[13px] text-muted">{{ t('appearance.template.text') }}</p>
                </div>
                <AppBadge v-if="isLocked('template')" tone="neutral" :icon="Lock">{{ lockText('template') }}</AppBadge>
                <SourceBadge v-else-if="source('template')" v-bind="source('template')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2" role="radiogroup" :aria-labelledby="'look-template'">
                <button
                    v-for="template in TEMPLATES"
                    :key="template"
                    type="button"
                    role="radio"
                    :aria-checked="appearance.template === template"
                    :disabled="!canPick('template', template) || !!saving"
                    class="group relative rounded-2xl border p-3 text-start transition disabled:cursor-not-allowed disabled:opacity-55"
                    :class="appearance.template === template ? 'border-brand ring-4 ring-brand/15' : 'border-line hover:border-line-strong hover:shadow-pop'"
                    @click="choose('template', template)"
                >
                    <!-- Miniature of the shell in this template -->
                    <div class="flex h-36 overflow-hidden rounded-xl border border-line bg-canvas" :data-shell="template" aria-hidden="true">
                        <div class="shell-side flex w-[34%] flex-col gap-1.5 p-2.5">
                            <div class="mb-1.5 flex items-center gap-1.5">
                                <span class="brand-mark size-4 rounded-[5px]" />
                                <span class="h-1.5 w-10 rounded-full bg-side-fg/70" />
                            </div>
                            <span class="h-5 rounded-md bg-side-active shadow-[inset_0_0_0_1px_var(--side-ring)]" />
                            <span v-for="n in 4" :key="n" class="ms-1 h-1.5 rounded-full bg-side-muted/50" :class="n % 2 ? 'w-14' : 'w-10'" />
                        </div>
                        <div class="flex flex-1 flex-col gap-2 p-2.5">
                            <div class="flex items-center gap-1.5">
                                <span class="h-4 flex-1 rounded-md border border-line bg-surface" />
                                <span class="h-4 w-8 rounded-md bg-brand" />
                                <span class="size-4 rounded-md border border-line bg-surface" />
                            </div>
                            <div class="grid flex-1 grid-cols-3 gap-1.5">
                                <span v-for="n in 3" :key="n" class="rounded-md border border-line bg-surface" />
                            </div>
                            <span class="h-8 rounded-md border border-line bg-surface" />
                        </div>
                    </div>
                    <div class="mt-3 flex items-center gap-2 px-1">
                        <span class="text-[14px] font-semibold text-fg">{{ t(`appearance.template.${template}`) }}</span>
                        <span class="text-[12.5px] text-muted">· {{ t(`appearance.template.${template}_text`) }}</span>
                        <span
                            v-if="appearance.template === template"
                            class="ms-auto grid size-6 place-items-center rounded-full bg-brand text-brand-fg"
                            aria-hidden="true"
                        >
                            <Check class="size-3.5" />
                        </span>
                    </div>
                </button>
            </div>
            <p v-if="isLocked('template')" class="mt-3 text-[13px] text-muted">{{ t('appearance.locked_help') }}</p>
        </section>

        <!-- Highlight color -->
        <section class="card p-5 sm:p-6" aria-labelledby="look-accent">
            <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 id="look-accent" class="text-[15px] font-semibold text-fg">{{ t('appearance.accent.title') }}</h2>
                    <p class="mt-1 text-[13px] text-muted">{{ t('appearance.accent.text') }}</p>
                </div>
                <AppBadge v-if="isLocked('accent')" tone="neutral" :icon="Lock">{{ lockText('accent') }}</AppBadge>
                <SourceBadge v-else-if="source('accent')" v-bind="source('accent')" />
            </div>

            <div class="grid grid-cols-3 gap-2.5 sm:grid-cols-5 lg:grid-cols-9" role="radiogroup" aria-labelledby="look-accent">
                <button
                    v-for="accent in accents"
                    :key="accent"
                    type="button"
                    role="radio"
                    :aria-checked="appearance.accent === accent"
                    :disabled="!canPick('accent', accent) || !!saving"
                    class="flex flex-col items-center gap-2 rounded-xl border p-2.5 transition disabled:cursor-not-allowed disabled:opacity-45"
                    :class="appearance.accent === accent ? 'border-brand bg-brand-soft' : 'border-line hover:border-line-strong'"
                    @click="choose('accent', accent)"
                >
                    <span
                        class="grid size-9 place-items-center rounded-full shadow-[inset_0_1px_0_rgb(255_255_255/0.25),0_4px_10px_-4px_rgb(0_0_0/0.4)]"
                        :style="{ background: accentColor(accent), color: readableOn(accentColor(accent)) }"
                        aria-hidden="true"
                    >
                        <Check v-if="appearance.accent === accent" class="size-4" />
                    </span>
                    <!-- The name is always written: colors are never told apart by color alone. -->
                    <span class="text-[12.5px] font-medium text-fg-2">{{ t(`appearance.accent.${accent}`) }}</span>
                </button>
            </div>
        </section>

        <!-- Readability: always the person's own -->
        <section class="card p-5 sm:p-6" aria-labelledby="look-readability">
            <h2 id="look-readability" class="text-[15px] font-semibold text-fg">{{ t('appearance.readability.title') }}</h2>
            <p class="mt-1 text-[13px] text-muted">{{ t('appearance.readability.text') }}</p>

            <h3 class="mt-5 mb-2.5 text-[13px] font-semibold text-fg-2">{{ t('appearance.vision.title') }}</h3>
            <div class="grid gap-3 sm:grid-cols-2" role="radiogroup" :aria-label="t('appearance.vision.title')">
                <button
                    v-for="option in visionOptions"
                    :key="option.value"
                    type="button"
                    role="radio"
                    :aria-checked="appearance.color_vision === option.value"
                    :disabled="!!saving"
                    class="rounded-xl border p-4 text-start transition"
                    :class="appearance.color_vision === option.value ? 'border-brand ring-4 ring-brand/15' : 'border-line hover:border-line-strong'"
                    @click="choose('color_vision', option.value)"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="text-[14px] font-semibold text-fg">{{ option.title }}</span>
                        <Check v-if="appearance.color_vision === option.value" class="size-4 text-brand-text" aria-hidden="true" />
                    </span>
                    <span class="mt-1 block text-[12.5px] text-muted">{{ option.text }}</span>
                    <!-- How states look with these colors; each has an icon and words too. -->
                    <span class="mt-3 flex flex-wrap gap-1.5" :data-vision="option.value">
                        <AppBadge tone="ok" :icon="CircleCheck">{{ t('appearance.vision.sample_ok') }}</AppBadge>
                        <AppBadge tone="warn" :icon="TriangleAlert">{{ t('appearance.vision.sample_warn') }}</AppBadge>
                        <AppBadge tone="bad" :icon="CircleAlert">{{ t('appearance.vision.sample_bad') }}</AppBadge>
                    </span>
                </button>
            </div>

            <div class="mt-6 grid gap-6 sm:grid-cols-2">
                <div>
                    <h3 class="mb-2.5 text-[13px] font-semibold text-fg-2">{{ t('appearance.contrast.title') }}</h3>
                    <AppSegmented
                        :model-value="appearance.contrast"
                        :options="contrastOptions"
                        :label="t('appearance.contrast.title')"
                        block
                        @update:model-value="(value) => choose('contrast', value)"
                    />
                </div>
                <div>
                    <h3 class="mb-2.5 text-[13px] font-semibold text-fg-2">{{ t('appearance.theme.title') }}</h3>
                    <AppSegmented :model-value="theme.preference" :options="themeOptions" :label="t('appearance.theme.title')" block @update:model-value="setTheme" />
                    <p class="mt-2 text-[12.5px] text-muted">{{ t('appearance.theme.device') }}</p>
                </div>
            </div>
        </section>
    </div>
</template>
