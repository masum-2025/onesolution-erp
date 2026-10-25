<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Download, Inbox, MessageSquareText, NotebookPen, Phone, Search, UserPlus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { formatNumber, formatRelative } from '@/lib/format';
import { t } from '@/lib/i18n';
import { can } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import { useSite } from '../useSite';

/**
 * Admission enquiries from the website, for the people who handle them:
 * by status (new first), a search over names and numbers, a call by tap,
 * the status and a note of the office, the CSV, and "start an application"
 * in Education with what the enquiry already says.
 */
const router = useRouter();
const { web } = useSite();
const STATUSES = ['new', 'contacted', 'admitted', 'closed'];
const TONES = { new: 'warn', contacted: 'brand', admitted: 'ok', closed: 'neutral' };

const status = ref('new');
const search = ref('');
const list = useResource(() => web.enquiries({ status: status.value === 'all' ? undefined : status.value, q: search.value.trim() || undefined }));
const rows = computed(() => list.data.value?.data ?? []);
const counts = computed(() => list.data.value?.meta?.counts ?? {});
const tabs = computed(() => [
    ...STATUSES.map((key) => ({ key, label: t(`edu_website.enquiries.status_${key}`), count: counts.value[key] ?? 0 })),
    { key: 'all', label: t('edu_website.enquiries.all') },
]);

let timer = null;
watch(status, () => list.reload());
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => list.reload(), 350);
});

const busy = ref(null);
async function setStatus(enquiry, next) {
    if (next === enquiry.status) return;
    busy.value = enquiry.id;
    try {
        await web.updateEnquiry(enquiry.id, { status: next, base_version: enquiry.version });
        toast.success(t('edu_website.enquiries.moved', { status: t(`edu_website.enquiries.status_${next}`) }));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
        list.reload();
    }
}

const noting = ref(null);
const note = ref('');
const saving = ref(false);
function openNote(enquiry) {
    noting.value = enquiry;
    note.value = enquiry.note ?? '';
}
async function saveNote() {
    saving.value = true;
    try {
        await web.updateEnquiry(noting.value.id, { note: note.value, base_version: noting.value.version });
        toast.success(t('edu_website.enquiries.note_saved'));
        noting.value = null;
        list.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') {
            noting.value = null;
            list.reload();
        }
    } finally {
        saving.value = false;
    }
}

// A new application in Education with the enquiry's details (in the history state, never in the address).
function startApplication(enquiry) {
    router.push({
        name: 'education-admissions',
        query: { new: '1' },
        state: { prefill: { name: enquiry.student_name, guardian: enquiry.guardian_name, phone: enquiry.phone, level_id: enquiry.level_id } },
    });
}

const downloading = ref(false);
async function download() {
    downloading.value = true;
    try {
        const blob = await web.exportEnquiries(status.value === 'all' ? null : status.value);
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = `enquiries-${new Date().toISOString().slice(0, 10)}.csv`;
        link.click();
        URL.revokeObjectURL(link.href);
    } catch (error) {
        toast.error(error.message);
    } finally {
        downloading.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.enquiries.title')" :description="t('edu_website.enquiries.text')">
            <template #actions>
                <AppButton :icon="Download" :loading="downloading" :disabled="!rows.length" @click="download">{{ t('edu_website.enquiries.download') }}</AppButton>
            </template>
        </PageHeader>

        <div class="flex flex-wrap items-center gap-3">
            <AppTabs v-model="status" :tabs="tabs" :label="t('edu_website.enquiries.title')" />
            <label class="relative ms-auto w-full sm:w-72">
                <span class="sr-only">{{ t('edu_website.enquiries.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="search" type="search" class="field-input ps-9" :placeholder="t('edu_website.enquiries.search')" maxlength="100" />
            </label>
        </div>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!rows.length" class="card">
            <EmptyState :icon="Inbox" :title="search ? t('edu_website.enquiries.none_found') : t('edu_website.enquiries.empty')" :text="t('edu_website.enquiries.empty_text')" />
        </section>
        <ul v-else class="grid gap-4 lg:grid-cols-2">
            <li v-for="enquiry in rows" :key="enquiry.id" class="card flex flex-col gap-3 p-4 sm:p-5">
                <div class="flex flex-wrap items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <p class="text-[15px] font-semibold">{{ enquiry.student_name }}</p>
                        <p class="text-[13px] text-muted">{{ t('edu_website.enquiries.guardian', { name: enquiry.guardian_name }) }} · {{ formatRelative(enquiry.created_at) }}</p>
                    </div>
                    <AppBadge v-if="enquiry.level_name" tone="outline">{{ textIn(enquiry.level_name) }}</AppBadge>
                    <AppBadge :tone="TONES[enquiry.status]" dot>{{ t(`edu_website.enquiries.status_${enquiry.status}`) }}</AppBadge>
                </div>

                <a :href="`tel:${enquiry.phone}`" class="inline-flex w-fit items-center gap-2 rounded-lg bg-brand-soft px-3 py-1.5 font-mono text-[14px] font-medium text-brand-text hover:bg-brand hover:text-white" dir="ltr">
                    <Phone class="size-4" aria-hidden="true" />{{ enquiry.phone }}
                </a>
                <p v-if="enquiry.message" class="flex gap-2 rounded-lg bg-subtle px-3 py-2 text-[13.5px]">
                    <MessageSquareText class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" /><span class="whitespace-pre-line">{{ enquiry.message }}</span>
                </p>
                <p v-if="enquiry.note" class="flex gap-2 text-[13px] text-fg-2">
                    <NotebookPen class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" /><span class="whitespace-pre-line">{{ enquiry.note }}</span>
                </p>

                <div class="mt-auto flex flex-wrap items-center gap-2 border-t border-line pt-3">
                    <label class="sr-only" :for="`status-${enquiry.id}`">{{ t('edu_website.enquiries.status') }}</label>
                    <select :id="`status-${enquiry.id}`" class="field-input h-9 w-auto py-1 text-[13px]" :value="enquiry.status" :disabled="busy === enquiry.id" @change="setStatus(enquiry, $event.target.value)">
                        <option v-for="key in STATUSES" :key="key" :value="key">{{ t(`edu_website.enquiries.status_${key}`) }}</option>
                    </select>
                    <AppButton size="sm" variant="ghost" :icon="NotebookPen" @click="openNote(enquiry)">{{ enquiry.note ? t('edu_website.enquiries.edit_note') : t('edu_website.enquiries.add_note') }}</AppButton>
                    <AppButton v-if="can('education.admit')" size="sm" class="ms-auto" :icon="UserPlus" @click="startApplication(enquiry)">{{ t('edu_website.enquiries.apply') }}</AppButton>
                </div>
            </li>
        </ul>
        <p v-if="rows.length" class="text-center text-[12.5px] text-muted">{{ t('edu_website.enquiries.kept', { count: formatNumber(rows.length) }) }}</p>

        <AppDialog :open="noting !== null" :title="t('edu_website.enquiries.note')" @close="noting = null">
            <AppField v-slot="{ id }" :label="t('edu_website.enquiries.note')" :hint="t('edu_website.enquiries.note_hint')">
                <textarea :id="id" v-model="note" rows="4" class="field-input" maxlength="2000" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="noting = null">{{ t('edu_website.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" @click="saveNote">{{ t('edu_website.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
