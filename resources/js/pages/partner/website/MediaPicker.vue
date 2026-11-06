<script setup>
import { ref, watch } from 'vue';
import { Check, ImagePlus, Upload } from 'lucide-vue-next';
import { AppButton, AppDialog, EmptyState, ErrorState, SkeletonRows, toast } from '@/ui';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { websiteMedia } from './useWebsite';

/**
 * Choosing one of the website's pictures for a section or the settings,
 * with an upload right here (owners and sales staff). Emits `pick` with
 * the chosen id. The size limit shown comes from the platform's rule.
 */
const props = defineProps({
    open: Boolean,
    selected: { type: String, default: null },
});
const emit = defineEmits(['close', 'pick']);

const rows = ref([]);
const meta = ref({});
const loading = ref(false);
const error = ref(null);
const chosen = ref(null);
const uploading = ref(false);
const input = ref(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        const response = await websiteMedia.list();
        rows.value = response.data;
        meta.value = response.meta ?? {};
    } catch (caught) {
        error.value = caught;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        chosen.value = props.selected;
        load();
    },
    { immediate: true },
);

async function upload(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    uploading.value = true;
    for (const file of files) {
        if (meta.value.max_mb && file.size > meta.value.max_mb * 1024 * 1024) {
            toast.error(t('website.media.too_big', { name: file.name, mb: formatNumber(meta.value.max_mb) }));
            continue;
        }
        try {
            const media = (await websiteMedia.upload(file)).data;
            rows.value = [media, ...rows.value];
            chosen.value = media.id;
        } catch (caught) {
            toast.error(caught.errors?.file?.[0] ?? caught.message);
        }
    }
    uploading.value = false;
}
</script>

<template>
    <AppDialog :open="open" size="lg" :title="t('website.media.pick')" @close="emit('close')">
        <div class="space-y-4">
            <div v-if="meta.can_write" class="flex flex-wrap items-center gap-3">
                <input ref="input" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" multiple @change="upload" />
                <AppButton :icon="Upload" :loading="uploading" @click="input?.click()">{{ t('website.media.upload') }}</AppButton>
                <span class="text-[12.5px] text-muted">{{ t('website.media.hint', { mb: formatNumber(meta.max_mb ?? 5) }) }}</span>
            </div>
            <SkeletonRows v-if="loading && !rows.length" :rows="3" />
            <ErrorState v-else-if="error" compact :error="error" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="ImagePlus" :title="t('website.media.empty')" :text="t('website.media.empty_pick')" compact />
            <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4" role="listbox" :aria-label="t('website.media.title')">
                <li v-for="row in rows" :key="row.id" role="option" :aria-selected="chosen === row.id">
                    <button
                        type="button"
                        class="relative block w-full overflow-hidden rounded-lg border bg-subtle text-start outline-none transition focus-visible:ring-2 focus-visible:ring-brand"
                        :class="chosen === row.id ? 'border-brand ring-2 ring-brand' : 'border-line hover:border-line-strong'"
                        @click="chosen = row.id"
                    >
                        <img :src="row.thumb" alt="" class="aspect-[4/3] w-full object-cover" loading="lazy" />
                        <span class="block truncate px-2 py-1.5 text-[12px]">{{ row.name }}</span>
                        <span v-if="chosen === row.id" class="absolute end-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-brand text-white">
                            <Check class="size-4" aria-hidden="true" />
                        </span>
                    </button>
                </li>
            </ul>
        </div>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('website.media.cancel') }}</AppButton>
            <AppButton variant="primary" :disabled="!chosen" @click="emit('pick', chosen)">{{ t('website.media.use') }}</AppButton>
        </template>
    </AppDialog>
</template>
