<script setup>
import { computed, reactive, ref } from 'vue';
import { ImagePlus, Lock, Pencil, Trash2, Upload } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, EmptyState, PageFrame, TranslatedFields, confirmAction, toast } from '@/ui';
import { formatDate, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { useResource } from '@/lib/useResource';
import WebsiteNav from './WebsiteNav.vue';
import { websiteMedia } from './useWebsite';

/**
 * The website's pictures: upload (JPG, PNG, WebP; made smaller as WebP),
 * name them and give the words read out instead, remove the unused ones.
 * A picture in use (a section, the settings, a kept version) stays.
 * Owners and sales staff change them; everyone else looks.
 */
const resource = useResource(() => websiteMedia.list());
const rows = computed(() => resource.data.value?.data ?? []);
const meta = computed(() => resource.data.value?.meta ?? {});
const full = computed(() => meta.value.max_count != null && rows.value.length >= meta.value.max_count);

const input = ref(null);
const uploading = ref(false);

async function upload(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    uploading.value = true;
    let added = 0;
    for (const file of files) {
        if (meta.value.max_mb && file.size > meta.value.max_mb * 1024 * 1024) {
            toast.error(t('website.media.too_big', { name: file.name, mb: formatNumber(meta.value.max_mb) }));
            continue;
        }
        try {
            await websiteMedia.upload(file);
            added++;
        } catch (caught) {
            toast.error(caught.errors?.file?.[0] ?? caught.message);
        }
    }
    uploading.value = false;
    if (added) {
        toast.success(t('website.media.added', { count: formatNumber(added) }));
        resource.reload();
    }
}

// Name and words read out instead
const editing = ref(null);
const form = reactive({ name: '', alt: {} });
const errors = ref({});
const saving = ref(false);
function edit(row) {
    errors.value = {};
    Object.assign(form, { name: row.name, alt: { ...(row.alt ?? {}) } });
    editing.value = row;
}
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await websiteMedia.update(editing.value.id, { name: form.name, alt: form.alt });
        toast.success(t('website.media.saved'));
        editing.value = null;
        resource.reload();
    } catch (caught) {
        errors.value = Object.fromEntries(Object.entries(caught.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        if (!caught.errors) toast.error(caught.message);
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    const confirmed = await confirmAction({
        title: t('website.media.remove_title', { name: row.name }),
        message: t('website.media.remove_text'),
        confirmLabel: t('website.media.remove'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await websiteMedia.remove(row.id);
        toast.success(t('website.media.removed'));
        resource.reload();
    } catch (caught) {
        toast.error(caught.message);
    }
}

const exported = computed(() => ({
    module: 'partner',
    page: 'partner.website_media',
    columns: [
        { key: 'name', label: t('website.media.name') },
        { key: 'size', label: t('website.media.size'), value: (row) => `${formatNumber(row.width)} × ${formatNumber(row.height)}` },
        { key: 'kb', label: t('website.media.kb'), value: (row) => formatNumber(Math.round(row.size_bytes / 1024)) },
        { key: 'in_use', label: t('website.media.in_use'), value: (row) => (row.in_use ? t('website.media.yes') : t('website.media.no')) },
        { key: 'created_at', label: t('website.media.added_on'), value: (row) => (row.created_at ? formatDate(row.created_at) : '') },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.media.text')" :resource="resource" width="lg" :export="exported">
        <template #actions>
            <template v-if="meta.can_write">
                <input ref="input" type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" multiple @change="upload" />
                <AppButton variant="primary" :icon="Upload" :loading="uploading" :disabled="full" @click="input?.click()">{{ t('website.media.upload') }}</AppButton>
            </template>
        </template>

        <WebsiteNav />

        <p v-if="!meta.can_write" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
            <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('website.read_only') }}
        </p>
        <p class="mb-4 text-[12.5px] text-muted">
            {{ t('website.media.hint', { mb: formatNumber(meta.max_mb ?? 5) }) }}
            · {{ t('website.media.count', { count: formatNumber(rows.length), max: formatNumber(meta.max_count ?? 0) }) }}
        </p>
        <p v-if="full" class="mb-4 rounded-xl bg-warn-soft p-3.5 text-[13px] text-fg" role="status">{{ t('website.media.full') }}</p>

        <EmptyState v-if="!rows.length" :icon="ImagePlus" :title="t('website.media.empty')" :text="t('website.media.empty_text')" />
        <ul v-else class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            <li v-for="row in rows" :key="row.id" class="card overflow-hidden">
                <img :src="row.thumb" :alt="row.alt?.[Object.keys(row.alt ?? {})[0]] ?? ''" class="aspect-[4/3] w-full bg-subtle object-cover" loading="lazy" />
                <div class="space-y-1.5 p-3">
                    <p class="truncate text-[13px] font-medium text-fg" :title="row.name">{{ row.name }}</p>
                    <p class="text-[12px] text-muted">{{ formatNumber(row.width) }} × {{ formatNumber(row.height) }} · {{ formatNumber(Math.round(row.size_bytes / 1024)) }} KB</p>
                    <div class="flex items-center gap-1">
                        <AppBadge v-if="row.in_use" tone="ok">{{ t('website.media.in_use') }}</AppBadge>
                        <span class="flex-1" />
                        <template v-if="meta.can_write">
                            <AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="t('website.media.edit')" @click="edit(row)" />
                            <AppButton
                                size="sm"
                                variant="danger-soft"
                                :icon="Trash2"
                                :disabled="row.in_use"
                                :title="row.in_use ? t('website.media.in_use_hint') : ''"
                                :aria-label="t('website.media.remove')"
                                @click="remove(row)"
                            />
                        </template>
                    </div>
                </div>
            </li>
        </ul>

        <AppDialog :open="!!editing" :title="t('website.media.edit')" @close="editing = null">
            <form id="media-form" class="space-y-4" @submit.prevent="save">
                <AppField :label="t('website.media.name')" :error="errors.name">
                    <template #default="{ id }"><input :id="id" v-model="form.name" class="field-input" maxlength="150" required /></template>
                </AppField>
                <TranslatedFields v-model="form.alt" :label="t('website.media.alt')" :maxlength="200" :errors="errors" error-prefix="alt" />
                <p class="text-[12.5px] text-muted">{{ t('website.media.alt_hint') }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('website.media.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="media-form" :loading="saving">{{ t('website.media.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
