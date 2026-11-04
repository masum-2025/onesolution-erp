<script setup>
import { computed } from 'vue';
import { brand } from '@/lib/brand';
import { t } from '@/lib/i18n';
import { PAPER } from '../lib';
import LetterText from './LetterText.vue';

/**
 * One letter on paper at its real size: the letterhead (the company's name
 * and logo, its own letterhead image, or room for printed paper), then the
 * blocks in order, signatures with their images. The same drawing serves
 * the editor's preview, the printed letter, "my letters" and the portal.
 * The paper stays white with dark ink in the dark theme too.
 */
const props = defineProps({
    content: { type: Object, required: true },
    images: { type: Object, default: () => ({}) },
    revoked: { type: Boolean, default: false },
});

const size = computed(() => PAPER[props.content.page?.size] ?? PAPER.A4);
const margin = computed(() => props.content.page?.margin_mm ?? 20);
const head = computed(() => props.content.letterhead ?? {});
const blocks = computed(() => (props.content.blocks ?? []).filter((block) => block.type !== 'paragraph' || String(block.text ?? '').trim() !== ''));
const align = (value) => ({ start: 'text-start', center: 'text-center', end: 'text-end', justify: 'text-justify' })[value] ?? 'text-start';
const justify = (value) => ({ center: 'items-center', end: 'items-end' })[value] ?? 'items-start';
const space = { sm: '4mm', md: '8mm', lg: '16mm' };
</script>

<template>
    <article
        class="letter-paper relative mx-auto box-border bg-white text-[13.5px] leading-relaxed text-[#1b1b1f] shadow-sm ring-1 ring-black/5 print:shadow-none print:ring-0"
        :style="{ width: `${size[0]}mm`, minHeight: `${size[1]}mm`, padding: `${margin}mm` }"
    >
        <!-- Letterhead. -->
        <header v-if="head.style === 'image' && images[head.image_id]" class="mb-6">
            <img :src="images[head.image_id]" alt="" class="w-full" />
        </header>
        <header v-else-if="head.style === 'none'" class="mb-6" style="height: 28mm" aria-hidden="true" />
        <header v-else class="mb-6 flex flex-col gap-1 border-b border-[#d4d4d8] pb-4" :class="[justify(head.align), align(head.align)]">
            <img v-if="head.logo !== false && brand.logo_url" :src="brand.logo_url" alt="" class="h-12 w-auto" />
            <div class="text-[19px] font-semibold tracking-wide">{{ head.company }}</div>
            <div v-if="head.address" class="whitespace-pre-line text-[12px] text-[#52525b]">{{ head.address }}</div>
        </header>

        <!-- Body. -->
        <template v-for="(block, index) in blocks" :key="index">
            <h2 v-if="block.type === 'heading'" class="mb-3 font-semibold" :class="[align(block.align), block.level === 1 ? 'text-[18px] tracking-wide' : 'text-[15px]']">
                <LetterText :text="block.text" />
            </h2>
            <p v-else-if="block.type === 'paragraph'" class="mb-3" :class="align(block.align)"><LetterText :text="block.text" /></p>
            <component :is="block.ordered ? 'ol' : 'ul'" v-else-if="block.type === 'list'" class="mb-3 space-y-1 ps-6" :class="block.ordered ? 'list-decimal' : 'list-disc'">
                <li v-for="(item, at) in block.items" :key="at"><LetterText :text="item" /></li>
            </component>
            <table v-else-if="block.type === 'table'" class="mb-4 w-full border-collapse text-[13px]">
                <tbody>
                    <tr v-for="(row, at) in block.rows" :key="at" class="border-b border-[#e4e4e7] last:border-b-2 last:border-[#a1a1aa] last:font-semibold">
                        <td class="py-1.5 pe-4"><LetterText :text="row[0]" /></td>
                        <td class="py-1.5 text-end tabular-nums"><LetterText :text="row[1]" /></td>
                    </tr>
                </tbody>
            </table>
            <div v-else-if="block.type === 'spacer'" :style="{ height: space[block.size] ?? space.md }" aria-hidden="true" />
            <div v-else-if="block.type === 'signature'" class="mt-2 mb-4 flex flex-col" :class="[justify(block.align), align(block.align)]">
                <div class="relative flex h-16 items-end gap-2">
                    <img v-if="images[block.image_id]" :src="images[block.image_id]" alt="" class="max-h-16 w-auto" />
                    <img v-if="images[block.stamp_id]" :src="images[block.stamp_id]" alt="" class="max-h-20 w-auto opacity-90" />
                </div>
                <div class="mt-1 w-56 border-t border-[#71717a] pt-1">
                    <div v-if="block.name" class="font-semibold"><LetterText :text="block.name" /></div>
                    <div v-if="block.title" class="text-[12.5px] text-[#52525b]"><LetterText :text="block.title" /></div>
                </div>
            </div>
            <div v-else-if="block.type === 'page_break'" class="letter-break my-6 border-t border-dashed border-[#d4d4d8] print:my-0 print:border-0" :aria-label="t('letters.paper.page_break')" />
        </template>

        <div v-if="revoked" class="pointer-events-none absolute inset-0 grid place-items-center" aria-hidden="true">
            <span class="-rotate-[24deg] rounded-xl border-4 border-[#dc2626]/60 px-8 py-2 text-[56px] font-bold tracking-[0.2em] text-[#dc2626]/60">{{ t('letters.paper.revoked') }}</span>
        </div>
    </article>
</template>

<style scoped>
@media print {
    .letter-paper {
        margin: 0;
        box-shadow: none;
    }
    .letter-break {
        break-after: page;
    }
}
</style>
