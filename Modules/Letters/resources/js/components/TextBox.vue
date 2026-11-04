<script setup>
import { nextTick, ref } from 'vue';
import { Bold, Italic, Underline } from 'lucide-vue-next';
import { t } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { insertField, wrapMark } from '../lib';

/**
 * A line or a longer text of a letter, with buttons for **bold**, *italic*
 * and __underline__ (around what is chosen) and a list of fields to put in
 * at the cursor ("{employee.name}"). The marks are plain characters: the
 * paper draws them, nothing is ever HTML.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    id: { type: String, default: undefined },
    multiline: Boolean,
    rows: { type: Number, default: 4 },
    maxlength: { type: Number, default: 3000 },
    groups: { type: Array, default: () => [] },
    inputs: { type: Array, default: () => [] },
    marks: { type: Boolean, default: true },
    invalid: Boolean,
});
const emit = defineEmits(['update:modelValue']);
const box = ref(null);

async function apply(change) {
    const element = box.value;
    const start = element?.selectionStart ?? props.modelValue.length;
    const end = element?.selectionEnd ?? start;
    const next = change(props.modelValue, start, end);
    emit('update:modelValue', next.text);
    await nextTick();
    element?.focus();
    element?.setSelectionRange(next.cursor, next.cursor);
}
const mark = (sign) => apply((text, start, end) => wrapMark(text, start, end, sign));
function insert(event) {
    const key = event.target.value;
    event.target.value = '';
    if (key) apply((text, start, end) => insertField(text, start, end, key));
}
</script>

<template>
    <div class="overflow-hidden rounded-xl border bg-surface focus-within:ring-2 focus-within:ring-brand/30" :class="invalid ? 'border-bad' : 'border-line'">
        <div class="flex flex-wrap items-center gap-1 border-b border-line bg-surface-2 px-1.5 py-1">
            <template v-if="marks">
                <button type="button" class="grid size-7 place-items-center rounded-md hover:bg-subtle" :title="t('letters.editor.bold')" :aria-label="t('letters.editor.bold')" @click="mark('**')"><Bold class="size-4" aria-hidden="true" /></button>
                <button type="button" class="grid size-7 place-items-center rounded-md hover:bg-subtle" :title="t('letters.editor.italic')" :aria-label="t('letters.editor.italic')" @click="mark('*')"><Italic class="size-4" aria-hidden="true" /></button>
                <button type="button" class="grid size-7 place-items-center rounded-md hover:bg-subtle" :title="t('letters.editor.underline')" :aria-label="t('letters.editor.underline')" @click="mark('__')"><Underline class="size-4" aria-hidden="true" /></button>
            </template>
            <select class="ms-auto h-7 max-w-52 rounded-md border-0 bg-transparent text-[12.5px] text-brand-text focus:ring-0" :aria-label="t('letters.editor.insert_field')" @change="insert">
                <option value="">{{ t('letters.editor.insert_field') }}</option>
                <optgroup v-for="group in groups" :key="group.group" :label="group.label">
                    <option v-for="field in group.fields.filter((item) => !item.table)" :key="field.key" :value="field.key">{{ field.salary ? '💰 ' : '' }}{{ field.label }}</option>
                </optgroup>
                <optgroup v-if="inputs.length" :label="t('letters.editor.questions')">
                    <option v-for="input in inputs" :key="input.key" :value="`input.${input.key}`">{{ textIn(input.label) || input.key }}</option>
                </optgroup>
            </select>
        </div>
        <textarea
            v-if="multiline"
            :id="id"
            ref="box"
            :value="modelValue"
            :rows="rows"
            :maxlength="maxlength"
            class="block w-full resize-y border-0 bg-transparent px-3 py-2 text-[13.5px] leading-relaxed focus:ring-0"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <input
            v-else
            :id="id"
            ref="box"
            :value="modelValue"
            :maxlength="maxlength"
            class="block w-full border-0 bg-transparent px-3 py-2 text-[13.5px] focus:ring-0"
            @input="emit('update:modelValue', $event.target.value)"
        />
    </div>
</template>
