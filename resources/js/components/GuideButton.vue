<script setup>
import { computed, defineAsyncComponent, ref } from 'vue';
import { CircleHelp } from 'lucide-vue-next';
import { guideSeen, hasGuide, markGuideSeen } from '@/lib/guide';
import { t } from '@/lib/i18n';

/**
 * "ⓘ How to?" at the inline-end of a page header. PageFrame shows it from its
 * `guide` prop; a full-screen tool (the till) places it in its own bar.
 *
 *   <GuideButton guide="pos.guide.sell" :settings="{ name: 'module-settings', params: { module: 'pos' } }" />
 *
 * The drawer loads on the first click, so the guide adds nothing to the first load.
 */
const props = defineProps({
    guide: { type: String, default: '' },
    // Where this page's settings live (a route), and a partner's video (https only).
    settings: { type: [Object, String], default: null },
    video: { type: String, default: '' },
    // On a dark bar (the till).
    onDark: Boolean,
});

const GuideDrawer = defineAsyncComponent(() => import('@/components/GuideDrawer.vue'));

const open = ref(false);
const loaded = ref(false);
const seen = ref(props.guide ? guideSeen(props.guide) : true);
const shown = computed(() => hasGuide(props.guide));

function show() {
    loaded.value = true;
    open.value = true;
    if (!seen.value) {
        markGuideSeen(props.guide);
        seen.value = true;
    }
}
</script>

<template>
    <template v-if="shown">
        <button
            type="button"
            class="relative inline-flex h-8 items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-medium transition print:hidden"
            :class="onDark ? 'text-side-fg hover:bg-white/10' : 'border border-line bg-raised text-fg hover:bg-subtle'"
            aria-haspopup="dialog"
            :aria-expanded="open ? 'true' : 'false'"
            data-guide-button
            @click="show"
        >
            <CircleHelp class="size-4" aria-hidden="true" />
            <span>{{ t('core.guide.button') }}</span>
            <span v-if="!seen" class="absolute -end-0.5 -top-0.5 flex size-2.5" data-guide-pulse aria-hidden="true">
                <span class="absolute inline-flex size-full animate-ping rounded-full bg-brand opacity-70 motion-reduce:animate-none" />
                <span class="relative inline-flex size-2.5 rounded-full bg-brand" />
            </span>
        </button>
        <GuideDrawer v-if="loaded" :open="open" :guide="guide" :settings="settings" :video="video" @close="open = false" />
    </template>
</template>
