<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Bot, FlaskConical, Save } from 'lucide-vue-next';
import { AppButton, AppField, AppSwitch, ErrorState, PageFrame, SkeletonRows, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { socialApi } from '../api';

/**
 * What the bot understands and answers: for each question, the words that
 * mean it (per language; Banglish goes with Bangla) and the answer, with
 * {product} {price} {stock} {delivery} {page} filled from the ERP. The try
 * box shows what a customer would get.
 */
const social = socialApi(currentOrganization().id);
const record = useResource(() => social.replies());
const data = computed(() => record.data.value?.data ?? null);
const forms = ref({});
const busy = ref('');

watch(data, (value) => {
    if (!value) return;
    forms.value = Object.fromEntries(value.templates.map((template) => [template.intent, {
        active: template.active,
        version: template.version,
        fixed: template.fixed,
        keywords: Object.fromEntries(value.locales.map((code) => [code, (template.keywords[code] ?? []).join(', ')])),
        answers: Object.fromEntries(value.locales.map((code) => [code, template.answers[code] ?? ''])),
    }]));
}, { immediate: true });

async function save(intent) {
    const form = forms.value[intent];
    busy.value = intent;
    try {
        const response = await social.saveReply(intent, {
            base_version: form.version,
            active: form.active,
            keywords: form.fixed ? {} : Object.fromEntries(Object.entries(form.keywords).map(([code, text]) => [code, text.split(',').map((word) => word.trim()).filter(Boolean)])),
            answers: form.answers,
        });
        form.version = response.data.version;
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) record.reload();
    } finally {
        busy.value = '';
    }
}

// Try: what would a customer get?
const trying = reactive({ text: '', post_id: '', result: null, loading: false });
async function tryIt() {
    trying.loading = true;
    try {
        trying.result = (await social.tryReply({ text: trying.text, post_id: trying.post_id || null })).data;
    } catch (error) {
        toast.error(error.message);
    } finally {
        trying.loading = false;
    }
}
</script>

<template>
    <PageFrame :title="t('social_commerce.replies.title')" :description="t('social_commerce.replies.text')" width="lg">
        <SkeletonRows v-if="record.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />
        <template v-else-if="data">
            <section class="card mb-4 grid gap-3 p-5">
                <h2 class="flex items-center gap-2 text-[14px] font-semibold"><FlaskConical class="size-4" aria-hidden="true" />{{ t('social_commerce.replies.try_title') }}</h2>
                <form class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end" @submit.prevent="tryIt">
                    <AppField :label="t('social_commerce.replies.try_text')" v-slot="{ id }"><input :id="id" v-model="trying.text" class="field-input" maxlength="1000" :placeholder="t('social_commerce.replies.try_placeholder')" required /></AppField>
                    <AppField :label="t('social_commerce.replies.try_post')" optional v-slot="{ id }">
                        <select :id="id" v-model="trying.post_id" class="field-input">
                            <option value="">{{ t('social_commerce.replies.no_post') }}</option>
                            <option v-for="post in data.posts" :key="post.id" :value="post.id">{{ post.title || post.id }}</option>
                        </select>
                    </AppField>
                    <AppButton type="submit" :loading="trying.loading">{{ t('social_commerce.replies.try') }}</AppButton>
                </form>
                <div v-if="trying.result" class="rounded-xl bg-surface-2 p-4 text-[13.5px]" aria-live="polite">
                    <p class="text-[12.5px] text-muted">
                        {{ trying.result.intent ? t('social_commerce.replies.understood', { intent: t(`social_commerce.replies.intent.${trying.result.intent}`), lang: t(`core.languages.${trying.result.locale}`) }) : t('social_commerce.replies.not_understood') }}
                    </p>
                    <p class="mt-1 flex gap-2 whitespace-pre-line"><Bot class="mt-0.5 size-4 shrink-0" aria-hidden="true" />{{ trying.result.answer ?? trying.result.handoff ?? '—' }}</p>
                    <p v-if="trying.result.intent && !trying.result.answer" class="mt-1 text-[12px] text-warn">{{ t('social_commerce.replies.no_answer') }}</p>
                </div>
            </section>

            <section v-for="template in data.templates" :key="template.intent" class="card mb-4 grid gap-4 p-5">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>
                        <h2 class="text-[14px] font-semibold">{{ t(`social_commerce.replies.intent.${template.intent}`) }}</h2>
                        <p class="text-[12.5px] text-muted">{{ t(`social_commerce.replies.about.${template.intent}`) }}</p>
                    </span>
                    <AppSwitch v-if="forms[template.intent]" v-model="forms[template.intent].active" :label="t('social_commerce.replies.active')" show-label />
                </div>
                <div v-if="forms[template.intent]" class="grid gap-4 md:grid-cols-2">
                    <div v-for="code in data.locales" :key="code" class="grid content-start gap-3">
                        <AppField v-if="!template.fixed" :label="t('social_commerce.replies.words', { lang: t(`core.languages.${code}`) })" :hint="t('social_commerce.replies.words_hint')" v-slot="{ id }">
                            <input :id="id" v-model="forms[template.intent].keywords[code]" class="field-input" />
                        </AppField>
                        <AppField :label="t('social_commerce.replies.answer', { lang: t(`core.languages.${code}`) })" v-slot="{ id }">
                            <textarea :id="id" v-model="forms[template.intent].answers[code]" class="field-input" rows="2" maxlength="1000" :lang="code" />
                        </AppField>
                    </div>
                </div>
                <div class="flex items-center justify-between gap-2">
                    <p class="text-[12px] text-muted">{{ t('social_commerce.replies.placeholders') }}</p>
                    <AppButton size="sm" variant="primary" :icon="Save" :loading="busy === template.intent" @click="save(template.intent)">{{ t('social_commerce.replies.save') }}</AppButton>
                </div>
            </section>
        </template>
    </PageFrame>
</template>
