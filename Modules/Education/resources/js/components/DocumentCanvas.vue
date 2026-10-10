<script setup>
import { computed } from 'vue';
import { ImageOff, QrCode, UserRound } from 'lucide-vue-next';
import { fillText, mm, pt } from '../lib';

/**
 * A design drawn at its real size in millimetres (so it prints true to
 * size), shrunk or grown on screen with `scale` (on-screen pixels per
 * millimetre). Texts show their {placeholders} filled from `values`, or as
 * written while designing. Coordinates are left to right whatever the
 * language of the page; each text keeps its own direction.
 *
 * In the designer (`editable`) a pointer on an item tells the parent, which
 * moves or resizes it.
 */
const props = defineProps({
    pageSize: { type: Array, required: true }, // [width, height] in tenths of a millimetre
    layout: { type: Object, required: true },
    values: { type: Object, default: null },
    assetLinks: { type: Object, default: () => ({}) },
    photoUrl: { type: String, default: null },
    qr: { type: String, default: null },
    scale: { type: Number, default: null }, // null: real size (printing)
    editable: Boolean,
    selected: { type: String, default: null },
});
const emit = defineEmits(['press', 'press-handle', 'select-page']);

// 1 mm in CSS pixels (96 per inch).
const PX_PER_MM = 96 / 25.4;
const factor = computed(() => (props.scale ? props.scale / PX_PER_MM : 1));
// The space the page takes: its real size, or shrunk for the screen.
const outer = computed(() => (props.scale
    ? { width: `${(props.pageSize[0] / 10) * props.scale}px`, height: `${(props.pageSize[1] / 10) * props.scale}px` }
    : { width: mm(props.pageSize[0]), height: mm(props.pageSize[1]) }));
const background = computed(() => {
    const bg = props.layout.background ?? {};
    const image = bg.asset_id && props.assetLinks[bg.asset_id];
    return { backgroundColor: bg.color ?? '#ffffff', ...(image ? { backgroundImage: `url("${image}")`, backgroundSize: 'cover', backgroundPosition: 'center' } : {}) };
});

const box = (element) => ({ left: mm(element.x), top: mm(element.y), width: mm(element.w), height: mm(element.h) });
const chosen = computed(() => props.layout.elements.find((element) => element.id === props.selected) ?? null);
const fonts = { sans: "'Hind Siliguri', 'Inter', system-ui, sans-serif", serif: "'Noto Serif Bengali', 'Noto Serif', Georgia, serif" };
const aligns = { start: 'left', center: 'center', end: 'right', justify: 'justify' };

function style(element) {
    switch (element.type) {
        case 'text':
            return {
                ...box(element),
                fontSize: pt(element.size),
                fontWeight: element.weight === 'bold' ? 700 : 400,
                fontStyle: element.style,
                fontFamily: fonts[element.font] ?? fonts.sans,
                lineHeight: `${element.line_height}%`,
                textAlign: aligns[element.align] ?? 'left',
                color: element.color,
            };
        case 'line':
            return {
                ...box(element),
                ...(element.h === 0 ? { borderTop: `${mm(element.thickness)} solid ${element.color}` } : { borderLeft: `${mm(element.thickness)} solid ${element.color}` }),
            };
        case 'box':
            return {
                ...box(element),
                border: element.color && element.thickness ? `${mm(element.thickness)} solid ${element.color}` : 'none',
                backgroundColor: element.fill ?? 'transparent',
                borderRadius: mm(element.radius ?? 0),
            };
        case 'photo':
            return { ...box(element), borderRadius: mm(element.radius ?? 0) };
        default:
            return box(element);
    }
}

function press(event, element) {
    if (!props.editable) return;
    event.stopPropagation();
    emit('press', event, element);
}
</script>

<template>
    <div class="document-canvas relative shrink-0" :style="outer" dir="ltr">
        <div
            class="absolute start-0 top-0 overflow-hidden"
            :style="{ width: mm(pageSize[0]), height: mm(pageSize[1]), transform: factor === 1 ? undefined : `scale(${factor})`, transformOrigin: 'top left', ...background }"
            @pointerdown="editable && emit('select-page')"
        >
            <div
                v-for="element in layout.elements"
                :key="element.id"
                class="absolute box-border"
                :class="[
                    element.type === 'text' ? 'overflow-hidden whitespace-pre-wrap [overflow-wrap:anywhere]' : '',
                    editable ? 'cursor-move touch-none' : '',
                    editable && selected === element.id ? 'outline outline-2 outline-offset-1 outline-[var(--brand)]' : editable ? 'hover:outline hover:outline-1 hover:outline-[var(--brand)]/50' : '',
                ]"
                :style="style(element)"
                :data-element="element.id"
                @pointerdown="press($event, element)"
            >
                <template v-if="element.type === 'text'"><span dir="auto">{{ fillText(element.text, values) }}</span></template>
                <template v-else-if="element.type === 'image'">
                    <img v-if="assetLinks[element.asset_id]" :src="assetLinks[element.asset_id]" alt="" class="size-full" :style="{ objectFit: element.fit }" draggable="false" />
                    <span v-else class="grid size-full place-items-center bg-black/5 text-black/40"><ImageOff class="size-1/2" aria-hidden="true" /></span>
                </template>
                <template v-else-if="element.type === 'photo'">
                    <img v-if="photoUrl" :src="photoUrl" alt="" class="size-full" :style="{ objectFit: element.fit, borderRadius: mm(element.radius ?? 0) }" draggable="false" />
                    <span v-else class="grid size-full place-items-center bg-slate-200 text-slate-400" :style="{ borderRadius: mm(element.radius ?? 0) }"><UserRound class="size-2/3" aria-hidden="true" /></span>
                </template>
                <template v-else-if="element.type === 'qr'">
                    <img v-if="qr" :src="qr" alt="" class="size-full" draggable="false" />
                    <span v-else class="grid size-full place-items-center border border-dashed border-black/30" :style="{ color: element.color }"><QrCode class="size-3/4" aria-hidden="true" /></span>
                </template>
            </div>
            <!-- Resize handle of the chosen item (designer), outside it so a text never hides it. -->
            <span
                v-if="editable && chosen"
                class="absolute z-10 cursor-se-resize rounded-sm border border-white bg-[var(--brand)] shadow"
                :style="{ left: mm(chosen.x + chosen.w - 15), top: mm(chosen.y + chosen.h - 15), width: '3mm', height: '3mm' }"
                @pointerdown.stop="emit('press-handle', $event, chosen)"
            />
        </div>
    </div>
</template>
