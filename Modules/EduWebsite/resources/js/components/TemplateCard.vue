<script setup>
import { computed } from 'vue';
import { Check, Lock } from 'lucide-vue-next';
import { t } from '@/lib/i18n';

/**
 * A template to choose, drawn small in the site's colours (no pictures to
 * load): "school" has a coloured band and a bold welcome, "minimal" stays
 * white with colour only on links and buttons. A template a level above
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

const school = computed(() => props.template === 'school');
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
            <span class="block overflow-hidden rounded-md bg-white shadow-sm ring-1 ring-black/5">
                <span class="block h-1.5" :style="{ background: school ? primary : '#e5e7eb' }" />
                <span class="flex items-center gap-1.5 px-2 py-1.5">
                    <span class="size-3 rounded" :style="{ background: primary }" />
                    <span class="h-1.5 w-12 rounded-full bg-gray-300" />
                    <span class="ms-auto h-1.5 w-6 rounded-full" :style="{ background: school ? '#d1d5db' : primary }" />
                    <span class="h-1.5 w-6 rounded-full bg-gray-200" />
                </span>
                <span class="block px-2 py-3" :style="school ? { background: primary } : { background: '#f9fafb' }">
                    <span class="block h-2 w-3/4 rounded-full" :style="{ background: school ? '#ffffff' : '#111827' }" />
                    <span class="mt-1.5 block h-1.5 w-1/2 rounded-full" :style="{ background: school ? 'rgba(255,255,255,.6)' : '#9ca3af' }" />
                    <span class="mt-2 block h-2.5 w-10 rounded-full" :style="{ background: school ? accent : primary }" />
                </span>
                <span class="grid grid-cols-3 gap-1 p-2">
                    <span v-for="n in 3" :key="n" class="h-5 rounded" :style="school ? { background: n === 2 ? accent : primary, opacity: 0.85 } : { background: '#f3f4f6' }" />
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
