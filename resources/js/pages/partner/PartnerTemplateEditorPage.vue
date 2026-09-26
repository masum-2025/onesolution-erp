<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, RotateCcw, Save } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppTabs from '@/components/AppTabs.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: reword one message, per channel and language, with the
 * placeholders it offers, and see it in the partner's brand before saving.
 */
const route = useRoute();
const key = route.params.key;
const template = useResource(() => api(`/api/partner/templates/${key}`).then((response) => response.data));
const data = computed(() => template.data.value);
const canEdit = computed(() => data.value?.can_edit === true);

const channel = ref('mail');
const locale = ref('en');
const form = reactive({ subject: '', body: '' });
const errors = ref({});
const saving = ref(false);
const preview = ref(null);
const previewError = ref(null);

const current = computed(() => data.value?.wording?.[channel.value]?.[locale.value] ?? null);
const channels = computed(() => Object.keys(data.value?.wording ?? {}).map((item) => ({ key: item, label: t(`messaging.channels.${item}`) })));
const locales = computed(() => Object.keys(data.value?.wording?.[channel.value] ?? {}).map((item) => ({ value: item, label: t(`messaging.locales.${item}`) })));
const dirty = computed(() => current.value && (form.body !== current.value.body || (channel.value === 'mail' && form.subject !== current.value.subject)));

function load() {
    errors.value = {};
    form.subject = current.value?.subject ?? '';
    form.body = current.value?.body ?? '';
}
watch([current, channel, locale], load);

let timer;
watch(
    () => [form.subject, form.body, channel.value, locale.value],
    () => {
        clearTimeout(timer);
        timer = setTimeout(refreshPreview, 400);
    },
);

async function refreshPreview() {
    if (!data.value || !form.body.trim()) return;
    previewError.value = null;
    try {
        const body = channel.value === 'mail' ? { subject: form.subject, body: form.body } : { body: form.body };
        preview.value = { channel: channel.value, ...(await api(`/api/partner/templates/${key}/${channel.value}/${locale.value}/preview`, { method: 'POST', body })).data };
    } catch (error) {
        previewError.value = error.message;
    }
}

// The text a placeholder is written as (kept out of the template, whose own delimiters are the same).
const slot = (name) => `{{ ${name} }}`;

function insert(name) {
    const field = document.getElementById('template-body');
    const text = slot(name);
    if (!field) {
        form.body += text;
        return;
    }
    const start = field.selectionStart ?? form.body.length;
    const end = field.selectionEnd ?? start;
    form.body = form.body.slice(0, start) + text + form.body.slice(end);
    requestAnimationFrame(() => {
        field.focus();
        field.setSelectionRange(start + text.length, start + text.length);
    });
}

async function save() {
    errors.value = {};
    saving.value = true;
    try {
        const body = channel.value === 'mail' ? { subject: form.subject, body: form.body } : { body: form.body };
        const response = await api(`/api/partner/templates/${key}/${channel.value}/${locale.value}`, { method: 'PUT', body });
        toast.success(response.message);
        await template.reload();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (error.data?.field) errors.value[error.data.field] = error.message;
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function reset() {
    const answer = await confirmAction({ title: t('messaging.templates.reset_title'), message: t('messaging.templates.reset_text'), danger: true, confirmLabel: t('messaging.templates.reset') });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/templates/${key}/${channel.value}/${locale.value}`, { method: 'DELETE' });
        toast.success(response.message);
        await template.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <AppButton class="mb-3" variant="ghost" size="sm" :icon="ArrowLeft" to="/partner/templates">{{ t('messaging.templates.back') }}</AppButton>

        <SkeletonRows v-if="template.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="template.error.value" :error="template.error.value" @retry="template.reload()" />

        <template v-else-if="data">
            <PageHeader :title="data.name" :description="data.description" />

            <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
                <AppTabs v-model="channel" :tabs="channels" :label="t('messaging.templates.channel')" />
                <AppSegmented v-model="locale" :options="locales" :label="t('messaging.templates.language')" size="sm" />
            </div>

            <div class="grid gap-6 lg:grid-cols-2">
                <form class="card space-y-4 p-5" novalidate @submit.prevent="save">
                    <p class="flex flex-wrap items-center gap-2 text-[12.5px] text-muted">
                        <AppBadge :tone="current?.custom ? 'brand' : 'outline'">{{ t(current?.custom ? 'messaging.templates.yours' : 'messaging.templates.default') }}</AppBadge>
                        {{ t('messaging.templates.plain_text') }}
                    </p>

                    <AppField v-if="channel === 'mail'" :label="t('messaging.templates.subject')" :error="errors.subject">
                        <template #default="{ id, invalid }">
                            <input :id="id" v-model="form.subject" class="field-input" maxlength="200" :disabled="!canEdit" :aria-invalid="invalid || undefined" />
                        </template>
                    </AppField>

                    <AppField :label="t('messaging.templates.body')" :error="errors.body">
                        <template #default="{ invalid }">
                            <textarea
                                id="template-body"
                                v-model="form.body"
                                class="field-input min-h-[12rem] font-mono text-[13px]"
                                :rows="channel === 'sms' ? 4 : 10"
                                :maxlength="channel === 'sms' ? 480 : 5000"
                                :disabled="!canEdit"
                                :aria-invalid="invalid || undefined"
                            />
                        </template>
                    </AppField>

                    <div>
                        <p class="mb-2 text-[12.5px] font-medium text-fg-2">{{ t('messaging.templates.placeholders') }}</p>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                v-for="placeholder in data.placeholders"
                                :key="placeholder.name"
                                type="button"
                                class="rounded-md border border-line bg-subtle px-2 py-1 text-start text-[12px] transition hover:border-line-strong disabled:opacity-60"
                                :title="placeholder.description"
                                :disabled="!canEdit"
                                @click="insert(placeholder.name)"
                            >
                                <span class="font-mono text-fg" dir="ltr">{{ slot(placeholder.name) }}</span>
                                <span class="ms-1.5 text-muted">{{ placeholder.description }}</span>
                            </button>
                        </div>
                    </div>

                    <div v-if="canEdit" class="flex flex-wrap gap-2 border-t border-line pt-4">
                        <AppButton type="submit" variant="primary" :icon="Save" :loading="saving" :disabled="!dirty">{{ t('messaging.templates.save') }}</AppButton>
                        <AppButton v-if="current?.custom" :icon="RotateCcw" @click="reset">{{ t('messaging.templates.reset') }}</AppButton>
                    </div>
                </form>

                <section class="card overflow-hidden" :aria-label="t('messaging.templates.preview')">
                    <header class="border-b border-line px-5 py-3 text-[13px] font-medium text-fg-2">{{ t('messaging.templates.preview') }}</header>
                    <p v-if="previewError" class="p-5 text-[13px] text-bad" role="alert">{{ previewError }}</p>
                    <div v-else-if="preview?.channel === 'mail' && channel === 'mail'">
                        <div class="space-y-0.5 border-b border-line px-5 py-3 text-[12.5px]">
                            <p><span class="text-muted">{{ t('messaging.templates.from') }}</span> <span class="font-medium text-fg">{{ preview.from }}</span></p>
                            <p><span class="text-muted">{{ t('messaging.templates.subject') }}:</span> <span class="font-medium text-fg">{{ preview.subject }}</span></p>
                        </div>
                        <!-- Rendered by the server with every text escaped; its own page policy allows no scripts, and the frame is sandboxed. -->
                        <iframe :src="preview.preview_url" sandbox="" class="h-[28rem] w-full bg-white" :title="t('messaging.templates.preview')" />
                    </div>
                    <div v-else-if="preview?.channel === 'sms' && channel === 'sms'" class="p-5">
                        <div class="max-w-xs rounded-2xl rounded-es-sm bg-subtle px-4 py-3 text-[14px] whitespace-pre-wrap text-fg">{{ preview.text }}</div>
                        <p class="mt-3 text-[12.5px] text-muted">
                            {{ t('messaging.templates.sms_count', { length: formatNumber(preview.length), count: preview.segments, segments: formatNumber(preview.segments) }) }}
                            <template v-if="preview.unicode"> · {{ t('messaging.templates.unicode') }}</template>
                        </p>
                    </div>
                    <p v-else class="p-5 text-[13px] text-muted">{{ t('messaging.templates.preview_empty') }}</p>
                </section>
            </div>
        </template>
    </div>
</template>
