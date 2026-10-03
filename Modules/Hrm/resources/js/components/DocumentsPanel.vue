<script setup>
import { computed, reactive, ref } from 'vue';
import { CircleAlert, ExternalLink, FileText, Paperclip, Trash2, TriangleAlert, Upload } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * An employee's documents: kinds and size from the rules, opened through a
 * short-lived signed link, removal confirmed (all audited on the server).
 */
const props = defineProps({
    employeeId: { type: String, required: true },
    hrm: { type: Object, required: true },
    options: { type: Object, default: null },
    canEdit: Boolean,
});

const list = useResource(() => props.hrm.documents(props.employeeId));
const documents = computed(() => list.data.value?.data ?? []);
// A date without a time: written as that same day everywhere.
const dateOf = (document) => formatDate(document.expires_on, { dateStyle: 'medium', timeZone: 'UTC' });

const adding = ref(false);
const saving = ref(false);
const errors = ref({});
const fileInput = ref(null);
const form = reactive({ type: '', title: '', expires_on: '', file: null });

function startAdding() {
    Object.assign(form, { type: props.options?.document_types[0]?.value ?? '', title: '', expires_on: '', file: null });
    errors.value = {};
    adding.value = true;
}

async function upload() {
    const body = new FormData();
    if (form.file) body.append('file', form.file);
    body.append('type', form.type);
    body.append('title', form.title);
    if (form.expires_on) body.append('expires_on', form.expires_on);

    saving.value = true;
    errors.value = {};
    try {
        await props.hrm.upload(props.employeeId, body);
        toast.success(t('hrm.documents.uploaded'));
        adding.value = false;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function open(document) {
    try {
        const { data } = await props.hrm.documentLink(props.employeeId, document.id);
        window.open(data.url, '_blank', 'noopener');
    } catch (error) {
        toast.error(error.message);
    }
}

async function remove(document) {
    const confirmed = await confirmAction({
        title: t('hrm.documents.remove_title', { title: document.title }),
        message: t('hrm.documents.remove_text'),
        confirmLabel: t('hrm.documents.remove'),
        reason: 'optional',
        danger: true,
    });
    if (!confirmed) return;

    try {
        await props.hrm.removeDocument(props.employeeId, document.id, confirmed.reason || null);
        toast.success(t('hrm.documents.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const typeLabel = (value) => props.options?.document_types.find((type) => type.value === value)?.label ?? value;
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <section class="card">
        <header v-if="canEdit" class="flex justify-end border-b border-line px-5 py-3">
            <AppButton v-if="!adding" size="sm" :icon="Paperclip" @click="startAdding">{{ t('hrm.documents.add') }}</AppButton>
        </header>

        <form v-if="adding" class="grid gap-4 border-b border-line p-5 sm:grid-cols-2" novalidate @submit.prevent="upload">
            <AppField v-slot="{ id }" :label="t('hrm.documents.type')" :error="fieldError('type')">
                <select :id="id" v-model="form.type" class="field-input">
                    <option v-for="type in options?.document_types ?? []" :key="type.value" :value="type.value">{{ type.label }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.documents.title_field')" :error="fieldError('title')">
                <input :id="id" v-model="form.title" class="field-input" maxlength="150" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.documents.file')" :hint="t('hrm.documents.file_hint', { kb: options?.document_max_kb ?? 0 })" :error="fieldError('file')">
                <input :id="id" ref="fileInput" type="file" class="field-input" accept="application/pdf,image/jpeg,image/png,image/webp" @change="form.file = $event.target.files[0] ?? null" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('hrm.documents.expires_on')" :error="fieldError('expires_on')" optional>
                <input :id="id" v-model="form.expires_on" type="date" class="field-input" />
            </AppField>
            <div class="flex justify-end gap-2 sm:col-span-2">
                <AppButton variant="ghost" @click="adding = false">{{ t('hrm.profile.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" :icon="Upload" :loading="saving" :disabled="!form.file">{{ t('hrm.documents.upload') }}</AppButton>
            </div>
        </form>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
        <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
        <EmptyState v-else-if="!documents.length" :icon="FileText" :title="t('hrm.documents.empty_title')" :text="t('hrm.documents.empty_text')" compact />
        <ul v-else class="divide-y divide-line">
            <li v-for="document in documents" :key="document.id" class="flex items-center gap-3 px-5 py-3">
                <FileText class="size-4 shrink-0 text-muted" aria-hidden="true" />
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[13.5px] font-medium text-fg">{{ document.title }}</span>
                    <span class="block text-[12px] text-muted">{{ typeLabel(document.type) }} · {{ formatNumber(Math.ceil(document.size_bytes / 1024)) }} KB</span>
                </span>
                <!-- Expired / expiring soon (by the reminder days) carry an icon as well as a color. -->
                <AppBadge v-if="document.expiry?.state === 'expired'" tone="bad" :icon="CircleAlert">
                    {{ t('hrm.documents.expired', { date: dateOf(document) }) }}
                </AppBadge>
                <AppBadge v-else-if="document.expiry?.state === 'expiring'" tone="warn" :icon="TriangleAlert">
                    {{ t('hrm.documents.expiring', { date: dateOf(document), days: formatNumber(document.expiry.days_left) }) }}
                </AppBadge>
                <AppBadge v-else-if="document.expires_on" tone="outline">{{ t('hrm.documents.expires', { date: dateOf(document) }) }}</AppBadge>
                <AppButton size="icon-sm" variant="ghost" :icon="ExternalLink" :aria-label="t('hrm.documents.open')" @click="open(document)" />
                <AppButton v-if="canEdit" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('hrm.documents.remove')" @click="remove(document)" />
            </li>
        </ul>
    </section>
</template>
