<script setup>
import { computed, reactive, ref } from 'vue';
import { Languages, Plus } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import WordingEditor from '@/components/WordingEditor.vue';
import { api } from '@/lib/http';
import { confirmAction } from '@/lib/dialogs';
import { loadMe } from '@/lib/session';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';

/**
 * Partner console (LANG-1): the partner's own wording for all clients and,
 * for the platform (the house partner's owners), the languages themselves:
 * add as a draft, translate, offer, switch off, remove a draft.
 */
const state = useResource(() => api('/api/partner/languages').then((response) => response.data));
const data = computed(() => state.data.value);

const level = ref('partner');
const levelOptions = computed(() => ['partner', 'platform'].map((value) => ({ value, label: t(`languages.levels.${value}`) })));
const params = computed(() => ({ level: level.value }));
// The platform translates drafts too; a partner words the languages people can use.
const editorLanguages = computed(() => (data.value?.languages ?? []).filter((language) => level.value === 'platform' || language.status === 'published'));

const adding = ref(false);
const form = reactive({ code: '', name: '', english_name: '', direction: '', fallback: 'en' });
const errors = ref({});
const saving = ref(false);

function openAdd() {
    Object.assign(form, { code: '', name: '', english_name: '', direction: '', fallback: data.value?.file_languages?.[0] ?? 'en' });
    errors.value = {};
    adding.value = true;
}

async function add() {
    errors.value = {};
    saving.value = true;
    try {
        const body = Object.fromEntries(Object.entries(form).filter(([, value]) => value !== ''));
        const response = await api('/api/partner/languages', { method: 'POST', body });
        toast.success(response.message);
        adding.value = false;
        level.value = 'platform';
        await state.reload();
    } catch (problem) {
        errors.value = Object.fromEntries(Object.entries(problem.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (problem.data?.field) errors.value[problem.data.field] = problem.message;
        if (!Object.keys(errors.value).length) toast.error(problem.message);
    } finally {
        saving.value = false;
    }
}

async function setStatus(language, status) {
    if (status === 'disabled') {
        const answer = await confirmAction({ title: t('languages.disable_title', { name: language.name }), message: t('languages.disable_text'), danger: true, confirmLabel: t('languages.disable') });
        if (!answer) return;
    }
    try {
        const response = await api(`/api/partner/languages/${language.code}`, { method: 'PATCH', body: { status } });
        toast.success(response.message);
        await Promise.all([state.reload(), loadMe()]);
    } catch (problem) {
        toast.error(problem.message);
    }
}

async function remove(language) {
    const answer = await confirmAction({ title: t('languages.remove_title', { name: language.name }), message: t('languages.remove_text'), danger: true, confirmLabel: t('languages.remove') });
    if (!answer) return;
    try {
        const response = await api(`/api/partner/languages/${language.code}`, { method: 'DELETE' });
        toast.success(response.message);
        await state.reload();
    } catch (problem) {
        toast.error(problem.message);
    }
}

const statusTone = { draft: 'warn', published: 'ok', disabled: 'outline' };
</script>

<template>
    <div>
        <PageHeader :title="t('languages.title')" :description="level === 'platform' ? t('languages.platform_description') : t('languages.partner_description')">
            <template v-if="data?.platform" #actions>
                <AppSegmented v-model="level" :options="levelOptions" :label="t('languages.level')" size="sm" />
            </template>
        </PageHeader>

        <SkeletonRows v-if="state.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="state.error.value" :error="state.error.value" @retry="state.reload()" />

        <template v-else-if="data">
            <section v-if="data.platform && level === 'platform'" class="card mb-6 p-4" :aria-label="t('languages.languages_title')">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-[15px] font-semibold text-fg">{{ t('languages.languages_title') }}</h2>
                    <AppButton size="sm" variant="primary" :icon="Plus" @click="openAdd">{{ t('languages.add_language') }}</AppButton>
                </div>
                <ul class="divide-y divide-line">
                    <li v-for="language in data.languages" :key="language.code" class="flex flex-wrap items-center justify-between gap-3 py-3">
                        <div class="min-w-0">
                            <p class="font-medium text-fg" :dir="language.direction" :lang="language.code">{{ language.name }}</p>
                            <p class="text-[12.5px] text-muted">
                                {{ language.english_name }} · <span dir="ltr">{{ language.code }}</span> · {{ t(`languages.source.${language.source}`) }}
                                <template v-if="language.completion !== null"> · {{ t('languages.completion', { percent: language.completion }) }}</template>
                            </p>
                            <div v-if="language.completion !== null" class="mt-1.5 h-1.5 w-40 overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="language.completion" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-full rounded-full bg-brand" :style="{ inlineSize: `${language.completion}%` }" />
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <AppBadge :tone="statusTone[language.status]">{{ t(`languages.status.${language.status}`) }}</AppBadge>
                            <template v-if="language.source === 'db'">
                                <AppButton v-if="language.status !== 'published'" size="sm" @click="setStatus(language, 'published')">{{ t(language.status === 'draft' ? 'languages.publish' : 'languages.enable') }}</AppButton>
                                <AppButton v-if="language.status === 'published'" size="sm" variant="ghost" @click="setStatus(language, 'disabled')">{{ t('languages.disable') }}</AppButton>
                                <AppButton v-if="language.status === 'draft'" size="sm" variant="ghost" @click="remove(language)">{{ t('languages.remove') }}</AppButton>
                            </template>
                        </div>
                    </li>
                </ul>
            </section>

            <WordingEditor
                :key="`${level}:${editorLanguages.map((language) => language.code).join(',')}`"
                base="/api/partner/translations"
                :params="params"
                :languages="editorLanguages"
                :can-edit="data.can_edit"
                @changed="state.reload()"
            />
            <p v-if="!data.can_edit" class="mt-3 text-[12.5px] text-muted">{{ t('languages.read_only') }}</p>
        </template>

        <AppDialog :open="adding" :title="t('languages.add_language')" :icon="Languages" @close="adding = false">
            <form id="language-form" class="space-y-4" novalidate @submit.prevent="add">
                <AppField :label="t('languages.code')" :hint="t('languages.code_hint')" :error="errors.code">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model.trim="form.code" class="field-input" dir="ltr" maxlength="20" autocomplete="off" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
                <AppField :label="t('languages.name')" optional :error="errors.name">
                    <template #default="{ id }">
                        <input :id="id" v-model="form.name" class="field-input" maxlength="60" />
                    </template>
                </AppField>
                <AppField :label="t('languages.english_name')" optional :error="errors.english_name">
                    <template #default="{ id }">
                        <input :id="id" v-model="form.english_name" class="field-input" maxlength="60" />
                    </template>
                </AppField>
                <AppField :label="t('languages.direction')" optional>
                    <template #default="{ id }">
                        <select :id="id" v-model="form.direction" class="field-input">
                            <option value="" />
                            <option value="ltr">{{ t('languages.directions.ltr') }}</option>
                            <option value="rtl">{{ t('languages.directions.rtl') }}</option>
                        </select>
                    </template>
                </AppField>
                <AppField :label="t('languages.fallback')" :error="errors.fallback">
                    <template #default="{ id }">
                        <select :id="id" v-model="form.fallback" class="field-input">
                            <option v-for="code in data?.file_languages ?? []" :key="code" :value="code">{{ data.languages.find((language) => language.code === code)?.name ?? code }}</option>
                        </select>
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="adding = false">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="language-form" variant="primary" :loading="saving">{{ t('languages.add_language') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
