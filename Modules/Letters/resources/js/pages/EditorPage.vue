<script setup>
import { computed, ref, watch } from 'vue';
import { onBeforeRouteLeave, useRoute } from 'vue-router';
import {
    AlignLeft, ArrowDown, ArrowLeft, ArrowUp, Eye, FileText, Heading, List, MoveVertical, PenLine, Plus, Save, Scissors, SearchX, Table, Trash2, TriangleAlert,
} from 'lucide-vue-next';
import { AppBadge, AppButton, AppField, AppSwitch, EmptyState, ErrorState, SkeletonRows, TranslatedFields, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { lettersApi } from '../api';
import { BLOCK_TYPES, blockSummary, fillSample, keyFrom, newBlock, templateTone, unknownPlaceholders } from '../lib';
import LetterPaper from '../components/LetterPaper.vue';
import TextBox from '../components/TextBox.vue';

/**
 * The editor of one letter design, full screen: on the start side the parts
 * of the letter (add, move, remove) with the page and the questions; in the
 * middle the chosen part; on the end side the letter as it will print,
 * filled with examples as one types, or with a real person from the saved
 * version. One body per language (English required). Saved with the
 * version read; made active (letters can be issued) or retired. On a phone
 * the editor and the preview take turns.
 */
const org = currentOrganization();
const letters = lettersApi(org.id);
const route = useRoute();

const record = useResource(() => letters.template(route.params.id));
const options = useResource(() => letters.options());
const opts = computed(() => options.data.value?.data ?? null);
const groups = computed(() => opts.value?.fields ?? []);
const tableFields = computed(() => groups.value.flatMap((group) => group.fields.filter((field) => field.table)));
const canDesign = computed(() => Boolean(opts.value?.can?.design));

const template = ref(null);
const work = ref(null);
const dirty = ref(false);
const errors = ref({});
const saving = ref(false);
const locale = ref('en');
const selected = ref(0);
const side = ref('parts');
const view = ref('edit');

watch(() => record.data.value, (value) => value && load(value.data));
function load(data) {
    template.value = data;
    work.value = structuredClone({
        name: data.name, kind: data.kind, code: data.code, page: data.page, letterhead: { address: {}, ...data.letterhead },
        inputs: data.inputs ?? [], bodies: data.bodies,
    });
    if (!work.value.bodies[locale.value]) locale.value = 'en';
    selected.value = Math.min(selected.value, (work.value.bodies[locale.value]?.length ?? 1) - 1);
    dirty.value = false;
}
// Any change by the person (load() resets the mark after replacing the design).
watch(work, () => {
    dirty.value = true;
}, { deep: true, flush: 'sync' });

const locales = computed(() => opts.value?.locales ?? ['en', 'bn']);
const blocks = computed(() => work.value?.bodies[locale.value] ?? null);
const block = computed(() => blocks.value?.[selected.value] ?? null);
const prefix = computed(() => `bodies.${locale.value}.${selected.value}`);
const blockError = computed(() => errors.value[prefix.value]?.[0] ?? null);
const errorAt = (index) => Boolean(errors.value[`bodies.${locale.value}.${index}`]);
const generalErrors = computed(() => Object.entries(errors.value).filter(([key]) => !/^bodies\.[a-z]+\.\d+$/.test(key)).map(([, messages]) => messages[0]));
const unknown = computed(() => (blocks.value ? unknownPlaceholders(blocks.value, groups.value, work.value.inputs) : []));
const unknownText = computed(() => unknown.value.map((key) => `{${key}}`).join(', '));

// Parts.
const ICONS = { heading: Heading, paragraph: AlignLeft, list: List, table: Table, spacer: MoveVertical, signature: PenLine, page_break: Scissors };
function add(type) {
    blocks.value.splice(selected.value + 1, 0, newBlock(type));
    selected.value = Math.min(selected.value + 1, blocks.value.length - 1);
}
function move(index, by) {
    const to = index + by;
    if (to < 0 || to >= blocks.value.length) return;
    blocks.value.splice(to, 0, blocks.value.splice(index, 1)[0]);
    selected.value = to;
}
/** A table from a field (rows filled when issuing) or with rows written here. */
function tableSource(field) {
    blocks.value[selected.value] = field ? { type: 'table', field } : { type: 'table', rows: [['', '']] };
}
function remove(index) {
    if (blocks.value.length <= 1) return;
    blocks.value.splice(index, 1);
    selected.value = Math.max(0, Math.min(selected.value, blocks.value.length - 1));
}

// Languages: another language starts as a copy of English to translate, or empty.
function startLanguage(code, copy) {
    work.value.bodies[code] = copy ? structuredClone(work.value.bodies.en) : [{ ...newBlock('paragraph') }];
    locale.value = code;
    selected.value = 0;
}
async function dropLanguage(code) {
    if (code === 'en' || !(await confirmAction({ title: t('letters.editor.drop_language', { language: t(`letters.languages.${code}`) }), message: t('letters.editor.drop_language_text'), confirmLabel: t('letters.editor.drop'), danger: true }))) return;
    delete work.value.bodies[code];
    locale.value = 'en';
    selected.value = 0;
}

// Questions asked when issuing.
function addQuestion() {
    work.value.inputs.push({ key: '', type: 'text', required: true, label: { en: '' } });
}
function questionLabel(input, value) {
    const generated = !input.key || input.key === keyFrom(input.label.en ?? '');
    input.label.en = value;
    if (generated) input.key = keyFrom(value);
}

// Images.
const assetList = useResource(() => letters.assets());
const assets = computed(() => assetList.data.value?.data ?? []);
const images = computed(() => Object.fromEntries(assets.value.map((asset) => [asset.id, asset.url])));
const assetsOf = (kind) => assets.value.filter((asset) => asset.kind === kind && asset.is_active);

// Preview: examples as one types, or a real person from the saved version.
const person = ref('');
const people = ref([]);
const real = ref(null);
const loadingReal = ref(false);
watch(() => opts.value?.can?.issue, async (issues) => {
    if (issues) people.value = (await letters.employees('').catch(() => ({ data: [] }))).data;
}, { immediate: true });
async function showReal() {
    if (!person.value) {
        real.value = null;
        return;
    }
    loadingReal.value = true;
    try {
        real.value = (await letters.preview(template.value.id, { employee_id: person.value, locale: locale.value })).data;
    } catch (error) {
        toast.error(error.message);
        person.value = '';
    } finally {
        loadingReal.value = false;
    }
}
watch(person, showReal);
watch(locale, () => person.value && showReal());
const sample = computed(() => {
    if (!work.value || !blocks.value) return null;
    const address = work.value.letterhead.address?.[locale.value] ?? work.value.letterhead.address?.en ?? '';
    return {
        page: work.value.page,
        letterhead: { ...work.value.letterhead, address, company: org.name ?? '' },
        blocks: fillSample(blocks.value, examples.value[locale.value] ?? groups.value, work.value.inputs, (label) => textIn(label, locale.value)),
    };
});
// The examples in the letter's language (fetched once per language tab).
const examples = ref({});
watch(locale, async (code) => {
    if (examples.value[code]) return;
    const result = await letters.options(code).catch(() => null);
    if (result) examples.value = { ...examples.value, [code]: result.data.fields };
}, { immediate: true });
const shown = computed(() => (person.value && real.value && !dirty.value ? real.value : { content: sample.value, images: images.value }));

async function save(extra = {}) {
    saving.value = true;
    errors.value = {};
    try {
        const body = extra.status ? { base_version: template.value.version, ...extra } : {
            base_version: template.value.version,
            name: Object.fromEntries(Object.entries(work.value.name).filter(([, value]) => value?.trim())),
            kind: work.value.kind, code: work.value.code, page: work.value.page, letterhead: work.value.letterhead,
            inputs: work.value.inputs, bodies: work.value.bodies,
        };
        const { data } = await letters.updateTemplate(template.value.id, body);
        load(data);
        toast.success(extra.status ? t(`letters.editor.now_${extra.status}`) : t('letters.editor.saved', { version: data.version }));
        if (person.value) showReal();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('letters.editor.conflict'));
            record.reload();
            return;
        }
        errors.value = error.errors ?? {};
        // Open the first part with a problem.
        const first = Object.keys(errors.value).map((key) => /^bodies\.([a-z]+)\.(\d+)$/.exec(key)).find(Boolean);
        if (first) {
            locale.value = first[1];
            selected.value = Number(first[2]);
            side.value = 'parts';
        }
        toast.error(Object.values(errors.value)[0]?.[0] ?? error.message);
    } finally {
        saving.value = false;
    }
}
async function setStatus(status) {
    if (status === 'retired' && !(await confirmAction({ title: t('letters.editor.retire'), message: t('letters.editor.retire_text'), confirmLabel: t('letters.editor.retire') }))) return;
    save({ status });
}

onBeforeRouteLeave(async () => {
    if (!dirty.value) return true;
    return confirmAction({ title: t('letters.editor.leave_title'), message: t('letters.editor.leave_text'), confirmLabel: t('letters.editor.leave'), danger: true });
});
</script>

<template>
    <div class="min-h-dvh bg-subtle/40">
        <SkeletonRows v-if="(record.loading.value && !template) || (options.loading.value && !opts)" :rows="6" class="p-6" />
        <div v-else-if="record.error.value?.status === 404" class="p-6"><section class="card"><EmptyState :icon="SearchX" :title="t('letters.editor.not_found')" /></section></div>
        <ErrorState v-else-if="record.error.value" :error="record.error.value" class="m-6" @retry="record.reload()" />

        <template v-else-if="template && work">
            <!-- Top bar. -->
            <header class="sticky top-0 z-20 flex flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2 sm:px-4">
                <AppButton variant="ghost" size="sm" :to="{ name: 'letters-templates' }" :icon="ArrowLeft">{{ t('letters.editor.back') }}</AppButton>
                <span class="min-w-0 flex-1 truncate text-[14.5px] font-semibold">{{ textIn(work.name) }}</span>
                <AppBadge :tone="templateTone(template.status)" dot>{{ t(`letters.template_status.${template.status}`) }}</AppBadge>
                <span v-if="dirty" class="text-[12px] text-warn" role="status">{{ t('letters.editor.unsaved') }}</span>
                <template v-if="canDesign">
                    <AppButton v-if="template.status !== 'active'" size="sm" :disabled="dirty" :loading="saving" @click="setStatus('active')">{{ t('letters.editor.activate') }}</AppButton>
                    <AppButton v-else size="sm" variant="ghost" :disabled="dirty" @click="setStatus('retired')">{{ t('letters.editor.retire') }}</AppButton>
                    <AppButton size="sm" variant="primary" :icon="Save" :loading="saving" :disabled="!dirty" @click="save()">{{ t('letters.editor.save') }}</AppButton>
                </template>
            </header>

            <!-- Languages; on a phone, editor or preview. -->
            <div class="flex flex-wrap items-center gap-2 border-b border-line bg-surface px-3 py-2 sm:px-4">
                <div class="flex gap-1" role="tablist" :aria-label="t('letters.editor.languages')">
                    <button
                        v-for="code in locales"
                        :key="code"
                        type="button"
                        role="tab"
                        :aria-selected="locale === code"
                        class="rounded-lg px-3 py-1.5 text-[13px]"
                        :class="locale === code ? 'bg-brand-soft font-semibold text-brand-text' : 'text-muted hover:bg-subtle'"
                        @click="locale = code; selected = 0"
                    >
                        {{ t(`letters.languages.${code}`) }}<span v-if="!work.bodies[code]" class="text-muted"> +</span>
                    </button>
                </div>
                <button v-if="locale !== 'en' && work.bodies[locale] && canDesign" type="button" class="text-[12.5px] text-bad hover:underline" @click="dropLanguage(locale)">{{ t('letters.editor.drop') }}</button>
                <div class="ms-auto flex gap-1 lg:hidden">
                    <AppButton size="sm" :variant="view === 'edit' ? 'primary' : 'ghost'" @click="view = 'edit'">{{ t('letters.editor.edit') }}</AppButton>
                    <AppButton size="sm" :variant="view === 'preview' ? 'primary' : 'ghost'" :icon="Eye" @click="view = 'preview'">{{ t('letters.editor.preview') }}</AppButton>
                </div>
            </div>

            <div v-if="generalErrors.length || unknown.length" class="space-y-1 border-b border-bad/30 bg-bad-soft px-4 py-2 text-[13px] text-bad" role="alert">
                <p v-for="(message, index) in generalErrors" :key="index">{{ message }}</p>
                <p v-if="unknown.length" class="flex items-center gap-1.5"><TriangleAlert class="size-4" aria-hidden="true" />{{ t('letters.editor.unknown_fields', { fields: unknownText }) }}</p>
            </div>

            <!-- A language not written yet. -->
            <section v-if="!blocks" class="mx-auto my-10 max-w-md card p-6 text-center">
                <FileText class="mx-auto size-8 text-brand-text" aria-hidden="true" />
                <h2 class="mt-2 text-[15px] font-semibold">{{ t('letters.editor.no_language', { language: t(`letters.languages.${locale}`) }) }}</h2>
                <p class="mt-1 text-[13px] text-muted">{{ t('letters.editor.no_language_text') }}</p>
                <div v-if="canDesign" class="mt-4 flex justify-center gap-2">
                    <AppButton variant="primary" @click="startLanguage(locale, true)">{{ t('letters.editor.copy_english') }}</AppButton>
                    <AppButton @click="startLanguage(locale, false)">{{ t('letters.editor.start_empty') }}</AppButton>
                </div>
            </section>

            <div v-else class="grid lg:grid-cols-[260px_minmax(0,1fr)_minmax(0,1.1fr)]">
                <!-- Start side: parts, page, questions. -->
                <aside class="border-e border-line bg-surface lg:h-[calc(100dvh-98px)] lg:overflow-y-auto" :class="view === 'preview' ? 'hidden lg:block' : ''">
                    <div class="flex border-b border-line text-[13px]" role="tablist">
                        <button v-for="tab in ['parts', 'page', 'questions']" :key="tab" type="button" role="tab" :aria-selected="side === tab" class="flex-1 px-2 py-2.5" :class="side === tab ? 'border-b-2 border-brand font-semibold text-brand-text' : 'text-muted'" @click="side = tab">
                            {{ t(`letters.editor.tabs.${tab}`) }}
                        </button>
                    </div>

                    <template v-if="side === 'parts'">
                        <ol class="space-y-1 p-2">
                            <li v-for="(item, index) in blocks" :key="index">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-[13px]"
                                    :class="[selected === index ? 'bg-brand-soft text-brand-text' : 'hover:bg-subtle', errorAt(index) ? 'ring-1 ring-bad' : '']"
                                    @click="selected = index"
                                >
                                    <component :is="ICONS[item.type]" class="size-4 shrink-0" aria-hidden="true" />
                                    <span class="min-w-0 flex-1 truncate">{{ blockSummary(item) || t(`letters.blocks.${item.type}`) }}</span>
                                </button>
                            </li>
                        </ol>
                        <div v-if="canDesign" class="border-t border-line p-3">
                            <h3 class="mb-2 text-[12px] font-semibold tracking-wide text-muted uppercase">{{ t('letters.editor.add') }}</h3>
                            <div class="grid grid-cols-2 gap-1.5">
                                <button v-for="type in BLOCK_TYPES" :key="type" type="button" class="flex items-center gap-1.5 rounded-lg border border-line px-2 py-1.5 text-[12.5px] hover:border-brand/50 hover:bg-brand-soft hover:text-brand-text" @click="add(type)">
                                    <component :is="ICONS[type]" class="size-3.5" aria-hidden="true" />{{ t(`letters.blocks.${type}`) }}
                                </button>
                            </div>
                        </div>
                    </template>

                    <div v-else-if="side === 'page'" class="space-y-4 p-4">
                        <TranslatedFields v-model="work.name" :label="t('letters.designs.name')" :errors="errors" error-prefix="name" required :maxlength="80" />
                        <div class="grid grid-cols-2 gap-3">
                            <AppField v-slot="{ id }" :label="t('letters.designs.kind')" :error="errors.kind?.[0]">
                                <select :id="id" v-model="work.kind" class="field-input">
                                    <option v-for="kind in opts.kinds" :key="kind" :value="kind">{{ t(`letters.kinds.${kind}`) }}</option>
                                </select>
                            </AppField>
                            <AppField v-slot="{ id }" :label="t('letters.designs.code')" :error="errors.code?.[0]">
                                <input :id="id" v-model="work.code" maxlength="10" class="field-input font-mono uppercase" dir="ltr" />
                            </AppField>
                            <AppField v-slot="{ id }" :label="t('letters.editor.paper')">
                                <select :id="id" v-model="work.page.size" class="field-input">
                                    <option value="A4">A4</option>
                                    <option value="Letter">Letter</option>
                                </select>
                            </AppField>
                            <AppField v-slot="{ id }" :label="t('letters.editor.margin')" :error="errors.page?.[0]">
                                <input :id="id" v-model.number="work.page.margin_mm" type="number" min="10" max="40" class="field-input" />
                            </AppField>
                        </div>
                        <AppField v-slot="{ id }" :label="t('letters.editor.letterhead')" :error="errors.letterhead?.[0]">
                            <select :id="id" v-model="work.letterhead.style" class="field-input">
                                <option v-for="style in ['name', 'image', 'none']" :key="style" :value="style">{{ t(`letters.editor.letterheads.${style}`) }}</option>
                            </select>
                        </AppField>
                        <AppField v-if="work.letterhead.style === 'image'" v-slot="{ id }" :label="t('letters.editor.letterhead_image')" :hint="assetsOf('letterhead').length ? '' : t('letters.editor.add_images_first')" :error="errors['letterhead.image_id']?.[0]">
                            <select :id="id" v-model="work.letterhead.image_id" class="field-input">
                                <option :value="null">—</option>
                                <option v-for="asset in assetsOf('letterhead')" :key="asset.id" :value="asset.id">{{ asset.name }}</option>
                            </select>
                        </AppField>
                        <template v-if="work.letterhead.style === 'name'">
                            <AppSwitch v-model="work.letterhead.logo" :label="t('letters.editor.show_logo')" show-label />
                            <AppField v-slot="{ id }" :label="t('letters.editor.align')">
                                <select :id="id" v-model="work.letterhead.align" class="field-input">
                                    <option v-for="value in ['start', 'center', 'end']" :key="value" :value="value">{{ t(`letters.editor.aligns.${value}`) }}</option>
                                </select>
                            </AppField>
                            <AppField v-for="code in locales" :key="code" v-slot="{ id }" :label="t('letters.editor.address', { language: t(`letters.languages.${code}`) })" optional>
                                <textarea :id="id" v-model="work.letterhead.address[code]" rows="2" maxlength="300" class="field-input" />
                            </AppField>
                        </template>
                    </div>

                    <div v-else class="space-y-3 p-4">
                        <p class="text-[12.5px] text-muted">{{ t('letters.editor.questions_text') }}</p>
                        <div v-for="(input, index) in work.inputs" :key="index" class="space-y-2 rounded-xl border p-3" :class="errors[`inputs.${index}`] ? 'border-bad' : 'border-line'">
                            <AppField v-slot="{ id }" :label="t('letters.editor.question_en')" :error="errors[`inputs.${index}`]?.[0]">
                                <input :id="id" :value="input.label.en" maxlength="80" class="field-input" @input="questionLabel(input, $event.target.value)" />
                            </AppField>
                            <AppField v-slot="{ id }" :label="t('letters.editor.question_bn')" optional>
                                <input :id="id" v-model="input.label.bn" maxlength="80" class="field-input" />
                            </AppField>
                            <div class="grid grid-cols-2 gap-2">
                                <AppField v-slot="{ id }" :label="t('letters.editor.question_type')">
                                    <select :id="id" v-model="input.type" class="field-input">
                                        <option v-for="type in ['text', 'long_text', 'date']" :key="type" :value="type">{{ t(`letters.editor.question_types.${type}`) }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('letters.editor.question_key')">
                                    <input :id="id" v-model="input.key" maxlength="30" class="field-input font-mono text-[12.5px]" dir="ltr" />
                                </AppField>
                            </div>
                            <div class="flex items-center justify-between">
                                <AppSwitch v-model="input.required" :label="t('letters.editor.required')" show-label />
                                <button type="button" class="text-[12.5px] text-bad hover:underline" @click="work.inputs.splice(index, 1)">{{ t('letters.editor.remove') }}</button>
                            </div>
                        </div>
                        <AppButton v-if="work.inputs.length < 10" size="sm" :icon="Plus" @click="addQuestion">{{ t('letters.editor.add_question') }}</AppButton>
                    </div>
                </aside>

                <!-- Middle: the chosen part. -->
                <section class="space-y-4 p-4 lg:h-[calc(100dvh-98px)] lg:overflow-y-auto" :class="view === 'preview' ? 'hidden lg:block' : ''">
                    <template v-if="block">
                        <div class="flex items-center gap-2">
                            <component :is="ICONS[block.type]" class="size-5 text-brand-text" aria-hidden="true" />
                            <h2 class="flex-1 text-[15px] font-semibold">{{ t(`letters.blocks.${block.type}`) }}</h2>
                            <template v-if="canDesign">
                                <AppButton size="sm" variant="ghost" :icon="ArrowUp" :disabled="selected === 0" :aria-label="t('letters.editor.move_up')" @click="move(selected, -1)" />
                                <AppButton size="sm" variant="ghost" :icon="ArrowDown" :disabled="selected === blocks.length - 1" :aria-label="t('letters.editor.move_down')" @click="move(selected, 1)" />
                                <AppButton size="sm" variant="ghost" :icon="Trash2" :disabled="blocks.length <= 1" :aria-label="t('letters.editor.remove')" @click="remove(selected)" />
                            </template>
                        </div>
                        <p v-if="blockError" class="rounded-lg bg-bad-soft px-3 py-2 text-[13px] text-bad" role="alert">{{ blockError }}</p>

                        <template v-if="block.type === 'heading' || block.type === 'paragraph'">
                            <AppField v-slot="{ id }" :label="t('letters.editor.text')" :hint="t('letters.editor.text_hint')">
                                <TextBox :id="id" v-model="block.text" :multiline="block.type === 'paragraph'" :rows="7" :maxlength="block.type === 'heading' ? 200 : 3000" :groups="groups" :inputs="work.inputs" :invalid="Boolean(blockError)" />
                            </AppField>
                            <div class="grid grid-cols-2 gap-3">
                                <AppField v-slot="{ id }" :label="t('letters.editor.align')">
                                    <select :id="id" v-model="block.align" class="field-input">
                                        <option v-for="value in block.type === 'paragraph' ? ['start', 'center', 'end', 'justify'] : ['start', 'center', 'end']" :key="value" :value="value">{{ t(`letters.editor.aligns.${value}`) }}</option>
                                    </select>
                                </AppField>
                                <AppField v-if="block.type === 'heading'" v-slot="{ id }" :label="t('letters.editor.size')">
                                    <select :id="id" v-model.number="block.level" class="field-input">
                                        <option :value="1">{{ t('letters.editor.levels.1') }}</option>
                                        <option :value="2">{{ t('letters.editor.levels.2') }}</option>
                                    </select>
                                </AppField>
                            </div>
                        </template>

                        <template v-else-if="block.type === 'list'">
                            <AppSwitch v-model="block.ordered" :label="t('letters.editor.numbered')" show-label />
                            <div v-for="(item, index) in block.items" :key="index" class="flex items-start gap-2">
                                <span class="mt-9 w-5 text-end text-[12px] text-muted">{{ block.ordered ? `${index + 1}.` : '•' }}</span>
                                <TextBox v-model="block.items[index]" multiline :rows="2" :maxlength="500" :groups="groups" :inputs="work.inputs" class="flex-1" />
                                <AppButton size="sm" variant="ghost" class="mt-8" :icon="Trash2" :disabled="block.items.length <= 1" :aria-label="t('letters.editor.remove')" @click="block.items.splice(index, 1)" />
                            </div>
                            <AppButton v-if="block.items.length < 30" size="sm" :icon="Plus" @click="block.items.push('')">{{ t('letters.editor.add_item') }}</AppButton>
                        </template>

                        <template v-else-if="block.type === 'table'">
                            <AppField v-slot="{ id }" :label="t('letters.editor.table_from')">
                                <select
                                    :id="id"
                                    :value="block.field ?? ''"
                                    class="field-input"
                                    @change="tableSource($event.target.value)"
                                >
                                    <option value="">{{ t('letters.editor.table_rows') }}</option>
                                    <option v-for="field in tableFields" :key="field.key" :value="field.key">{{ field.salary ? '💰 ' : '' }}{{ field.label }}</option>
                                </select>
                            </AppField>
                            <template v-if="!block.field">
                                <div v-for="(row, index) in block.rows" :key="index" class="grid grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] items-end gap-2">
                                    <TextBox v-model="row[0]" :maxlength="200" :marks="false" :groups="groups" :inputs="work.inputs" />
                                    <TextBox v-model="row[1]" :maxlength="500" :marks="false" :groups="groups" :inputs="work.inputs" />
                                    <AppButton size="sm" variant="ghost" :icon="Trash2" :disabled="block.rows.length <= 1" :aria-label="t('letters.editor.remove')" @click="block.rows.splice(index, 1)" />
                                </div>
                                <AppButton v-if="block.rows.length < 30" size="sm" :icon="Plus" @click="block.rows.push(['', ''])">{{ t('letters.editor.add_row') }}</AppButton>
                            </template>
                        </template>

                        <AppField v-else-if="block.type === 'spacer'" v-slot="{ id }" :label="t('letters.editor.size')">
                            <select :id="id" v-model="block.size" class="field-input">
                                <option v-for="size in ['sm', 'md', 'lg']" :key="size" :value="size">{{ t(`letters.editor.spaces.${size}`) }}</option>
                            </select>
                        </AppField>

                        <template v-else-if="block.type === 'signature'">
                            <AppField v-slot="{ id }" :label="t('letters.editor.signer_name')">
                                <TextBox :id="id" v-model="block.name" :maxlength="100" :marks="false" :groups="groups" :inputs="work.inputs" />
                            </AppField>
                            <AppField v-slot="{ id }" :label="t('letters.editor.signer_title')">
                                <TextBox :id="id" v-model="block.title" :maxlength="150" :marks="false" :groups="groups" :inputs="work.inputs" />
                            </AppField>
                            <div class="grid grid-cols-2 gap-3">
                                <AppField v-slot="{ id }" :label="t('letters.editor.signature_image')" :hint="assetsOf('signature').length ? '' : t('letters.editor.add_images_first')">
                                    <select :id="id" v-model="block.image_id" class="field-input">
                                        <option :value="null">—</option>
                                        <option v-for="asset in assetsOf('signature')" :key="asset.id" :value="asset.id">{{ asset.name }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('letters.editor.stamp_image')">
                                    <select :id="id" v-model="block.stamp_id" class="field-input">
                                        <option :value="null">—</option>
                                        <option v-for="asset in assetsOf('stamp')" :key="asset.id" :value="asset.id">{{ asset.name }}</option>
                                    </select>
                                </AppField>
                                <AppField v-slot="{ id }" :label="t('letters.editor.align')">
                                    <select :id="id" v-model="block.align" class="field-input">
                                        <option v-for="value in ['start', 'center', 'end']" :key="value" :value="value">{{ t(`letters.editor.aligns.${value}`) }}</option>
                                    </select>
                                </AppField>
                            </div>
                        </template>

                        <p v-else class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('letters.editor.page_break_text') }}</p>
                    </template>
                </section>

                <!-- End side: the letter as it prints. -->
                <section class="border-s border-line bg-subtle/60 p-4 lg:h-[calc(100dvh-98px)] lg:overflow-y-auto" :class="view === 'edit' ? 'hidden lg:block' : ''">
                    <div class="mb-3 flex flex-wrap items-center gap-2 text-[12.5px]">
                        <span class="font-semibold">{{ t('letters.editor.preview') }}</span>
                        <select v-if="people.length" v-model="person" class="field-input ms-auto h-8 w-auto py-0 text-[12.5px]" :aria-label="t('letters.editor.preview_with')">
                            <option value="">{{ t('letters.editor.with_examples') }}</option>
                            <option v-for="item in people" :key="item.id" :value="item.id">{{ item.name }}</option>
                        </select>
                    </div>
                    <p v-if="person && dirty" class="mb-2 rounded-lg bg-warn-soft px-3 py-1.5 text-[12px] text-warn">{{ t('letters.editor.save_for_person') }}</p>
                    <p v-if="loadingReal" class="text-[12px] text-muted">{{ t('letters.editor.loading_preview') }}</p>
                    <div class="overflow-x-auto">
                        <div class="[zoom:0.42] sm:[zoom:0.62]">
                            <LetterPaper v-if="shown.content" :content="shown.content" :images="{ ...images, ...shown.images }" />
                        </div>
                    </div>
                </section>
            </div>
        </template>
    </div>
</template>
