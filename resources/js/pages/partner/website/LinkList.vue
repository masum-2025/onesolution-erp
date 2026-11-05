<script setup>
import { ArrowDown, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, TranslatedFields } from '@/ui';
import { t } from '@/lib/i18n';

/**
 * A short list of links (a menu, a footer column): each a label per
 * language and an address. v-model is [{ label: { en, bn }, url }].
 * Errors are keyed as the API sends them ("<prefix>.0.label.en").
 */
const props = defineProps({
    modelValue: { type: Array, required: true },
    max: { type: Number, default: 8 },
    mainLocale: { type: String, default: 'en' },
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, required: true },
    disabled: Boolean,
    addLabel: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);

function change(index, patch) {
    emit('update:modelValue', props.modelValue.map((item, at) => (at === index ? { ...item, ...patch } : item)));
}
function add() {
    emit('update:modelValue', [...props.modelValue, { label: {}, url: '' }]);
}
function remove(index) {
    emit('update:modelValue', props.modelValue.filter((_, at) => at !== index));
}
function move(index, step) {
    const items = [...props.modelValue];
    [items[index], items[index + step]] = [items[index + step], items[index]];
    emit('update:modelValue', items);
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="(item, index) in modelValue" :key="index" class="rounded-xl border border-line p-3.5">
            <div class="grid gap-3 lg:grid-cols-[2fr_1fr_auto] lg:items-start">
                <TranslatedFields
                    :model-value="item.label ?? {}"
                    :label="t('website.links.label')"
                    required
                    :required-locale="mainLocale"
                    :maxlength="40"
                    :errors="errors"
                    :error-prefix="`${errorPrefix}.${index}.label`"
                    @update:model-value="change(index, { label: $event })"
                />
                <AppField :label="t('website.links.url')" :error="errors[`${errorPrefix}.${index}.url`]">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" :value="item.url ?? ''" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="/pricing" :disabled="disabled" :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="change(index, { url: $event.target.value })" />
                    </template>
                </AppField>
                <span v-if="!disabled" class="flex items-center gap-1 lg:pt-6">
                    <AppButton size="sm" variant="ghost" :icon="ArrowUp" :disabled="index === 0" :aria-label="t('website.move_up')" @click="move(index, -1)" />
                    <AppButton size="sm" variant="ghost" :icon="ArrowDown" :disabled="index === modelValue.length - 1" :aria-label="t('website.move_down')" @click="move(index, 1)" />
                    <AppButton size="sm" variant="danger-soft" :icon="Trash2" :aria-label="t('website.links.remove')" @click="remove(index)" />
                </span>
            </div>
        </div>
        <AppButton v-if="!disabled && modelValue.length < max" size="sm" :icon="Plus" @click="add">{{ addLabel }}</AppButton>
        <p class="text-[12px] text-muted">{{ t('website.links.hint') }}</p>
    </div>
</template>
