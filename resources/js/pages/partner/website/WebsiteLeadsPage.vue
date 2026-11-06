<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Inbox, Mail, Phone, Search, Trash2 } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, EmptyState, PageFrame, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { formatDateTime, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { useResource } from '@/lib/useResource';
import WebsiteNav from './WebsiteNav.vue';
import { changedMeanwhile } from './useWebsite';

/**
 * Messages visitors sent through the website's contact form: newest
 * first, by where each stands (new, contacted, won, closed), searchable by
 * name, phone, email or company. Owners and sales staff follow up and keep
 * a note; owners may remove one (a visitor asked, or it was spam).
 */
const filters = reactive({ status: '', q: '', page: 1 });
const resource = useResource(() => api('/api/partner/website/leads', { query: { status: filters.status || undefined, q: filters.q || undefined, page: filters.page } }));
const rows = computed(() => resource.data.value?.data ?? []);
const meta = computed(() => resource.data.value?.meta ?? {});
const statuses = computed(() => meta.value.statuses ?? ['new', 'contacted', 'won', 'closed']);
const TONES = { new: 'brand', contacted: 'warn', won: 'ok', closed: 'neutral' };

let timer = null;
watch(
    () => filters.q,
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            filters.page = 1;
            resource.reload();
        }, 350);
    },
);
function setStatus(status) {
    filters.status = status;
    filters.page = 1;
    resource.reload();
}
function goTo(page) {
    filters.page = page;
    resource.reload();
}

// Following up
const open = ref(null);
const form = reactive({ status: 'new', note: '' });
const errors = ref({});
const saving = ref(false);
function show(row) {
    errors.value = {};
    Object.assign(form, { status: row.status, note: row.note ?? '' });
    open.value = row;
}
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await api(`/api/partner/website/leads/${open.value.id}`, { method: 'PATCH', body: { base_version: open.value.version, status: form.status, note: form.note || null } });
        toast.success(t('website.leads.saved'));
        open.value = null;
        resource.reload();
    } catch (caught) {
        if (changedMeanwhile(caught)) {
            toast.error(caught.message);
            open.value = null;
            resource.reload();
            return;
        }
        errors.value = Object.fromEntries(Object.entries(caught.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        if (!caught.errors) toast.error(caught.message);
    } finally {
        saving.value = false;
    }
}
async function remove(row) {
    const confirmed = await confirmAction({
        title: t('website.leads.remove_title', { name: row.name }),
        message: t('website.leads.remove_text'),
        confirmLabel: t('website.leads.remove'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        await api(`/api/partner/website/leads/${row.id}`, { method: 'DELETE' });
        toast.success(t('website.leads.removed'));
        open.value = null;
        resource.reload();
    } catch (caught) {
        toast.error(caught.message);
    }
}

const exported = computed(() => ({
    module: 'partner',
    page: 'partner.website_leads',
    columns: [
        { key: 'created_at', label: t('website.leads.when'), value: (row) => formatDateTime(row.created_at) },
        { key: 'name', label: t('website.leads.name') },
        { key: 'phone', label: t('website.leads.phone') },
        { key: 'email', label: t('website.leads.email') },
        { key: 'company', label: t('website.leads.company') },
        { key: 'message', label: t('website.leads.message') },
        { key: 'status', label: t('website.leads.status'), value: (row) => t(`website.leads.statuses.${row.status}`) },
        { key: 'note', label: t('website.leads.note') },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.leads.text')" :resource="resource" width="lg" :export="exported">
        <WebsiteNav />

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <div class="flex flex-wrap gap-1.5" role="group" :aria-label="t('website.leads.status')">
                <AppButton size="sm" :variant="filters.status === '' ? 'primary' : 'ghost'" @click="setStatus('')">{{ t('website.leads.all') }}</AppButton>
                <AppButton v-for="status in statuses" :key="status" size="sm" :variant="filters.status === status ? 'primary' : 'ghost'" @click="setStatus(status)">
                    {{ t(`website.leads.statuses.${status}`) }} · {{ formatNumber(meta.counts?.[status] ?? 0) }}
                </AppButton>
            </div>
            <label class="relative ms-auto w-full sm:w-64">
                <span class="sr-only">{{ t('website.leads.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="filters.q" type="search" class="field-input ps-9" maxlength="100" :placeholder="t('website.leads.search')" />
            </label>
        </div>

        <EmptyState v-if="!rows.length" :icon="Inbox" :title="filters.status || filters.q ? t('website.leads.none_found') : t('website.leads.empty')" :text="filters.status || filters.q ? '' : t('website.leads.empty_text')" />
        <ul v-else class="card divide-y divide-line">
            <li v-for="row in rows" :key="row.id">
                <button type="button" class="flex w-full items-start gap-3 p-4 text-start transition hover:bg-subtle/60 focus-visible:bg-subtle focus-visible:outline-none" @click="show(row)">
                    <span class="min-w-0 flex-1">
                        <span class="flex flex-wrap items-center gap-2">
                            <span class="text-[14px] font-medium text-fg">{{ row.name }}</span>
                            <span v-if="row.company" class="text-[12.5px] text-muted">· {{ row.company }}</span>
                            <AppBadge :tone="TONES[row.status]">{{ t(`website.leads.statuses.${row.status}`) }}</AppBadge>
                        </span>
                        <span class="mt-0.5 block text-[12.5px] text-muted" dir="ltr">{{ row.phone }}<template v-if="row.email"> · {{ row.email }}</template></span>
                        <span v-if="row.message" class="mt-1 line-clamp-2 block text-[13px] text-fg-2">{{ row.message }}</span>
                    </span>
                    <span class="shrink-0 text-[12px] text-faint">{{ formatDateTime(row.created_at) }}</span>
                </button>
            </li>
        </ul>
        <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-center gap-2">
            <AppButton size="sm" :disabled="meta.page <= 1" @click="goTo(meta.page - 1)">{{ t('website.leads.newer') }}</AppButton>
            <span class="text-[12.5px] text-muted">{{ t('website.leads.page', { page: formatNumber(meta.page), last: formatNumber(meta.last_page) }) }}</span>
            <AppButton size="sm" :disabled="meta.page >= meta.last_page" @click="goTo(meta.page + 1)">{{ t('website.leads.older') }}</AppButton>
        </div>

        <AppDialog :open="!!open" :title="open?.name ?? ''" @close="open = null">
            <div v-if="open" class="space-y-4">
                <div class="flex flex-wrap gap-2">
                    <a :href="`tel:${open.phone.replace(/[^0-9+]/g, '')}`" class="inline-flex h-8 items-center gap-1.5 rounded-[9px] border border-line-strong px-2.5 text-[13px] font-medium text-fg hover:bg-subtle" dir="ltr">
                        <Phone class="size-4" aria-hidden="true" />{{ open.phone }}
                    </a>
                    <a v-if="open.email" :href="`mailto:${open.email}`" class="inline-flex h-8 items-center gap-1.5 rounded-[9px] border border-line-strong px-2.5 text-[13px] font-medium text-fg hover:bg-subtle">
                        <Mail class="size-4" aria-hidden="true" />{{ open.email }}
                    </a>
                </div>
                <dl class="grid gap-1 text-[13px]">
                    <div v-if="open.company"><dt class="inline text-muted">{{ t('website.leads.company') }}:</dt> <dd class="inline">{{ open.company }}</dd></div>
                    <div><dt class="inline text-muted">{{ t('website.leads.from_page') }}:</dt> <dd class="inline font-mono" dir="ltr">/{{ open.page_slug }}</dd></div>
                    <div><dt class="inline text-muted">{{ t('website.leads.when') }}:</dt> <dd class="inline">{{ formatDateTime(open.created_at) }}</dd></div>
                </dl>
                <p v-if="open.message" class="whitespace-pre-line rounded-xl bg-subtle p-3.5 text-[13.5px] text-fg">{{ open.message }}</p>
                <form id="lead-form" class="space-y-4" @submit.prevent="save">
                    <AppField :label="t('website.leads.status')" :error="errors.status">
                        <template #default="{ id }">
                            <select :id="id" v-model="form.status" class="field-input">
                                <option v-for="status in statuses" :key="status" :value="status">{{ t(`website.leads.statuses.${status}`) }}</option>
                            </select>
                        </template>
                    </AppField>
                    <AppField :label="t('website.leads.note')" :hint="t('website.leads.note_hint')" :error="errors.note" optional>
                        <template #default="{ id }"><textarea :id="id" v-model="form.note" class="field-input" rows="3" maxlength="2000" /></template>
                    </AppField>
                </form>
            </div>
            <template #footer>
                <AppButton v-if="meta.can_remove" variant="danger-soft" :icon="Trash2" class="me-auto" @click="remove(open)">{{ t('website.leads.remove') }}</AppButton>
                <AppButton variant="ghost" @click="open = null">{{ t('website.media.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="lead-form" :loading="saving">{{ t('website.media.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
