<script setup>
import { computed, ref } from 'vue';
import { FileText, Globe, ImagePlus, LoaderCircle, Trash2, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import { sizeParts } from '../lib';
import { useSite } from '../useSite';

/**
 * The website's pictures and PDFs. Pictures are drawn again as small WebP
 * copies by the server (large and thumbnail); the size limit comes from
 * the plan. A file the website uses (logo, a block, a notice) says so and
 * cannot be removed until taken out there.
 */
const { web, resource, site, meta, canManage } = useSite();
const kind = ref('');
const list = useResource(() => web.media(kind.value || undefined));
const rows = computed(() => list.data.value?.data ?? []);
const maxMb = computed(() => meta.value.max_media_mb?.value ?? 5);

const input = ref(null);
const uploading = ref([]); // names being sent

async function upload(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    let added = 0;
    for (const file of files) {
        if (file.size > maxMb.value * 1024 * 1024) {
            toast.error(t('edu_website.media.too_big', { name: file.name, mb: formatNumber(maxMb.value) }));
            continue;
        }
        uploading.value = [...uploading.value, file.name];
        try {
            await web.upload(file);
            added++;
        } catch (error) {
            toast.error(`${file.name}: ${error.errors?.file?.[0] ?? error.message}`);
        } finally {
            uploading.value = uploading.value.filter((name) => name !== file.name);
        }
    }
    if (added) {
        toast.success(t('edu_website.media.uploaded', { count: formatNumber(added) }));
        list.reload();
    }
}

async function remove(row) {
    const confirmed = await confirmAction({
        title: t('edu_website.media.remove_title'),
        message: t('edu_website.media.remove_text', { name: row.name }),
        confirmLabel: t('edu_website.common.remove'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await web.removeMedia(row.id);
        toast.success(t('edu_website.media.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

function size(bytes) {
    const { value, unit } = sizeParts(bytes);

    return t(`edu_website.media.size_${unit}`, { value: formatNumber(value) });
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.media.title')" :description="t('edu_website.media.text')">
            <template v-if="site && canManage" #actions>
                <input ref="input" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp,application/pdf" multiple @change="upload" />
                <AppButton variant="primary" :icon="Upload" :loading="uploading.length > 0" @click="input?.click()">{{ t('edu_website.media.upload') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <SkeletonRows v-else-if="!resource.data.value" :rows="4" />
        <section v-else-if="!site" class="card">
            <EmptyState :icon="Globe" :title="t('edu_website.overview.none')" :text="t('edu_website.common.start_first')">
                <AppButton variant="primary" to="/website">{{ t('edu_website.common.go_overview') }}</AppButton>
            </EmptyState>
        </section>

        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <AppSegmented
                    v-model="kind"
                    size="sm"
                    :label="t('edu_website.media.filter')"
                    :options="[
                        { value: '', label: t('edu_website.media.all') },
                        { value: 'image', label: t('edu_website.media.pictures') },
                        { value: 'pdf', label: t('edu_website.media.files') },
                    ]"
                    @update:model-value="list.reload()"
                />
                <span class="ms-auto flex flex-wrap items-center gap-2 text-[12.5px] text-muted">
                    {{ t('edu_website.media.limit', { mb: formatNumber(maxMb) }) }}
                    <SourceBadge v-if="meta.max_media_mb?.source" v-bind="meta.max_media_mb.source" />
                </span>
            </div>

            <ul v-if="uploading.length" class="space-y-1" aria-live="polite">
                <li v-for="name in uploading" :key="name" class="flex items-center gap-2 text-[13px] text-fg-2">
                    <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />{{ t('edu_website.media.uploading', { name }) }}
                </li>
            </ul>

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
            <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
            <section v-else-if="!rows.length" class="card">
                <EmptyState :icon="ImagePlus" :title="t('edu_website.media.empty')" :text="t('edu_website.media.empty_text', { mb: formatNumber(maxMb) })">
                    <AppButton v-if="canManage" variant="primary" :icon="Upload" @click="input?.click()">{{ t('edu_website.media.upload') }}</AppButton>
                </EmptyState>
            </section>
            <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                <li v-for="row in rows" :key="row.id" class="card overflow-hidden">
                    <a :href="row.url" target="_blank" rel="noopener" class="block bg-subtle">
                        <img v-if="row.thumb" :src="row.thumb" :alt="row.name" class="aspect-[4/3] w-full object-cover" loading="lazy" />
                        <span v-else class="flex aspect-[4/3] w-full flex-col items-center justify-center gap-1 text-muted">
                            <FileText class="size-9" aria-hidden="true" /><span class="text-[11px] font-semibold uppercase">PDF</span>
                        </span>
                    </a>
                    <div class="flex items-start gap-2 p-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-[13px] font-medium" :title="row.name">{{ row.name }}</p>
                            <p class="text-[12px] text-muted">
                                {{ size(row.size_bytes) }}<template v-if="row.width"> · <span dir="ltr">{{ formatNumber(row.width, { useGrouping: false }) }}×{{ formatNumber(row.height, { useGrouping: false }) }}</span></template>
                            </p>
                            <AppBadge v-if="row.in_use" class="mt-1.5" tone="brand">{{ t('edu_website.media.in_use') }}</AppBadge>
                        </div>
                        <AppButton
                            v-if="canManage"
                            size="icon-sm"
                            variant="ghost"
                            :icon="Trash2"
                            :disabled="row.in_use"
                            :title="row.in_use ? t('edu_website.media.in_use_hint') : undefined"
                            :aria-label="row.in_use ? t('edu_website.media.in_use_hint') : t('edu_website.common.remove')"
                            @click="remove(row)"
                        />
                    </div>
                </li>
            </ul>
        </template>
    </div>
</template>
