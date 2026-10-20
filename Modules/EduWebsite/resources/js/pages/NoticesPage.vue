<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { EyeOff, FileText, Globe, Megaphone, PenLine, Pin, Plus, Trash2, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cleanTexts, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import MediaPicker from '../components/MediaPicker.vue';
import { useSite } from '../useSite';

/**
 * The website's notice board. A published notice shows on the website at
 * once (no publish step), from its date on; pinned ones come first; a
 * hidden one stays here only. A notice may carry one PDF of the website's
 * files. "?new=1" (the header's "New" menu) opens the form.
 */
const route = useRoute();
const router = useRouter();
const { web, resource, site, meta, canManage, mainLocale, pick } = useSite();
const list = useResource(() => web.notices());
const notices = computed(() => list.data.value?.data ?? []);

const today = () => new Date().toLocaleDateString('en-CA');
const isFuture = (notice) => notice.published_on > today();

const editing = ref(null);
const form = reactive({ title: {}, body: {}, published_on: '', pinned: false, is_published: true, media_id: '' });
const errors = ref({});
const saving = ref(false);
const files = ref({});

function edit(notice = null) {
    Object.assign(form, {
        title: textsFor(notice?.title),
        body: textsFor(notice?.body),
        published_on: notice?.published_on ?? today(),
        pinned: notice?.pinned ?? false,
        is_published: notice?.is_published ?? true,
        media_id: notice?.media_id ?? '',
    });
    errors.value = {};
    editing.value = notice ?? 'new';
}

watch(
    [() => route.query.new, site],
    ([wanted, value]) => {
        if (wanted && value && canManage.value && editing.value === null) {
            edit();
            router.replace({ query: {} });
        }
    },
    { immediate: true },
);

// PDF names for notices that carry one.
useResource(async () => {
    files.value = Object.fromEntries((await web.media('pdf')).data.map((row) => [row.id, row]));
});

async function save() {
    const body = {
        title: cleanTexts(form.title),
        body: cleanTexts(form.body),
        published_on: form.published_on,
        pinned: form.pinned,
        is_published: form.is_published,
        media_id: form.media_id || null,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await web.createNotice(body);
        else await web.updateNotice(editing.value.id, { ...body, base_version: editing.value.version });
        toast.success(form.is_published ? t('edu_website.notices.saved_live') : t('edu_website.notices.saved_hidden'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(error.message);
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function remove(notice) {
    const confirmed = await confirmAction({
        title: t('edu_website.notices.remove_title'),
        message: t('edu_website.notices.remove_text', { title: pick(notice.title) }),
        confirmLabel: t('edu_website.common.remove'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await web.removeNotice(notice.id, notice.version);
        toast.success(t('edu_website.notices.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const picking = ref(false);
function pickFile(ids, rows) {
    rows.forEach((row) => (files.value[row.id] = row));
    form.media_id = ids[0] ?? '';
    picking.value = false;
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.notices.title')" :description="t('edu_website.notices.text')">
            <template v-if="site && canManage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('edu_website.notices.add') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <SkeletonRows v-else-if="!resource.data.value" :rows="4" />
        <section v-else-if="!site" class="card">
            <EmptyState :icon="Globe" :title="t('edu_website.overview.none')" :text="t('edu_website.common.start_first')">
                <AppButton variant="primary" to="/website">{{ t('edu_website.common.go_overview') }}</AppButton>
            </EmptyState>
        </section>

        <section v-else class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!notices.length" :icon="Megaphone" :title="t('edu_website.notices.empty')" :text="t('edu_website.notices.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="edit()">{{ t('edu_website.notices.add') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="notice in notices" :key="notice.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-5">
                    <time :datetime="notice.published_on" class="w-24 shrink-0 text-[12.5px] tabular text-muted">{{ formatDate(notice.published_on) }}</time>
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 text-[14px] font-medium">
                            <Pin v-if="notice.pinned" class="size-3.5 shrink-0 text-brand-text" :aria-label="t('edu_website.notices.pinned')" />
                            <span class="truncate">{{ pick(notice.title) }}</span>
                        </p>
                        <p v-if="pick(notice.body) || notice.media_id" class="truncate text-[12.5px] text-muted">
                            <FileText v-if="notice.media_id" class="me-1 inline size-3.5 align-[-2px]" aria-hidden="true" />{{ notice.media_id ? (files[notice.media_id]?.name ?? t('edu_website.notices.with_file')) + (pick(notice.body) ? ' · ' : '') : '' }}{{ pick(notice.body) }}
                        </p>
                    </div>
                    <AppBadge v-if="!notice.is_published" tone="neutral" :icon="EyeOff">{{ t('edu_website.notices.hidden') }}</AppBadge>
                    <AppBadge v-else-if="isFuture(notice)" tone="warn">{{ t('edu_website.notices.scheduled') }}</AppBadge>
                    <AppBadge v-else tone="ok" dot>{{ t('edu_website.notices.live') }}</AppBadge>
                    <div v-if="canManage" class="flex items-center gap-1">
                        <AppButton size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('edu_website.notices.edit')" @click="edit(notice)" />
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('edu_website.common.remove')" @click="remove(notice)" />
                    </div>
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" size="lg" :title="editing === 'new' ? t('edu_website.notices.add') : t('edu_website.notices.edit')" @close="editing = null">
            <form id="website-notice" class="grid gap-4" novalidate @submit.prevent="save">
                <TranslatedFields :required-locale="mainLocale" v-model="form.title" :label="t('edu_website.notices.notice_title')" required :maxlength="200" :errors="errors" error-prefix="title" />
                <TranslatedFields :required-locale="mainLocale" v-model="form.body" :label="t('edu_website.notices.body')" multiline :maxlength="5000" :errors="errors" error-prefix="body" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('edu_website.notices.date')" :hint="t('edu_website.notices.date_hint')" :error="errors.published_on?.[0] ?? null">
                        <input :id="id" v-model="form.published_on" type="date" class="field-input" />
                    </AppField>
                    <AppField :label="t('edu_website.notices.file')" :hint="t('edu_website.notices.file_hint')" :error="errors.media_id?.[0] ?? null" optional>
                        <div class="flex flex-wrap items-center gap-2">
                            <span v-if="form.media_id" class="flex min-w-0 items-center gap-1.5 rounded-md bg-subtle px-2 py-1 text-[13px]">
                                <FileText class="size-4 shrink-0" aria-hidden="true" /><span class="truncate">{{ files[form.media_id]?.name ?? '' }}</span>
                                <button type="button" class="text-muted hover:text-fg" :aria-label="t('edu_website.media.remove_from_block')" @click="form.media_id = ''"><X class="size-3.5" aria-hidden="true" /></button>
                            </span>
                            <AppButton size="sm" :icon="FileText" @click="picking = true">{{ form.media_id ? t('edu_website.media.change') : t('edu_website.media.choose') }}</AppButton>
                        </div>
                    </AppField>
                </div>
                <div class="grid gap-3 sm:grid-cols-2">
                    <AppSwitch v-model="form.is_published" :label="t('edu_website.notices.show')" show-label />
                    <AppSwitch v-model="form.pinned" :label="t('edu_website.notices.pin')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('edu_website.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="website-notice" :loading="saving" :disabled="!form.title[mainLocale]?.trim() || !form.published_on">{{ t('edu_website.common.save') }}</AppButton>
            </template>
        </AppDialog>

        <MediaPicker :open="picking" :web="web" kind="pdf" :selected="form.media_id ? [form.media_id] : []" :max-mb="meta.max_media_mb?.value ?? 5" :can-upload="canManage" @close="picking = false" @pick="pickFile" />
    </div>
</template>
