<script setup>
import AppField from './AppField.vue';
import { direction, languageName } from '@/lib/i18n';
import { FALLBACK_LOCALE, textLocales } from '@/lib/texts';

/**
 * One field per language the app speaks, for a data label (a name, a
 * description). v-model is { en: '…', bn: '…', ar: '…' }. Each field is
 * written in its own direction (Arabic right to left) and marked with its
 * language. `errors` may be keyed by "prefix.locale" (API) or by locale.
 */
const props = defineProps({
    modelValue: { type: Object, required: true },
    label: { type: String, required: true },
    required: Boolean,
    multiline: Boolean,
    maxlength: { type: Number, default: 150 },
    errors: { type: Object, default: () => ({}) },
    errorPrefix: { type: String, default: 'name' },
    autofocus: Boolean,
});

const emit = defineEmits(['update:modelValue']);

function update(locale, value) {
    emit('update:modelValue', { ...props.modelValue, [locale]: value });
}

function errorFor(locale) {
    return props.errors?.[`${props.errorPrefix}.${locale}`] ?? props.errors?.[locale] ?? null;
}
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2">
        <AppField
            v-for="locale in textLocales()"
            :key="locale"
            :label="`${label} (${languageName(locale)})`"
            :error="errorFor(locale)"
            :optional="!(required && locale === FALLBACK_LOCALE)"
        >
            <template #default="{ id, invalid, describedby }">
                <textarea
                    v-if="multiline"
                    :id="id"
                    :value="modelValue[locale] ?? ''"
                    rows="2"
                    class="field-input"
                    :maxlength="maxlength"
                    :lang="locale"
                    :dir="direction(locale)"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedby"
                    @input="update(locale, $event.target.value)"
                />
                <input
                    v-else
                    :id="id"
                    :value="modelValue[locale] ?? ''"
                    class="field-input"
                    :maxlength="maxlength"
                    :lang="locale"
                    :dir="direction(locale)"
                    :data-autofocus="autofocus && locale === FALLBACK_LOCALE ? '' : undefined"
                    :aria-invalid="invalid || undefined"
                    :aria-describedby="describedby"
                    @input="update(locale, $event.target.value)"
                />
            </template>
        </AppField>
    </div>
</template>
