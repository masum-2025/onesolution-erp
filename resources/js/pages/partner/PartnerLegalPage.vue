<script setup>
import { computed, reactive, ref } from 'vue';
import { FileText, Globe, Upload } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import LegalText from '@/components/LegalText.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { languageName, t } from '@/lib/i18n';
import { textLocales } from '@/lib/texts';

/**
 * Partner console: the terms, privacy notice and data processing agreement
 * the partner's clients are bound by. Without its own version, the
 * platform's applies. A published version never changes. One Solutions'
 * owners (the house partner) also publish the platform's defaults here.
 */
const docs = useResource(() => api('/api/partner/legal'));
const rows = computed(() => docs.data.value?.data ?? []);
const canPublish = computed(() => docs.data.value?.can_publish === true);
const canPublishPlatform = computed(() => docs.data.value?.can_publish_platform === true);
const scope = ref('partner');
const scopes = computed(() => [
    { value: 'partner', label: t('provider.legal.scope_partner') },
    { value: 'platform', label: t('provider.legal.scope_platform') },
]);

const editing = ref(null);
const lang = ref('en');
const form = reactive({ title: { en: '', bn: '' }, body: { en: '', bn: '' }, summary: '' });
const errors = ref({});
const saving = ref(false);
const preview = ref(false);
// Every language the app speaks, each in its own name.
const langs = computed(() => textLocales().map((locale) => ({ value: locale, label: languageName(locale) })));

async function startNew(row, target = 'partner') {
    errors.value = {};
    preview.value = false;
    lang.value = 'en';
    scope.value = target;
    await loadBase(row);
    editing.value = row;
}

// Start from the version in force, so only what changes has to be written.
async function loadBase(row) {
    const current = (await api(`/api/partner/legal/${row.kind}/0`, { query: scope.value === 'platform' ? { scope: 'platform' } : {} })).data;
    Object.assign(form, { title: { en: '', bn: '', ...current.title_texts }, body: { en: '', bn: '', ...current.body_texts }, summary: '' });
}

async function switchScope(value) {
    scope.value = value;
    await loadBase(editing.value);
}

async function publish() {
    errors.value = {};
    const answer = await confirmAction({
        title: t('provider.legal.publish_title', { title: form.title.en }),
        message: scope.value === 'platform'
            ? t('provider.legal.publish_text_platform')
            : t(editing.value.needs_acceptance ? 'provider.legal.publish_text_accept' : 'provider.legal.publish_text'),
        confirmLabel: t('provider.legal.publish'),
    });
    if (!answer) return;

    saving.value = true;
    try {
        const response = await api(`/api/partner/legal/${editing.value.kind}`, {
            method: 'POST',
            body: { title: form.title, body: form.body, summary: form.summary.trim(), ...(scope.value === 'platform' ? { scope: 'platform' } : {}) },
        });
        toast.success(response.message);
        editing.value = null;
        docs.reload();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('provider.legal.title')" :description="t('provider.legal.text')" />

        <SkeletonRows v-if="docs.loading.value && !docs.data.value" :rows="3" />
        <ErrorState v-else-if="docs.error.value" :error="docs.error.value" @retry="docs.reload()" />

        <div v-else class="space-y-4">
            <article v-for="row in rows" :key="row.kind" class="card p-5">
                <div class="flex flex-wrap items-start gap-3">
                    <FileText class="mt-0.5 size-5 shrink-0 text-muted" aria-hidden="true" />
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[14px] font-semibold text-fg">
                            {{ row.current?.title ?? t(`provider.legal.kinds.${row.kind}`) }}
                            <AppBadge :tone="row.current?.platform ? 'outline' : 'brand'">{{ t(row.current?.platform ? 'provider.legal.platform' : 'provider.legal.yours') }}</AppBadge>
                            <AppBadge v-if="row.needs_acceptance" tone="neutral">{{ t('provider.legal.accepted_by_clients') }}</AppBadge>
                        </p>
                        <p v-if="row.current" class="mt-0.5 text-[12.5px] text-muted">{{ t('provider.documents.version_line', { version: row.current.version, date: formatDate(row.current.published_at) }) }}</p>
                        <p v-if="row.platform" class="mt-1 text-[12.5px] text-muted">
                            {{ t('provider.legal.platform_line', { version: row.platform.version, date: formatDate(row.platform.published_at) }) }}
                        </p>
                        <p v-if="row.own_versions.length > 1" class="mt-1 text-[12px] text-faint">
                            {{ t('provider.legal.history', { list: row.own_versions.slice(1).map((version) => `v${version.version}`).join(', ') }) }}
                        </p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        <AppButton v-if="canPublish" size="sm" :icon="Upload" @click="startNew(row)">{{ t('provider.legal.new_version') }}</AppButton>
                        <AppButton v-if="canPublishPlatform" size="sm" variant="ghost" :icon="Globe" @click="startNew(row, 'platform')">{{ t('provider.legal.new_platform_version') }}</AppButton>
                    </div>
                </div>
            </article>
        </div>

        <AppDialog :open="editing !== null" size="lg" :title="t('provider.legal.new_title', { kind: editing ? t(`provider.legal.kinds.${editing.kind}`) : '' })" :description="t('provider.legal.new_text')" :icon="Upload" @close="editing = null">
            <form id="legal-form" class="space-y-4" novalidate @submit.prevent="publish">
                <div v-if="canPublishPlatform" class="space-y-1.5">
                    <AppSegmented :model-value="scope" :options="scopes" :label="t('provider.legal.scope')" size="sm" @update:model-value="switchScope" />
                    <p class="text-[12.5px] text-muted">{{ t(scope === 'platform' ? 'provider.legal.scope_platform_hint' : 'provider.legal.scope_partner_hint') }}</p>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <AppSegmented v-model="lang" :options="langs" :label="t('provider.legal.language')" size="sm" />
                    <AppSegmented v-model="preview" :options="[{ value: false, label: t('provider.legal.write') }, { value: true, label: t('provider.legal.preview') }]" :label="t('provider.legal.mode')" size="sm" />
                </div>
                <AppField :label="t('provider.legal.doc_title')" :error="errors[`title.${lang}`]" :optional="lang !== 'en'">
                    <template #default="{ id, invalid }">
                        <input :id="id" v-model="form.title[lang]" class="field-input" maxlength="150" :lang="lang" :aria-invalid="invalid || undefined" />
                    </template>
                </AppField>
                <div v-if="preview" class="max-h-[40vh] overflow-y-auto rounded-xl border border-line p-4"><LegalText :text="form.body[lang]" /></div>
                <AppField v-else :label="t('provider.legal.body')" :hint="t('provider.legal.body_hint')" :error="errors[`body.${lang}`]" :optional="lang !== 'en'">
                    <template #default="{ id, invalid, describedby }">
                        <textarea :id="id" v-model="form.body[lang]" rows="12" class="field-input font-mono text-[13px]" :lang="lang" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('provider.legal.summary')" :hint="t('provider.legal.summary_hint')" :error="errors.summary">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.summary" class="field-input" maxlength="500" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="editing = null">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="legal-form" variant="primary" :loading="saving">{{ t('provider.legal.publish') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
