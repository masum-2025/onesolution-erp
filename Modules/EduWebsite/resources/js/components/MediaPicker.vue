<script setup>
import { computed, ref, watch } from 'vue';
import { Check, FileText, ImagePlus, Upload } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Choosing pictures (or PDFs) of the website for a block, the logo or a
 * notice, with an upload right here. `multiple` keeps the order picked.
 * Emits `pick` with the chosen ids (and the media rows, for thumbnails).
 */
const props = defineProps({
    open: Boolean,
    web: { type: Object, required: true },
    kind: { type: String, default: 'image' }, // image | pdf
    multiple: Boolean,
    selected: { type: Array, default: () => [] },
    maxMb: { type: Number, default: 5 },
    canUpload: Boolean,
});
const emit = defineEmits(['close', 'pick']);

const rows = ref([]);
const loading = ref(false);
const error = ref(null);
const chosen = ref([]);
const uploading = ref(false);
const input = ref(null);

async function load() {
    loading.value = true;
    error.value = null;
    try {
        rows.value = (await props.web.media(props.kind)).data;
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
        chosen.value = [...props.selected];
        load();
    },
    { immediate: true },
);

function toggle(id) {
    if (!props.multiple) {
        chosen.value = [id];
        return;
    }
    chosen.value = chosen.value.includes(id) ? chosen.value.filter((value) => value !== id) : [...chosen.value, id];
}

const accept = computed(() => (props.kind === 'pdf' ? 'application/pdf' : 'image/jpeg,image/png,image/webp'));

async function upload(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    uploading.value = true;
    for (const file of files) {
        if (file.size > props.maxMb * 1024 * 1024) {
            toast.error(t('edu_website.media.too_big', { name: file.name, mb: formatNumber(props.maxMb) }));
            continue;
        }
        try {
            const media = (await props.web.upload(file)).data;
            rows.value = [media, ...rows.value];
            toggle(media.id);
        } catch (caught) {
            toast.error(caught.errors?.file?.[0] ?? caught.message);
        }
    }
    uploading.value = false;
}

function done() {
    emit('pick', chosen.value, rows.value);
}
</script>

<template>
    <AppDialog :open="open" size="lg" :title="kind === 'pdf' ? t('edu_website.media.pick_pdf') : t('edu_website.media.pick_image')" @close="emit('close')">
        <div class="space-y-4">
            <div v-if="canUpload" class="flex flex-wrap items-center gap-3">
                <input ref="input" type="file" class="sr-only" :accept="accept" multiple @change="upload" />
                <AppButton :icon="Upload" :loading="uploading" @click="input?.click()">{{ t('edu_website.media.upload') }}</AppButton>
                <span class="text-[12.5px] text-muted">{{ t(kind === 'pdf' ? 'edu_website.media.pdf_hint' : 'edu_website.media.image_hint', { mb: formatNumber(maxMb) }) }}</span>
            </div>
            <SkeletonRows v-if="loading && !rows.length" :rows="3" />
            <ErrorState v-else-if="error" compact :error="error" @retry="load" />
            <EmptyState v-else-if="!rows.length" :icon="kind === 'pdf' ? FileText : ImagePlus" :title="t('edu_website.media.empty')" :text="t('edu_website.media.empty_pick')" compact />
            <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4" role="listbox" :aria-multiselectable="multiple || undefined" :aria-label="t('edu_website.media.title')">
                <li v-for="row in rows" :key="row.id" role="option" :aria-selected="chosen.includes(row.id)">
                    <button
                        type="button"
                        class="group relative block w-full overflow-hidden rounded-lg border bg-subtle text-start outline-none transition focus-visible:ring-2 focus-visible:ring-brand"
                        :class="chosen.includes(row.id) ? 'border-brand ring-2 ring-brand' : 'border-line hover:border-line-strong'"
                        @click="toggle(row.id)"
                    >
                        <img v-if="row.thumb" :src="row.thumb" alt="" class="aspect-[4/3] w-full object-cover" loading="lazy" />
                        <span v-else class="flex aspect-[4/3] w-full items-center justify-center text-muted"><FileText class="size-8" aria-hidden="true" /></span>
                        <span class="block truncate px-2 py-1.5 text-[12px]">{{ row.name }}</span>
                        <span v-if="chosen.includes(row.id)" class="absolute end-1.5 top-1.5 flex size-6 items-center justify-center rounded-full bg-brand text-[11px] font-semibold text-white">
                            <template v-if="multiple">{{ formatNumber(chosen.indexOf(row.id) + 1) }}</template>
                            <Check v-else class="size-4" aria-hidden="true" />
                        </span>
                    </button>
                </li>
            </ul>
        </div>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('edu_website.common.cancel') }}</AppButton>
            <AppButton variant="primary" :disabled="!chosen.length" @click="done">
                {{ multiple ? t('edu_website.media.use_count', { count: formatNumber(chosen.length) }) : t('edu_website.media.use') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
