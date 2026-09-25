<script setup>
import { computed, useId } from 'vue';
import { CircleAlert } from 'lucide-vue-next';
import { t } from '@/lib/i18n';

const props = defineProps({
    label: { type: String, default: '' },
    hint: { type: String, default: '' },
    error: { type: String, default: null },
    optional: Boolean,
    srOnlyLabel: Boolean,
});

const id = useId();
const describedby = computed(() => (props.error ? `${id}-error` : props.hint ? `${id}-hint` : undefined));
</script>

<template>
    <div class="space-y-1.5">
        <div v-if="label" class="flex items-baseline justify-between gap-3" :class="srOnlyLabel ? 'sr-only' : ''">
            <label :for="id" class="text-[13px] font-medium text-fg">
                {{ label }}
                <span v-if="optional" class="font-normal text-faint">· {{ t('core.optional') }}</span>
            </label>
            <slot name="aside" />
        </div>
        <slot :id="id" :invalid="!!error" :describedby="describedby" />
        <p v-if="error" :id="`${id}-error`" class="flex items-start gap-1.5 text-[12.5px] leading-snug text-bad" role="alert">
            <CircleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ error }}</span>
        </p>
        <p v-else-if="hint" :id="`${id}-hint`" class="text-[12.5px] leading-snug text-muted">{{ hint }}</p>
    </div>
</template>
