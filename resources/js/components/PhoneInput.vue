<script setup>
import { computed } from 'vue';
import { t } from '@/lib/i18n';

/**
 * A mobile number with its country. People type it the way they know it
 * (01712…, +8801712…); the server writes it in one form.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    country: { type: String, default: 'BD' },
    countries: { type: Array, default: () => [] }, // [{ code, dial }]
    id: { type: String, default: undefined },
    invalid: Boolean,
    describedby: { type: String, default: undefined },
    autocomplete: { type: String, default: 'tel-national' },
});

const emit = defineEmits(['update:modelValue', 'update:country']);

const options = computed(() => (props.countries.length ? props.countries : [{ code: props.country, dial: '' }]));
</script>

<template>
    <div class="flex gap-2" dir="ltr">
        <select
            :value="country"
            class="field-input h-11 w-[6.5rem] shrink-0 pe-7"
            :aria-label="t('identity.fields.country')"
            @change="emit('update:country', $event.target.value)"
        >
            <option v-for="option in options" :key="option.code" :value="option.code">{{ option.code }} +{{ option.dial }}</option>
        </select>
        <input
            :id="id"
            :value="modelValue"
            type="tel"
            inputmode="tel"
            :autocomplete="autocomplete"
            class="field-input h-11 min-w-0 flex-1"
            :placeholder="t('identity.fields.phone_placeholder')"
            :aria-invalid="invalid || undefined"
            :aria-describedby="describedby"
            @input="emit('update:modelValue', $event.target.value)"
        />
    </div>
</template>
