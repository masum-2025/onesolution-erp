<script setup>
import { computed } from 'vue';
import { Check, Lock } from 'lucide-vue-next';
import { t } from '@/lib/i18n';

/**
 * A template to choose, drawn small in the site's colours (no pictures to
 * load): "school" has a coloured band and a bold welcome, "minimal" stays
 * white with colour only on links and buttons, "premium" is deep ink with
 * the accent as gold, "campus" is deep blue with straight lines, "madrasa"
 * is green with a geometric band and arched cards. A template a level above
 * turned off shows why and cannot be chosen.
 */
const props = defineProps({
    template: { type: String, required: true },
    selected: Boolean,
    offered: { type: Boolean, default: true },
    primary: { type: String, default: '#0f6b4b' },
    accent: { type: String, default: '#f5b301' },
    lockedBy: { type: String, default: '' },
});
const emit = defineEmits(['select']);

// The parts of the small drawing, per template.
const look = computed(() => {
    if (props.template === 'premium') {
        const ink = `color-mix(in oklab, ${props.primary} 42%, #070d1a)`;
        return {
            page: '#fbf9f4', strip: ink, active: props.accent, hero: { background: ink }, line1: '#f8f5ec', line2: props.accent, button: props.accent,
            card: () => ({ background: '#ffffff', borderTop: `2px solid ${props.accent}` }),
        };
    }
    if (props.template === 'campus') {
        const ink = `color-mix(in oklab, ${props.primary} 55%, #06122b)`;
        return {
            page: '#f2f5fa', strip: ink, active: ink, hero: { background: `repeating-linear-gradient(135deg, rgba(255,255,255,.06) 0 2px, transparent 2px 8px), ${ink}` }, line1: '#ffffff', line2: props.accent, button: '#ffffff',
            card: () => ({ background: '#ffffff', borderLeft: `3px solid ${props.primary}`, borderRadius: '2px' }),
        };
    }
    if (props.template === 'madrasa') {
        const ink = `color-mix(in oklab, ${props.primary} 50%, #022b1c)`;
        return {
            page: '#fbfaf5', strip: props.accent, active: ink, hero: { background: `repeating-conic-gradient(from 45deg, rgba(255,255,255,.08) 0 25%, transparent 0 50%) 0 0/10px 10px, ${ink}` }, line1: '#ffffff', line2: props.accent, button: props.accent,
            card: () => ({ background: '#ffffff', borderTop: `2px solid ${props.accent}`, borderRadius: '12px 12px 3px 3px' }),
        };
    }
    if (props.template === 'minimal') {
        return {
            page: '#ffffff', strip: '#e5e7eb', active: props.primary, hero: { background: '#f9fafb' }, line1: '#111827', line2: '#9ca3af', button: props.primary,
            card: () => ({ background: '#f3f4f6' }),
        };
    }
    return {
        page: '#ffffff', strip: props.primary, active: '#d1d5db', hero: { background: props.primary }, line1: '#ffffff', line2: 'rgba(255,255,255,.6)', button: props.accent,
        card: (n) => ({ background: n === 2 ? props.accent : props.primary, opacity: 0.85 }),
    };
});
</script>

<template>
    <button
        type="button"
        class="group flex w-full flex-col overflow-hidden rounded-xl border bg-surface text-start outline-none transition focus-visible:ring-2 focus-visible:ring-brand disabled:cursor-not-allowed disabled:opacity-60"
        :class="selected ? 'border-brand ring-2 ring-brand' : 'border-line hover:border-line-strong hover:shadow-sm'"
        :disabled="!offered"
        :aria-pressed="selected"
        @click="emit('select', template)"
    >
        <span class="block border-b border-line bg-subtle p-3" aria-hidden="true">
            <span class="block overflow-hidden rounded-md shadow-sm ring-1 ring-black/5" :style="{ background: look.page }">
                <span class="block h-1.5" :style="{ background: look.strip }" />
                <span class="flex items-center gap-1.5 px-2 py-1.5">
                    <span class="size-3 rounded" :style="{ background: template === 'premium' ? look.strip : primary }" />
                    <span class="h-1.5 w-12 rounded-full bg-gray-300" />
                    <span class="ms-auto h-1.5 w-6 rounded-full" :style="{ background: look.active }" />
                    <span class="h-1.5 w-6 rounded-full bg-gray-200" />
                </span>
                <span class="block px-2 py-3" :style="look.hero">
                    <span class="block h-2 w-3/4 rounded-full" :style="{ background: look.line1 }" />
                    <span class="mt-1.5 block h-1.5 w-1/2 rounded-full" :style="{ background: look.line2, height: template === 'premium' ? '1px' : undefined }" />
                    <span class="mt-2 block h-2.5 w-10" :class="template === 'premium' ? 'rounded-sm' : 'rounded-full'" :style="{ background: look.button }" />
                </span>
                <span class="grid grid-cols-3 gap-1 p-2">
                    <span v-for="n in 3" :key="n" class="h-5 rounded" :style="look.card(n)" />
                </span>
            </span>
        </span>
        <span class="flex items-start gap-2 p-3">
            <span class="min-w-0 flex-1">
                <span class="block text-[14px] font-semibold">{{ t(`edu_website.templates.${template}`) }}</span>
                <span class="block text-[12.5px] text-muted">{{ t(`edu_website.templates.${template}_text`) }}</span>
                <span v-if="!offered" class="mt-1 flex items-center gap-1 text-[12px] text-warn">
                    <Lock class="size-3.5" aria-hidden="true" />{{ lockedBy ? t('edu_website.templates.off_by', { name: lockedBy }) : t('edu_website.templates.off') }}
                </span>
            </span>
            <span v-if="selected" class="flex size-6 shrink-0 items-center justify-center rounded-full bg-brand text-white"><Check class="size-4" aria-hidden="true" /></span>
        </span>
    </button>
</template>
