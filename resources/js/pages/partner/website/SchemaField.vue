<script setup>
import { computed } from 'vue';
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, AppSwitch, DateInput, TranslatedFields } from '@/ui';
import { has, t } from '@/lib/i18n';
import { SWATCHES, blankFor } from './blockForm';

defineOptions({ name: 'SchemaField' });

/**
 * One field of a section's form, drawn from its description: a text per
 * language, a link, a number, a switch, a date, a choice (colours as
 * swatches), or a list of any of these (items added, moved, removed).
 * Errors are keyed as the API sends them ("data.slides.0.title.en").
 */
const props = defineProps({
    field: { type: Object, required: true },
    modelValue: { type: null, default: null },
    path: { type: String, required: true },
    errors: { type: Object, default: () => ({}) },
    mainLocale: { type: String, default: 'en' },
    choices: { type: Object, default: () => ({}) },
    // The whole section's data: a map's keys come from it (pricing features per chosen plan).
    root: { type: Object, default: () => ({}) },
    label: { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue']);

const name = computed(() => props.label || t(`website.fields.${props.field.key}`));
const error = computed(() => props.errors[props.path] ?? null);
const optional = computed(() => !props.field.required);

function optionLabel(value) {
    if (props.field.key === 'modules' || props.path.endsWith('.modules')) return props.choices.modules?.find((module) => module.key === value)?.name ?? value;
    if (props.field.key === 'icon') return t(`website.icons.${value}`);
    if (props.choices.plans?.includes(value)) return value.replace(/_/g, ' ').replace(/^./, (letter) => letter.toUpperCase());
    return has(`website.options.${value}`) ? t(`website.options.${value}`) : value;
}

// Lists
const items = computed(() => (Array.isArray(props.modelValue) ? props.modelValue : []));
const canAdd = computed(() => props.field.max == null || items.value.length < props.field.max);
const canRemove = computed(() => items.value.length > (props.field.min ?? 0));
function setItem(index, value) {
    emit('update:modelValue', items.value.map((item, at) => (at === index ? value : item)));
}
function addItem() {
    emit('update:modelValue', [...items.value, blankFor(props.field.item)]);
}
function removeItem(index) {
    emit('update:modelValue', items.value.filter((_, at) => at !== index));
}
function moveItem(index, step) {
    const list = [...items.value];
    [list[index], list[index + step]] = [list[index + step], list[index]];
    emit('update:modelValue', list);
}
function toggleChoice(value, on) {
    emit('update:modelValue', on ? [...items.value, value] : items.value.filter((item) => item !== value));
}

// Objects and maps
function setChild(key, value) {
    emit('update:modelValue', { ...(props.modelValue ?? {}), [key]: value });
}
const mapKeys = computed(() => (Array.isArray(props.root?.plans) ? props.root.plans : []));
</script>

<template>
    <!-- A text in each language -->
    <TranslatedFields
        v-if="field.kind === 'text'"
        :model-value="modelValue ?? {}"
        :label="name"
        :required="field.required"
        :required-locale="mainLocale"
        :multiline="field.multiline || field.key === 'body'"
        :maxlength="field.max ?? 300"
        :errors="errors"
        :error-prefix="path"
        @update:model-value="emit('update:modelValue', $event)"
    />

    <AppSwitch v-else-if="field.kind === 'boolean'" :model-value="!!modelValue" :label="name" show-label @update:model-value="emit('update:modelValue', $event)" />

    <!-- A colour: swatches -->
    <AppField v-else-if="field.kind === 'choice' && field.key === 'color'" :label="name" :error="error">
        <template #default="{ id }">
            <div :id="id" class="flex flex-wrap gap-2" role="radiogroup" :aria-label="name">
                <button
                    v-for="option in field.options"
                    :key="option"
                    type="button"
                    role="radio"
                    :aria-checked="modelValue === option"
                    :aria-label="t(`website.colors.${option}`)"
                    :title="t(`website.colors.${option}`)"
                    class="size-7 rounded-full ring-offset-2 ring-offset-surface transition"
                    :class="modelValue === option ? 'ring-2 ring-fg' : 'hover:scale-110'"
                    :style="{ background: SWATCHES[option] ?? option }"
                    @click="emit('update:modelValue', option)"
                />
            </div>
        </template>
    </AppField>

    <AppField v-else-if="field.kind === 'choice'" :label="name" :error="error" :optional="optional">
        <template #default="{ id, invalid, describedby }">
            <select :id="id" class="field-input" :value="modelValue ?? ''" :aria-invalid="invalid || undefined" :aria-describedby="describedby" @change="emit('update:modelValue', $event.target.value || null)">
                <option v-if="!field.required" value="">{{ t('website.none') }}</option>
                <option v-for="option in field.options" :key="option" :value="option">{{ optionLabel(option) }}</option>
            </select>
        </template>
    </AppField>

    <AppField v-else-if="['link', 'string', 'integer', 'date', 'datetime'].includes(field.kind)" :label="name" :error="error" :optional="optional" :hint="field.kind === 'link' ? t('website.links.short_hint') : ''">
        <template #default="{ id, invalid, describedby }">
            <DateInput v-if="field.kind === 'date'" :id="id" :model-value="modelValue ?? ''" @update:model-value="emit('update:modelValue', $event)" />
            <input
                v-else
                :id="id"
                :value="modelValue ?? ''"
                class="field-input"
                :class="{ 'font-mono': field.kind === 'link' }"
                :type="field.kind === 'integer' ? 'number' : field.kind === 'datetime' ? 'datetime-local' : 'text'"
                :min="field.min ?? undefined"
                :max="field.kind === 'integer' ? field.max ?? undefined : undefined"
                :maxlength="field.kind === 'link' || field.kind === 'string' ? field.max ?? undefined : undefined"
                :dir="field.kind === 'link' ? 'ltr' : undefined"
                :placeholder="field.kind === 'link' ? '/pricing' : ''"
                :aria-invalid="invalid || undefined"
                :aria-describedby="describedby"
                @input="emit('update:modelValue', field.kind === 'integer' ? ($event.target.value === '' ? null : Number($event.target.value)) : $event.target.value)"
            />
        </template>
    </AppField>

    <!-- Several choices (modules, plans): ticks -->
    <fieldset v-else-if="field.kind === 'list' && field.item.kind === 'choice'" class="space-y-2">
        <legend class="text-[13px] font-medium text-fg">{{ name }}</legend>
        <div class="grid gap-x-4 gap-y-1.5 sm:grid-cols-2">
            <label v-for="option in field.item.options" :key="option" class="flex items-center gap-2 text-[13px]">
                <input type="checkbox" :checked="items.includes(option)" :disabled="!items.includes(option) && !canAdd" @change="toggleChoice(option, $event.target.checked)" />
                {{ optionLabel(option) }}
            </label>
        </div>
        <p v-if="error" class="text-[12.5px] text-bad" role="alert">{{ error }}</p>
    </fieldset>

    <!-- A list of texts or of groups of fields -->
    <fieldset v-else-if="field.kind === 'list'" class="space-y-3">
        <legend class="text-[13px] font-medium text-fg">
            {{ name }}
            <span v-if="field.max" class="font-normal text-faint">· {{ t('website.up_to', { count: field.max }) }}</span>
        </legend>
        <div v-for="(item, index) in items" :key="index" class="rounded-xl border border-line p-3.5" :class="field.item.kind === 'object' ? 'space-y-4 bg-subtle/40' : ''">
            <div class="flex items-center gap-1">
                <span class="min-w-0 flex-1 text-[12.5px] font-medium text-muted">{{ name }} {{ index + 1 }}</span>
                <AppButton size="sm" variant="ghost" :icon="ArrowUp" :disabled="index === 0" :aria-label="t('website.move_up')" @click="moveItem(index, -1)" />
                <AppButton size="sm" variant="ghost" :icon="ArrowDown" :disabled="index === items.length - 1" :aria-label="t('website.move_down')" @click="moveItem(index, 1)" />
                <AppButton size="sm" variant="danger-soft" :icon="Trash2" :disabled="!canRemove" :aria-label="t('website.remove_item')" @click="removeItem(index)" />
            </div>
            <template v-if="field.item.kind === 'object'">
                <SchemaField
                    v-for="child in field.item.fields"
                    :key="child.key"
                    :field="child"
                    :model-value="item?.[child.key]"
                    :path="`${path}.${index}.${child.key}`"
                    :errors="errors"
                    :main-locale="mainLocale"
                    :choices="choices"
                    :root="root"
                    @update:model-value="setItem(index, { ...(item ?? {}), [child.key]: $event })"
                />
            </template>
            <SchemaField
                v-else
                :field="field.item"
                :model-value="item"
                :path="`${path}.${index}`"
                :errors="errors"
                :main-locale="mainLocale"
                :choices="choices"
                :root="root"
                :label="name"
                @update:model-value="setItem(index, $event)"
            />
        </div>
        <p v-if="error" class="text-[12.5px] text-bad" role="alert">{{ error }}</p>
        <AppButton v-if="canAdd" size="sm" :icon="Plus" @click="addItem">{{ t('website.add_item', { name }) }}</AppButton>
    </fieldset>

    <!-- One list per chosen plan (pricing features) -->
    <fieldset v-else-if="field.kind === 'map'" class="space-y-3">
        <legend class="text-[13px] font-medium text-fg">{{ name }}</legend>
        <p v-if="!mapKeys.length" class="text-[12.5px] text-muted">{{ t('website.map_empty') }}</p>
        <SchemaField
            v-for="key in mapKeys"
            :key="key"
            :field="{ ...field.item, key: field.key }"
            :model-value="modelValue?.[key] ?? []"
            :path="`${path}.${key}`"
            :errors="errors"
            :main-locale="mainLocale"
            :choices="choices"
            :root="root"
            :label="`${name} · ${optionLabel(key)}`"
            @update:model-value="setChild(key, $event)"
        />
    </fieldset>
</template>
