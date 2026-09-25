<script setup>
import { onMounted, ref } from 'vue';
import { Check } from 'lucide-vue-next';
import { loadSectors } from '@/lib/packaging';
import { t } from '@/lib/i18n';

/**
 * Choose a company's sector from the sector packages, showing what each one
 * brings (modules and roles). Keyboard: a radio group.
 */
const props = defineProps({
    modelValue: { type: String, default: '' },
    labelledby: { type: String, default: null },
    describedby: { type: String, default: null },
    invalid: Boolean,
});

const emit = defineEmits(['update:modelValue']);

const sectors = ref(null);
const failed = ref(false);

onMounted(async () => {
    try {
        sectors.value = await loadSectors();
    } catch {
        failed.value = true;
    }
});

function summary(sector) {
    return [
        sector.modules.map((module) => module.name).join(', '),
        sector.roles.length ? t('packaging.sector.roles', { list: sector.roles.join(', ') }) : null,
    ]
        .filter(Boolean)
        .join(' · ');
}
</script>

<template>
    <div>
        <p v-if="failed" class="text-[12.5px] text-bad" role="alert">{{ t('packaging.sector.load_failed') }}</p>
        <div v-else-if="!sectors" class="grid gap-2 sm:grid-cols-2" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 4" :key="n" class="skeleton h-20 rounded-xl" />
        </div>
        <div
            v-else
            role="radiogroup"
            class="grid gap-2 sm:grid-cols-2"
            :aria-labelledby="labelledby"
            :aria-describedby="describedby"
            :aria-invalid="invalid || undefined"
        >
            <button
                v-for="sector in sectors"
                :key="sector.key"
                type="button"
                role="radio"
                :aria-checked="modelValue === sector.key"
                class="relative flex flex-col items-start gap-1 rounded-xl border p-3 text-start transition"
                :class="modelValue === sector.key ? 'border-brand bg-brand-soft/50 ring-1 ring-brand/30' : 'border-line bg-surface hover:border-line-strong'"
                @click="emit('update:modelValue', sector.key)"
            >
                <span class="flex w-full items-center justify-between gap-2">
                    <span class="text-[13.5px] font-medium text-fg">{{ sector.name }}</span>
                    <Check v-if="modelValue === sector.key" class="size-4 text-brand-text" aria-hidden="true" />
                </span>
                <span class="text-[12.5px] leading-snug text-muted">{{ sector.description }}</span>
                <span class="text-[11.5px] leading-snug text-faint">{{ summary(sector) }}</span>
            </button>
        </div>
    </div>
</template>
