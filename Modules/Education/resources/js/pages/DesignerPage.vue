<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import {
    ArrowDown, ArrowLeft, ArrowUp, Copy, Image as ImageIcon, Minus, Monitor, QrCode, RectangleHorizontal, Save, Search, SearchX, Trash2, Type, UserRound, X,
} from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { clampElement, keyFrom, newElement, placeholderGroups } from '../lib';
import DocumentCanvas from '../components/DocumentCanvas.vue';

/**
 * The designer of one ID card, certificate or letter: items placed on the
 * page with the mouse (drag to move, the corner handle to resize, arrow keys
 * for fine steps), their properties on the side, values inserted from a
 * list, questions asked when issuing, and a preview filled with a real
 * student. Saved with the version read; made active (documents can be
 * issued) or retired. Needs a wide screen to change; smaller ones only show.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const route = useRoute();

const record = useResource(() => education.template(route.params.id));
const template = ref(null);
const layout = ref(null);
const inputs = ref([]);
const assetLinks = ref({});
const dirty = ref(false);
const selected = ref(null);
const errors = ref({});
const saving = ref(false);

watch(() => record.data.value, (value) => {
    if (!value) return;
    load(value.data);
});

function load(data) {
    template.value = data;
    layout.value = structuredClone(data.layout);
    inputs.value = structuredClone(data.inputs ?? []);
    assetLinks.value = { ...(data.asset_links ?? {}) };
    dirty.value = false;
}

const page = computed(() => template.value?.page_size ?? [2100, 2970]);
const element = computed(() => layout.value?.elements.find((item) => item.id === selected.value) ?? null);
const elementIndex = computed(() => layout.value?.elements.findIndex((item) => item.id === selected.value) ?? -1);
const elementErrors = computed(() => Object.entries(errors.value).filter(([key]) => key.startsWith(`layout.elements.${elementIndex.value}.`)).map(([, messages]) => messages[0]));
const groups = computed(() => placeholderGroups([...(template.value?.placeholders ?? []).filter((key) => !key.startsWith('input.')), ...inputs.value.filter((input) => input.key).map((input) => `input.${input.key}`)]));
const sensitive = computed(() => new Set(template.value?.sensitive_placeholders ?? []));

// Screen: the page fits the space between the side panels.
const stage = ref(null);
const stageWidth = ref(800);
const wide = ref(true);
let observer = null;
onMounted(() => {
    observer = new ResizeObserver(([entry]) => {
        stageWidth.value = entry.contentRect.width;
    });
    if (stage.value) observer.observe(stage.value);
    const media = window.matchMedia('(min-width: 1024px)');
    wide.value = media.matches;
    media.addEventListener('change', (event) => (wide.value = event.matches));
    window.addEventListener('keydown', onKey);
});
watch(stage, (node) => node && observer?.observe(node));
onBeforeUnmount(() => {
    observer?.disconnect();
    window.removeEventListener('keydown', onKey);
});
const scale = computed(() => Math.max(1, Math.min(page.value[0] < 1000 ? 8 : 4, (stageWidth.value - 48) / (page.value[0] / 10))));

function change(values) {
    if (!element.value) return;
    const index = elementIndex.value;
    layout.value.elements[index] = clampElement({ ...layout.value.elements[index], ...values }, page.value);
    dirty.value = true;
}

// Moving and resizing with the pointer (pixels on screen -> tenths of a millimetre).
let drag = null;
function press(event, item, mode = 'move') {
    if (!wide.value) return;
    selected.value = item.id;
    drag = { mode, x: event.clientX, y: event.clientY, start: { ...item } };
    window.addEventListener('pointermove', move);
    window.addEventListener('pointerup', release, { once: true });
}
function move(event) {
    if (!drag) return;
    const dx = Math.round(((event.clientX - drag.x) / scale.value) * 10);
    const dy = Math.round(((event.clientY - drag.y) / scale.value) * 10);
    change(drag.mode === 'move' ? { x: drag.start.x + dx, y: drag.start.y + dy } : { w: drag.start.w + dx, h: drag.start.h + dy });
}
function release() {
    drag = null;
    window.removeEventListener('pointermove', move);
}

function onKey(event) {
    if (!element.value || !wide.value || ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName)) return;
    const step = event.shiftKey ? 1 : 10;
    const moves = { ArrowLeft: { x: element.value.x - step }, ArrowRight: { x: element.value.x + step }, ArrowUp: { y: element.value.y - step }, ArrowDown: { y: element.value.y + step } };
    if (moves[event.key]) {
        event.preventDefault();
        change(moves[event.key]);
    } else if (event.key === 'Delete') {
        remove();
    } else if (event.key === 'Escape') {
        selected.value = null;
    }
}

function add(type) {
    const item = newElement(type, page.value, layout.value.elements.map((existing) => existing.id));
    if (type === 'image') item.asset_id = assets.value[0]?.id ?? '';
    layout.value.elements.push(item);
    selected.value = item.id;
    dirty.value = true;
}
function remove() {
    layout.value.elements.splice(elementIndex.value, 1);
    selected.value = null;
    dirty.value = true;
}
function duplicate() {
    const copy = newElement(element.value.type, page.value, layout.value.elements.map((existing) => existing.id));
    layout.value.elements.push(clampElement({ ...element.value, id: copy.id, x: element.value.x + 30, y: element.value.y + 30 }, page.value));
    selected.value = copy.id;
    dirty.value = true;
}
function reorder(direction) {
    const items = layout.value.elements;
    const from = elementIndex.value;
    const to = Math.max(0, Math.min(items.length - 1, from + direction));
    items.splice(to, 0, items.splice(from, 1)[0]);
    dirty.value = true;
}

// Values inserted into the chosen text at the cursor.
const textBox = ref(null);
function insert(key) {
    const box = textBox.value;
    const text = element.value.text ?? '';
    const at = box?.selectionStart ?? text.length;
    change({ text: `${text.slice(0, at)}{${key}}${text.slice(box?.selectionEnd ?? at)}` });
}

// Numbers on the side in millimetres and points; kept as tenths.
const tenths = (value) => Math.round(Number(value) * 10);
const shown = (value) => (value ?? 0) / 10;

// Images to choose from.
const assetList = useResource(() => education.assets());
const assets = computed(() => assetList.data.value?.data ?? []);
watch(assets, (list) => {
    for (const item of list) assetLinks.value[item.id] = item.url;
});

// Questions asked when issuing.
function addQuestion() {
    inputs.value.push({ key: '', label: { en: '' }, required: false, multiline: false });
    dirty.value = true;
}
function questionLabel(input, value) {
    const generated = !input.key || input.key === keyFrom(input.label.en ?? '');
    input.label.en = value;
    if (generated) input.key = keyFrom(value);
    dirty.value = true;
}

// Preview with a real student.
const search = ref('');
const found = ref([]);
const previewing = ref(null);
const preview = ref(null);
let timer = null;
watch(search, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        found.value = (await education.students({ q: value.trim(), per_page: 6 }).catch(() => ({ data: [] }))).data;
    }, 300);
});
async function showWith(student) {
    previewing.value = student;
    search.value = '';
    found.value = [];
    try {
        const sample = Object.fromEntries(inputs.value.filter((input) => input.key).map((input) => [input.key, textIn(input.label)]));
        preview.value = (await education.previewTemplate(template.value.id, { student_id: student.id, inputs: sample })).data;
        assetLinks.value = { ...assetLinks.value, ...preview.value.asset_links };
    } catch (error) {
        toast.error(error.message);
        previewing.value = null;
    }
}
function clearPreview() {
    previewing.value = null;
    preview.value = null;
}

async function save(extra = {}) {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await education.updateTemplate(template.value.id, {
            base_version: template.value.version, layout: layout.value, inputs: inputs.value, ...extra,
        });
        const keep = selected.value;
        load({ ...template.value, ...data, placeholders: template.value.placeholders, sensitive_placeholders: template.value.sensitive_placeholders });
        selected.value = keep;
        toast.success(extra.status ? t(`education.designer.${extra.status === 'active' ? 'activated' : 'retired'}`) : t('education.designer.saved', { version: data.version }));
        if (previewing.value) showWith(previewing.value);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            record.reload();
            return;
        }
        errors.value = error.errors ?? {};
        // Open the first item with a problem.
        const first = Object.keys(errors.value).map((key) => /^layout\.elements\.(\d+)\./.exec(key)).find(Boolean);
        if (first) selected.value = layout.value.elements[Number(first[1])]?.id ?? selected.value;
        toast.error(Object.values(errors.value)[0]?.[0] ?? error.message);
    } finally {
        saving.value = false;
    }
}

async function setStatus(status) {
    if (status === 'retired' && !(await confirmAction({ title: t('education.designer.retire'), message: t('education.designer.retired'), confirmLabel: t('education.designer.retire') }))) return;
    save({ status });
}

onBeforeRouteLeave(async () => {
    if (!dirty.value) return true;
    return confirmAction({ title: t('education.designer.leave_title'), message: t('education.designer.leave_text'), confirmLabel: t('education.designer.leave'), danger: true });
});

const TYPES = [
    { type: 'text', icon: Type },
    { type: 'image', icon: ImageIcon },
    { type: 'photo', icon: UserRound },
    { type: 'qr', icon: QrCode },
    { type: 'line', icon: Minus },
    { type: 'box', icon: RectangleHorizontal },
];
const statusTone = { draft: 'neutral', active: 'ok', retired: 'outline' };
</script>

<template>
    <div class="min-h-dvh bg-subtle/40">
        <SkeletonRows v-if="record.loading.value && !template" :rows="6" class="p-6" />
        <div v-else-if="record.error.value?.status === 404" class="p-6"><section class="card"><EmptyState :icon="SearchX" :title="t('education.designer.not_found')" /></section></div>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" class="m-6" @retry="record.reload()" />

        <template v-else-if="template && layout">
            <!-- Top bar. -->
            <header class="sticky top-0 z-20 flex flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2 sm:px-4">
                <AppButton variant="ghost" size="sm" :to="{ name: 'education-designs' }" :icon="ArrowLeft">{{ t('education.designer.back') }}</AppButton>
                <span class="min-w-0 flex-1 truncate text-[14.5px] font-semibold text-fg">{{ template.name_text }}</span>
                <AppBadge :tone="statusTone[template.status]" dot>{{ t(`education.template_statuses.${template.status}`) }}</AppBadge>
                <span v-if="dirty" class="text-[12px] text-warn" role="status">{{ t('education.designer.unsaved') }}</span>
                <template v-if="wide">
                    <AppButton v-if="template.status !== 'active'" size="sm" :disabled="dirty" :loading="saving" @click="setStatus('active')">{{ t('education.designer.activate') }}</AppButton>
                    <AppButton v-else size="sm" variant="ghost" :disabled="dirty" @click="setStatus('retired')">{{ t('education.designer.retire') }}</AppButton>
                    <AppButton size="sm" variant="primary" :icon="Save" :loading="saving" :disabled="!dirty" @click="save()">{{ t('education.designer.save') }}</AppButton>
                </template>
            </header>

            <p v-if="!wide" class="m-3 flex items-start gap-2 rounded-xl bg-warn-soft px-4 py-3 text-[13px] text-warn"><Monitor class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ t('education.designer.small_screen') }}</p>

            <div class="grid lg:grid-cols-[240px_minmax(0,1fr)_300px]">
                <!-- Left: add items, list of items, page and questions. -->
                <aside v-if="wide" class="space-y-5 border-e border-line bg-surface p-4 lg:h-[calc(100dvh-49px)] lg:overflow-y-auto">
                    <section>
                        <h2 class="mb-2 text-[12px] font-semibold tracking-wide text-muted uppercase">{{ t('education.designer.add') }}</h2>
                        <div class="grid grid-cols-3 gap-1.5">
                            <button v-for="item in TYPES" :key="item.type" type="button" class="flex flex-col items-center gap-1 rounded-xl border border-line px-1 py-2 text-[11.5px] text-fg-2 transition hover:border-brand/50 hover:bg-brand-soft hover:text-brand-text" @click="add(item.type)">
                                <component :is="item.icon" class="size-4" aria-hidden="true" />{{ t(`education.designer.types.${item.type}`) }}
                            </button>
                        </div>
                    </section>
                    <section>
                        <h2 class="mb-2 text-[12px] font-semibold tracking-wide text-muted uppercase">{{ t('education.designer.items') }}</h2>
                        <ol class="space-y-0.5">
                            <li v-for="item in [...layout.elements].reverse()" :key="item.id">
                                <button type="button" class="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-[12.5px] transition" :class="selected === item.id ? 'bg-brand-soft text-brand-text' : 'text-fg-2 hover:bg-subtle'" @click="selected = item.id">
                                    <component :is="TYPES.find((type) => type.type === item.type)?.icon" class="size-3.5 shrink-0" aria-hidden="true" />
                                    <span class="truncate">{{ item.type === 'text' ? item.text.slice(0, 40) || item.id : t(`education.designer.types.${item.type}`) }}</span>
                                </button>
                            </li>
                        </ol>
                    </section>
                    <section class="space-y-3">
                        <h2 class="text-[12px] font-semibold tracking-wide text-muted uppercase">{{ t('education.designer.page') }}</h2>
                        <p class="text-[12px] text-muted">{{ t(`education.page_sizes.${template.page.size}`) }} · {{ t(`education.orientations.${template.page.orientation}`) }}</p>
                        <label class="flex items-center justify-between gap-2 text-[12.5px]">
                            {{ t('education.designer.background') }}
                            <input v-model="layout.background.color" type="color" class="h-8 w-12 cursor-pointer rounded border border-line" @input="dirty = true" />
                        </label>
                        <AppField v-slot="{ id }" :label="t('education.designer.background_image')">
                            <select :id="id" v-model="layout.background.asset_id" class="field-input h-8 text-[12.5px]" @change="dirty = true">
                                <option :value="null">{{ t('education.designer.none') }}</option>
                                <option v-for="item in assets" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </AppField>
                    </section>
                    <section class="space-y-2">
                        <h2 class="text-[12px] font-semibold tracking-wide text-muted uppercase">{{ t('education.designer.questions') }}</h2>
                        <p class="text-[11.5px] text-muted">{{ t('education.designer.questions_text') }}</p>
                        <div v-for="(input, index) in inputs" :key="index" class="space-y-1.5 rounded-xl border border-line p-2.5">
                            <div class="flex items-center gap-1">
                                <input :value="input.label.en" class="field-input h-8 flex-1 text-[12.5px]" :placeholder="t('education.designer.question_label')" maxlength="80" @input="questionLabel(input, $event.target.value)" />
                                <AppButton size="icon-sm" variant="ghost" :icon="X" :aria-label="t('education.guardian.remove')" @click="inputs.splice(index, 1); dirty = true" />
                            </div>
                            <input v-model="input.key" class="field-input h-8 font-mono text-[12px]" dir="ltr" :placeholder="t('education.designer.question_key')" maxlength="30" @input="dirty = true" />
                            <AppSwitch v-model="input.required" :label="t('education.designer.question_required')" show-label @update:model-value="dirty = true" />
                            <p v-if="errors[`inputs.${index}.key`] || errors[`inputs.${index}.label.en`]" class="text-[11.5px] text-bad">{{ (errors[`inputs.${index}.key`] ?? errors[`inputs.${index}.label.en`])[0] }}</p>
                        </div>
                        <AppButton v-if="inputs.length < 10" size="sm" variant="ghost" @click="addQuestion">{{ t('education.designer.add_question') }}</AppButton>
                    </section>
                </aside>

                <!-- Centre: the page. -->
                <main ref="stage" class="flex min-w-0 flex-col items-center gap-3 p-4 sm:p-6 lg:h-[calc(100dvh-49px)] lg:overflow-auto">
                    <div v-if="wide" class="relative w-full max-w-xl">
                        <div v-if="previewing" class="flex items-center gap-2 rounded-xl border border-brand/30 bg-brand-soft px-3 py-2 text-[12.5px] text-brand-text">
                            <span class="flex-1 truncate">{{ t('education.designer.preview') }}: <strong>{{ previewing.name }}</strong></span>
                            <AppButton size="sm" variant="ghost" @click="clearPreview">{{ t('education.designer.preview_clear') }}</AppButton>
                        </div>
                        <template v-else>
                            <label class="relative block">
                                <span class="sr-only">{{ t('education.designer.preview_search') }}</span>
                                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                                <input v-model="search" type="search" class="field-input h-9 ps-9" :placeholder="`${t('education.designer.preview')}: ${t('education.designer.preview_search')}`" autocomplete="off" />
                            </label>
                            <ul v-if="found.length" class="absolute inset-x-0 top-full z-10 mt-1 overflow-hidden rounded-xl border border-line bg-surface shadow-lg">
                                <li v-for="student in found" :key="student.id">
                                    <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-start text-[13px] hover:bg-subtle" @click="showWith(student)">
                                        <span class="flex-1 truncate">{{ student.name }}</span><span class="font-mono text-[11.5px] text-muted" dir="ltr">{{ student.code }}</span>
                                    </button>
                                </li>
                            </ul>
                        </template>
                    </div>
                    <div class="rounded-sm shadow-[0_8px_30px_rgb(0_0_0/0.12)] ring-1 ring-black/5">
                        <DocumentCanvas
                            :page-size="page"
                            :layout="layout"
                            :values="preview?.values ?? null"
                            :asset-links="assetLinks"
                            :photo-url="preview?.photo_url ?? null"
                            :qr="preview?.qr ?? null"
                            :scale="wide ? scale : Math.min(scale, (stageWidth - 32) / (page[0] / 10))"
                            :editable="wide"
                            :selected="selected"
                            @press="(event, item) => press(event, item)"
                            @press-handle="(event, item) => press(event, item, 'resize')"
                            @select-page="selected = null"
                        />
                    </div>
                    <p v-if="wide" class="text-[11.5px] text-faint">{{ t('education.designer.keys') }}</p>
                </main>

                <!-- Right: the chosen item. -->
                <aside v-if="wide" class="border-s border-line bg-surface p-4 lg:h-[calc(100dvh-49px)] lg:overflow-y-auto">
                    <p v-if="!element" class="rounded-xl bg-subtle px-3 py-3 text-[12.5px] text-muted">{{ t('education.designer.no_selection') }}</p>
                    <div v-else class="space-y-4">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-[13.5px] font-semibold text-fg">{{ t(`education.designer.types.${element.type}`) }} <span class="font-mono text-[11px] font-normal text-faint">{{ element.id }}</span></h2>
                            <div class="flex">
                                <AppButton size="icon-sm" variant="ghost" :icon="ArrowUp" :aria-label="t('education.designer.to_front')" :title="t('education.designer.to_front')" @click="reorder(1)" />
                                <AppButton size="icon-sm" variant="ghost" :icon="ArrowDown" :aria-label="t('education.designer.to_back')" :title="t('education.designer.to_back')" @click="reorder(-1)" />
                                <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('education.designer.duplicate')" :title="t('education.designer.duplicate')" @click="duplicate" />
                                <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('education.designer.delete')" :title="t('education.designer.delete')" @click="remove" />
                            </div>
                        </div>
                        <ul v-if="elementErrors.length" class="space-y-1 rounded-xl bg-bad-soft px-3 py-2 text-[12px] text-bad" role="alert">
                            <li v-for="message in elementErrors" :key="message">{{ message }}</li>
                        </ul>

                        <div class="grid grid-cols-2 gap-2">
                            <AppField v-for="key in ['x', 'y', 'w', 'h']" :key="key" v-slot="{ id }" :label="t(`education.designer.${key}`)">
                                <input :id="id" type="number" step="0.1" min="0" class="field-input h-8 tabular text-[12.5px]" :value="shown(element[key])" @change="change({ [key]: tenths($event.target.value) })" />
                            </AppField>
                        </div>

                        <template v-if="element.type === 'text'">
                            <AppField v-slot="{ id }" :label="t('education.designer.text')" :hint="t('education.designer.text_hint')">
                                <textarea :id="id" ref="textBox" :value="element.text" rows="5" class="field-input text-[13px]" maxlength="2000" dir="auto" @input="change({ text: $event.target.value })" />
                            </AppField>
                            <details class="rounded-xl border border-line">
                                <summary class="cursor-pointer px-3 py-2 text-[12.5px] font-medium text-fg-2">{{ t('education.designer.insert') }}</summary>
                                <div class="space-y-2 px-3 pb-3">
                                    <div v-for="group in groups" :key="group.name">
                                        <p class="mb-1 text-[11px] font-semibold text-muted">{{ t(`education.designer.groups.${group.name}`) }}</p>
                                        <div class="flex flex-wrap gap-1">
                                            <button v-for="key in group.keys" :key="key" type="button" class="rounded-md bg-subtle px-1.5 py-0.5 font-mono text-[11px] text-fg-2 hover:bg-brand-soft hover:text-brand-text" dir="ltr" @click="insert(key)">
                                                {{ key }}<span v-if="sensitive.has(key)" class="ms-1 font-sans text-bad">· {{ t('education.designer.private_value') }}</span>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </details>
                            <div class="grid grid-cols-2 gap-2">
                                <AppField v-slot="{ id }" :label="t('education.designer.size')">
                                    <input :id="id" type="number" step="0.5" min="4" max="96" class="field-input h-8 tabular text-[12.5px]" :value="shown(element.size)" @change="change({ size: tenths($event.target.value) })" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('education.designer.line_height')">
                                    <input :id="id" type="number" step="5" min="80" max="300" class="field-input h-8 tabular text-[12.5px]" :value="element.line_height" @change="change({ line_height: Math.round(Number($event.target.value)) })" />
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('education.designer.align')">
                                    <select :id="id" class="field-input h-8 text-[12.5px]" :value="element.align" @change="change({ align: $event.target.value })">
                                        <option v-for="value in ['start', 'center', 'end', 'justify']" :key="value" :value="value">{{ t(`education.designer.aligns.${value}`) }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('education.designer.font')">
                                    <select :id="id" class="field-input h-8 text-[12.5px]" :value="element.font" @change="change({ font: $event.target.value })">
                                        <option v-for="value in ['sans', 'serif']" :key="value" :value="value">{{ t(`education.designer.fonts.${value}`) }}</option>
                                    </select>
                                </AppField>
                            </div>
                            <div class="flex flex-wrap gap-x-5 gap-y-2">
                                <AppSwitch :model-value="element.weight === 'bold'" :label="t('education.designer.weight')" show-label @update:model-value="(on) => change({ weight: on ? 'bold' : 'normal' })" />
                                <AppSwitch :model-value="element.style === 'italic'" :label="t('education.designer.italic')" show-label @update:model-value="(on) => change({ style: on ? 'italic' : 'normal' })" />
                            </div>
                        </template>

                        <AppField v-if="element.type === 'image'" v-slot="{ id }" :label="t('education.designer.image')">
                            <select :id="id" class="field-input h-8 text-[12.5px]" :value="element.asset_id" @change="change({ asset_id: $event.target.value })">
                                <option value="" disabled>{{ t('education.designer.choose_image') }}</option>
                                <option v-for="item in assets" :key="item.id" :value="item.id">{{ item.name }}</option>
                            </select>
                        </AppField>
                        <AppField v-if="['image', 'photo'].includes(element.type)" v-slot="{ id }" :label="t('education.designer.fit')">
                            <select :id="id" class="field-input h-8 text-[12.5px]" :value="element.fit" @change="change({ fit: $event.target.value })">
                                <option v-for="value in ['contain', 'cover']" :key="value" :value="value">{{ t(`education.designer.fits.${value}`) }}</option>
                            </select>
                        </AppField>
                        <div v-if="['line', 'box'].includes(element.type)" class="grid grid-cols-2 gap-2">
                            <AppField v-slot="{ id }" :label="t('education.designer.thickness')">
                                <input :id="id" type="number" step="0.1" min="0" max="5" class="field-input h-8 tabular text-[12.5px]" :value="shown(element.thickness)" @change="change({ thickness: tenths($event.target.value) })" />
                            </AppField>
                        </div>
                        <AppField v-if="['box', 'photo'].includes(element.type)" v-slot="{ id }" :label="t('education.designer.radius')">
                            <input :id="id" type="number" step="0.5" min="0" max="20" class="field-input h-8 tabular text-[12.5px]" :value="shown(element.radius)" @change="change({ radius: tenths($event.target.value) })" />
                        </AppField>
                        <div v-if="['text', 'qr', 'line', 'box'].includes(element.type)" class="space-y-2">
                            <label class="flex items-center justify-between gap-2 text-[12.5px]">
                                {{ t('education.designer.color') }}
                                <input type="color" class="h-8 w-12 cursor-pointer rounded border border-line" :value="element.color ?? '#000000'" @input="change({ color: $event.target.value })" />
                            </label>
                            <label v-if="element.type === 'box'" class="flex items-center justify-between gap-2 text-[12.5px]">
                                {{ t('education.designer.fill') }}
                                <span class="flex items-center gap-2">
                                    <AppButton size="sm" variant="ghost" @click="change({ fill: null })">{{ t('education.designer.no_fill') }}</AppButton>
                                    <input type="color" class="h-8 w-12 cursor-pointer rounded border border-line" :value="element.fill ?? '#ffffff'" @input="change({ fill: $event.target.value })" />
                                </span>
                            </label>
                        </div>
                    </div>
                </aside>
            </div>
        </template>
    </div>
</template>
