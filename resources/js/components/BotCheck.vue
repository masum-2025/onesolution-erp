<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { signup } from '@/lib/identity';
import { i18n } from '@/lib/i18n';

/**
 * The "are you a person" check on public forms. With Cloudflare Turnstile
 * configured it shows the widget and gives its token; without one (local
 * development) it shows nothing and the token stays empty.
 */
const emit = defineEmits(['update:modelValue']);
defineProps({ modelValue: { type: String, default: null } });

const box = ref(null);
let widget = null;

const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

function loadScript() {
    if (window.turnstile) return Promise.resolve(window.turnstile);
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT;
        script.async = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = reject;
        document.head.appendChild(script);
    });
}

/** A token is used once: after a failed try the parent asks for a new one. */
function reset() {
    emit('update:modelValue', null);
    if (widget !== null) window.turnstile?.reset(widget);
}

defineExpose({ reset });

onMounted(async () => {
    if (signup.bot.driver !== 'turnstile' || !signup.bot.site_key) return;
    const turnstile = await loadScript().catch(() => null);
    if (!turnstile || !box.value) return;
    widget = turnstile.render(box.value, {
        sitekey: signup.bot.site_key,
        language: i18n.locale,
        callback: (token) => emit('update:modelValue', token),
        'expired-callback': () => emit('update:modelValue', null),
        'error-callback': () => emit('update:modelValue', null),
    });
});

onBeforeUnmount(() => {
    if (widget !== null) window.turnstile?.remove(widget);
});
</script>

<template>
    <div v-if="signup.bot.driver === 'turnstile'" ref="box" class="min-h-[65px]" />
</template>
