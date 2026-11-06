<script setup>
import { ref } from 'vue';
import { ImagePlus, X } from 'lucide-vue-next';
import { AppButton, AppField } from '@/ui';
import { t } from '@/lib/i18n';
import MediaPicker from './MediaPicker.vue';
import { websiteMedia } from './useWebsite';

/** One picture of the website (or none): its small copy, "choose" and "take out". */
const props = defineProps({
    modelValue: { type: String, default: null },
    label: { type: String, required: true },
    hint: { type: String, default: '' },
    error: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const picking = ref(false);

function pick(id) {
    picking.value = false;
    emit('update:modelValue', id);
}
</script>

<template>
    <AppField :label="label" :error="error" :hint="hint" optional>
        <template #default="{ id }">
            <div :id="id" class="flex flex-wrap items-center gap-3">
                <img v-if="props.modelValue" :src="websiteMedia.thumb(props.modelValue)" alt="" class="h-16 w-24 rounded-lg border border-line object-cover" />
                <span v-else class="flex h-16 w-24 items-center justify-center rounded-lg border border-dashed border-line text-faint"><ImagePlus class="size-5" aria-hidden="true" /></span>
                <AppButton size="sm" @click="picking = true">{{ props.modelValue ? t('website.media.change') : t('website.media.choose') }}</AppButton>
                <AppButton v-if="props.modelValue" size="sm" variant="ghost" :icon="X" @click="emit('update:modelValue', null)">{{ t('website.media.take_out') }}</AppButton>
            </div>
        </template>
    </AppField>
    <MediaPicker :open="picking" :selected="props.modelValue" @close="picking = false" @pick="pick" />
</template>
