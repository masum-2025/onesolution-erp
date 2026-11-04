<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Eye, Search, Send, X } from 'lucide-vue-next';
import { AppButton, AppDialog, AppField, AppSwitch, DateInput, newOpId, toast } from '@/ui';
import { currentOrganization } from '@/lib/session';
import { i18n, t } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { lettersApi } from '../api';
import { defaultLocale, missingAnswers } from '../lib';
import LetterPaper from './LetterPaper.vue';

/**
 * Issue letters from an active design: the people (searched by name or
 * code, many at once), the language, the design's questions, whether the
 * people see it; a preview with the first person before sending. One press
 * is one op_id, so pressing again after a dropped connection gives the same
 * letters. If someone lacks a detail the server names them and nothing is issued.
 */
const props = defineProps({
    open: Boolean,
    templates: { type: Array, default: () => [] },
    templateId: { type: String, default: '' },
    maxPeople: { type: Number, default: 200 },
});
const emit = defineEmits(['close', 'issued']);

const org = currentOrganization();
const letters = lettersApi(org.id);

const form = reactive({ template_id: '', locale: 'en', shared: true, inputs: {} });
const template = ref(null);
const people = ref([]);
const errors = ref({});
const problem = ref('');
const sending = ref(false);
// People are searched as one types (name or code) and picked into a list.
const search = ref('');
const found = ref([]);
// A preview with the first person (nothing is saved).
const preview = ref(null);
const previewing = ref(false);
let opId = newOpId();

watch(() => props.open, (open) => {
    if (!open) return;
    Object.assign(form, { template_id: props.templateId || props.templates[0]?.id || '', shared: true, inputs: {} });
    people.value = [];
    errors.value = {};
    problem.value = '';
    preview.value = null;
    opId = newOpId();
    search.value = '';
    lookUp('');
}, { immediate: true });

watch(() => form.template_id, async (id) => {
    template.value = null;
    preview.value = null;
    if (!id) return;
    try {
        template.value = (await letters.template(id)).data;
        form.locale = defaultLocale(template.value.locales, i18n.locale);
        form.inputs = Object.fromEntries(template.value.inputs.map((input) => [input.key, '']));
    } catch (error) {
        toast.error(error.message);
    }
}, { immediate: true });

let timer = null;
async function lookUp(value) {
    found.value = (await letters.employees(value.trim()).catch(() => ({ data: [] }))).data;
}
watch(search, (value) => {
    clearTimeout(timer);
    timer = setTimeout(() => lookUp(value), 250);
});
const chosen = computed(() => new Set(people.value.map((person) => person.id)));
function pick(person) {
    if (chosen.value.has(person.id) || people.value.length >= props.maxPeople) return;
    people.value.push(person);
    preview.value = null;
}
function drop(person) {
    people.value = people.value.filter((item) => item.id !== person.id);
    preview.value = null;
}
function pickAll() {
    for (const person of found.value) pick(person);
}

const missing = computed(() => (template.value ? missingAnswers(template.value.inputs, form.inputs) : []));
const ready = computed(() => template.value && people.value.length > 0 && missing.value.length === 0);
const labelOf = (input) => textIn(input.label);

async function showPreview() {
    previewing.value = true;
    try {
        preview.value = (await letters.preview(form.template_id, { employee_id: people.value[0]?.id ?? null, locale: form.locale, inputs: form.inputs })).data;
    } catch (error) {
        toast.error(error.message);
    } finally {
        previewing.value = false;
    }
}

async function send() {
    sending.value = true;
    errors.value = {};
    problem.value = '';
    try {
        const { data } = await letters.issue({
            template_id: form.template_id, employee_ids: people.value.map((person) => person.id), locale: form.locale,
            inputs: form.inputs, shared: form.shared, op_id: opId,
        });
        toast.success(t('letters.issue.done', { count: data.length }));
        emit('issued', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        // "Some people lack details": shown in full in the dialog, not a passing toast.
        problem.value = Object.keys(errors.value).length ? '' : error.message;
        // A refused send may be pressed again as a new one.
        opId = newOpId();
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('letters.issue.title')" :icon="Send" size="lg" @close="emit('close')">
        <form id="letters-issue" class="grid gap-4" novalidate @submit.prevent="send">
            <AppField v-slot="{ id }" :label="t('letters.issue.design')" :error="errors.template_id?.[0]">
                <select :id="id" v-model="form.template_id" class="field-input">
                    <option v-for="item in templates" :key="item.id" :value="item.id">{{ textIn(item.name) }}{{ item.uses_salary ? ' · 💰' : '' }}</option>
                </select>
            </AppField>

            <!-- People. -->
            <AppField v-slot="{ id }" :label="t('letters.issue.people')" :hint="t('letters.issue.people_hint', { max: maxPeople })" :error="errors.employee_ids?.[0]">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('letters.issue.search')" autocomplete="off" />
                </div>
            </AppField>
            <div v-if="found.length" class="-mt-2 max-h-48 overflow-y-auto rounded-xl border border-line">
                <div class="flex items-center justify-between border-b border-line bg-surface-2 px-3 py-1.5 text-[12px] text-muted">
                    <span>{{ t('letters.issue.found', { count: found.length }) }}</span>
                    <button type="button" class="font-medium text-brand-text hover:underline" @click="pickAll">{{ t('letters.issue.pick_all') }}</button>
                </div>
                <button
                    v-for="person in found"
                    :key="person.id"
                    type="button"
                    class="flex w-full items-center gap-3 px-3 py-2 text-start text-[13.5px] hover:bg-surface-2 disabled:opacity-50"
                    :disabled="chosen.has(person.id)"
                    @click="pick(person)"
                >
                    <span class="flex-1">{{ person.name }}</span>
                    <span class="font-mono text-[12px] text-muted" dir="ltr">{{ person.code }}</span>
                </button>
            </div>
            <ul v-if="people.length" class="flex flex-wrap gap-1.5" :aria-label="t('letters.issue.chosen')">
                <li v-for="person in people" :key="person.id" class="flex items-center gap-1 rounded-full bg-brand-soft py-1 ps-3 pe-1 text-[12.5px] text-brand-text">
                    {{ person.name }}
                    <button type="button" class="grid size-5 place-items-center rounded-full hover:bg-brand/15" :aria-label="t('letters.issue.remove', { name: person.name })" @click="drop(person)"><X class="size-3.5" aria-hidden="true" /></button>
                </li>
            </ul>

            <div v-if="template" class="grid gap-4 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('letters.issue.language')" :error="errors.locale?.[0]">
                    <select :id="id" v-model="form.locale" class="field-input">
                        <option v-for="code in template.locales" :key="code" :value="code">{{ t(`letters.languages.${code}`) }}</option>
                    </select>
                </AppField>
                <div class="flex items-end pb-2">
                    <AppSwitch v-model="form.shared" :label="t('letters.issue.shared')" show-label />
                </div>
                <template v-for="input in template.inputs" :key="input.key">
                    <AppField v-slot="{ id }" :label="labelOf(input)" :optional="!input.required" :error="errors[`inputs.${input.key}`]?.[0]" :class="input.type === 'long_text' ? 'sm:col-span-2' : ''">
                        <DateInput v-if="input.type === 'date'" :id="id" v-model="form.inputs[input.key]" />
                        <textarea v-else-if="input.type === 'long_text'" :id="id" v-model="form.inputs[input.key]" rows="3" maxlength="2000" class="field-input" />
                        <input v-else :id="id" v-model="form.inputs[input.key]" maxlength="200" class="field-input" />
                    </AppField>
                </template>
            </div>

            <p v-if="problem" class="rounded-xl bg-bad-soft px-4 py-3 text-[13px] text-bad" role="alert">{{ problem }}</p>

            <section v-if="preview" class="max-h-[60vh] overflow-auto rounded-xl bg-subtle p-3">
                <div class="[zoom:0.4] sm:[zoom:0.6]">
                    <LetterPaper :content="preview.content" :images="preview.images" />
                </div>
            </section>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('letters.common.cancel') }}</AppButton>
            <AppButton :icon="Eye" :loading="previewing" :disabled="!template" @click="showPreview">{{ t('letters.issue.preview') }}</AppButton>
            <AppButton variant="primary" type="submit" form="letters-issue" :icon="Send" :loading="sending" :disabled="!ready">
                {{ t('letters.issue.send', { count: people.length }) }}
            </AppButton>
        </template>
    </AppDialog>
</template>
