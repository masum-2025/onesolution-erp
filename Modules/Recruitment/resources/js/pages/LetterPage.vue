<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue';
import { Save } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { direction, i18n, languageName, t } from '@/lib/i18n';
import { recruitmentApi } from '../api';

/**
 * The company's offer letter: its text in each language, with
 * placeholders put in by pressing them (the cursor's place), and the
 * letter as it reads with sample values. Until the company saves its own,
 * the rule's wording shows (per country or partner).
 */
const org = currentOrganization();
const calls = recruitmentApi(org.id);
const view = useResource(() => calls.letter());
const data = computed(() => view.data.value?.data ?? null);
const languages = computed(() => (i18n.locales?.length ? i18n.locales : ['en', 'bn']));

const lang = ref('en');
const body = reactive({});
const errors = ref({});
const saving = ref(false);
const area = ref(null);
watch(data, (value) => {
    if (!value) return;
    for (const code of languages.value) body[code] = value.body?.[code] ?? '';
}, { immediate: true });

/** "salary" -> "{salary}" (as written in the letter). */
const braced = (name) => `{${name}}`;

async function insert(name) {
    const field = area.value;
    const token = braced(name);
    const text = body[lang.value] ?? '';
    const at = field ? field.selectionStart : text.length;
    const end = field ? field.selectionEnd : text.length;
    body[lang.value] = text.slice(0, at) + token + text.slice(end);
    await nextTick();
    field?.focus();
    field?.setSelectionRange(at + token.length, at + token.length);
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await calls.saveLetter({ base_version: data.value.version, body: Object.fromEntries(Object.entries(body).filter(([, text]) => text.trim())) });
        toast.success(t('recruitment.letter.saved'));
        view.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.code === 'version_conflict') view.reload();
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('recruitment.letter.title')" :description="t('recruitment.letter.text')">
            <template v-if="data?.can_manage" #actions>
                <AppButton variant="primary" :icon="Save" :loading="saving" :disabled="!body.en?.trim()" @click="save">{{ t('recruitment.common.save') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <SkeletonRows v-else-if="!data" :rows="6" />
        <div v-else class="grid gap-5 lg:grid-cols-2">
            <section class="card space-y-3 p-5" :aria-label="t('recruitment.letter.edit')">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <AppSegmented v-model="lang" size="sm" :label="t('recruitment.letter.language')" :options="languages.map((code) => ({ value: code, label: languageName(code) }))" />
                    <AppBadge :tone="data.own ? 'ok' : 'neutral'">{{ data.own ? t('recruitment.letter.own') : t('recruitment.letter.from_rule') }}</AppBadge>
                </div>
                <textarea ref="area" v-model="body[lang]" rows="18" class="field-input font-[inherit] leading-6" :readonly="!data.can_manage" :dir="direction(lang)" :aria-invalid="!!errors[`body.${lang}`]" :aria-label="t('recruitment.letter.edit')"></textarea>
                <p v-if="errors[`body.${lang}`]" class="text-[12.5px] text-bad" role="alert">{{ errors[`body.${lang}`][0] }}</p>
                <p v-for="(messages, key) in errors" v-show="key !== `body.${lang}`" :key="key" class="text-[12.5px] text-bad">{{ languageName(String(key).replace('body.', '')) }}: {{ messages[0] }}</p>
                <div v-if="data.can_manage">
                    <p class="mb-2 text-[12.5px] text-muted">{{ t('recruitment.letter.placeholders') }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="name in data.placeholders" :key="name" type="button" class="rounded-lg border border-line px-2 py-1 font-mono text-[12px] hover:bg-subtle" dir="ltr" @click="insert(name)">
                            {{ braced(name) }} <span class="font-sans text-muted">· {{ t(`recruitment.letter.names.${name.replace('.', '_')}`) }}</span>
                        </button>
                    </div>
                </div>
            </section>
            <section class="card p-5" :aria-label="t('recruitment.letter.sample')">
                <h2 class="mb-3 text-[13px] font-semibold text-fg-2">{{ t('recruitment.letter.sample') }}</h2>
                <div class="whitespace-pre-line rounded-xl bg-subtle p-4 text-[14px] leading-7">{{ data.sample }}</div>
                <p class="mt-2 text-[12.5px] text-muted">{{ t('recruitment.letter.sample_hint') }}</p>
            </section>
        </div>
    </div>
</template>
