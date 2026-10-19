<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { CircleAlert, CircleCheck, LoaderCircle } from 'lucide-vue-next';
import AppField from '@/components/AppField.vue';
import { t } from '@/lib/i18n';

/**
 * The website's address (the "abc-school" of abc-school.<sites domain>),
 * checked while typed: its form, and whether another website has it. The
 * server checks again when saving. `current` is the site's own address
 * (always fine for it).
 */
const props = defineProps({
    modelValue: { type: String, required: true },
    web: { type: Object, required: true },
    domain: { type: String, default: '' },
    current: { type: String, default: '' },
    error: { type: String, default: null },
    disabled: Boolean,
});
const emit = defineEmits(['update:modelValue', 'state']);

// idle | checking | ok | taken | invalid | failed
const state = ref('idle');
let timer = null;
let controller = null;

function set(value) {
    state.value = value;
    emit('state', value);
}

watch(
    () => props.modelValue,
    (value) => {
        clearTimeout(timer);
        controller?.abort();
        const slug = value.trim().toLowerCase();
        if (!slug || slug === props.current) {
            set(slug ? 'ok' : 'idle');
            return;
        }
        if (slug.length < 3 || !/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/.test(slug)) {
            set('invalid');
            return;
        }
        set('checking');
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const result = (await props.web.addressCheck(slug, controller.signal)).data;
                set(!result.valid ? 'invalid' : result.available ? 'ok' : 'taken');
            } catch (caught) {
                if (caught?.name !== 'AbortError') set('failed');
            }
        }, 450);
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    clearTimeout(timer);
    controller?.abort();
});

function input(event) {
    emit('update:modelValue', event.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''));
}
</script>

<template>
    <AppField v-slot="{ id, describedby }" :label="t('edu_website.settings.address')" :hint="t('edu_website.settings.address_hint')" :error="error">
        <div class="flex items-stretch overflow-hidden rounded-lg border border-line bg-surface focus-within:ring-2 focus-within:ring-brand" dir="ltr">
            <input
                :id="id"
                :value="modelValue"
                class="min-w-0 flex-1 bg-transparent px-3 py-2 font-mono text-[14px] outline-none"
                maxlength="63"
                autocomplete="off"
                spellcheck="false"
                :disabled="disabled"
                :aria-describedby="`${describedby ?? ''} ${id}-state`"
                @input="input"
            />
            <span v-if="domain" class="flex items-center border-s border-line bg-subtle px-3 font-mono text-[13px] text-muted">.{{ domain }}</span>
        </div>
        <p :id="`${id}-state`" class="mt-1.5 flex items-center gap-1.5 text-[12.5px]" aria-live="polite">
            <template v-if="state === 'checking'"><LoaderCircle class="size-4 animate-spin text-muted" aria-hidden="true" /><span class="text-muted">{{ t('edu_website.settings.address_checking') }}</span></template>
            <template v-else-if="state === 'ok' && modelValue !== current"><CircleCheck class="size-4 text-ok" aria-hidden="true" /><span class="text-ok">{{ t('edu_website.settings.address_free') }}</span></template>
            <template v-else-if="state === 'taken'"><CircleAlert class="size-4 text-bad" aria-hidden="true" /><span class="text-bad">{{ t('edu_website.settings.address_taken') }}</span></template>
            <template v-else-if="state === 'invalid'"><CircleAlert class="size-4 text-warn" aria-hidden="true" /><span class="text-warn">{{ t('edu_website.settings.address_invalid') }}</span></template>
            <template v-else-if="state === 'failed'"><CircleAlert class="size-4 text-warn" aria-hidden="true" /><span class="text-muted">{{ t('edu_website.settings.address_unknown') }}</span></template>
        </p>
    </AppField>
</template>
