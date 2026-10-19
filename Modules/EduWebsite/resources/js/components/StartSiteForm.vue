<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, ArrowRight, Rocket } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { formatNumber } from '@/lib/format';
import { languageName, t } from '@/lib/i18n';
import { cleanTexts, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { slugFrom } from '../lib';
import AddressField from './AddressField.vue';
import TemplateCard from './TemplateCard.vue';

/**
 * Starting the website in three short steps: a template (those the rules
 * offer), the name and address, the main language. The server makes a
 * home page with a welcome, the notice board and contact details.
 */
const props = defineProps({
    web: { type: Object, required: true },
    meta: { type: Object, required: true },
    organizationName: { type: String, default: '' },
});
const emit = defineEmits(['started']);

const step = ref(1);
const choices = computed(() => props.meta.template_choices ?? []);
const form = reactive({
    template: choices.value.find((choice) => choice.offered)?.key ?? 'school',
    // The organization's name goes into the field of its own script (a Bangla name is not an English one).
    title: textsFor(/[^\u0000-\u007f]/.test(props.organizationName) ? { bn: props.organizationName } : { en: props.organizationName }),
    slug: slugFrom(props.organizationName),
    default_locale: (props.meta.languages ?? []).includes('bn') ? 'bn' : props.meta.languages?.[0] ?? 'en',
});
const address = ref('idle');
const errors = ref({});
const saving = ref(false);

const lockedBy = computed(() => props.meta.template_source?.name ?? '');
const canNext = computed(() => {
    if (step.value === 1) return !!form.template;
    if (step.value === 2) return !!form.title.en?.trim() && address.value === 'ok';
    return true;
});

async function start() {
    saving.value = true;
    errors.value = {};
    try {
        await props.web.start({ template: form.template, title: cleanTexts(form.title), slug: form.slug, default_locale: form.default_locale });
        toast.success(t('edu_website.start.started'));
        emit('started');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (errors.value.slug || errors.value['title.en'] || error.code === 'slug_taken') step.value = 2;
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const STEPS = ['template', 'name', 'language'];
</script>

<template>
    <section class="card overflow-hidden" aria-labelledby="start-title">
        <div class="border-b border-line bg-brand-soft/60 px-5 py-4 sm:px-6">
            <h2 id="start-title" class="text-[17px] font-semibold">{{ t('edu_website.start.title') }}</h2>
            <p class="mt-0.5 text-[13px] text-fg-2">{{ t('edu_website.start.text') }}</p>
            <ol class="mt-4 flex flex-wrap gap-2" :aria-label="t('edu_website.start.steps')">
                <li
                    v-for="(key, index) in STEPS"
                    :key="key"
                    class="flex items-center gap-2 rounded-full px-3 py-1 text-[12.5px] font-medium"
                    :class="step === index + 1 ? 'bg-brand text-white' : step > index + 1 ? 'bg-surface text-brand-text ring-1 ring-brand/30' : 'bg-surface text-muted ring-1 ring-line'"
                    :aria-current="step === index + 1 ? 'step' : undefined"
                >
                    <span class="tabular">{{ formatNumber(index + 1) }}</span>{{ t(`edu_website.start.step_${key}`) }}
                </li>
            </ol>
        </div>

        <div class="p-5 sm:p-6">
            <div v-if="step === 1" class="grid gap-4 sm:grid-cols-2">
                <TemplateCard
                    v-for="choice in choices"
                    :key="choice.key"
                    :template="choice.key"
                    :offered="choice.offered"
                    :selected="form.template === choice.key"
                    :locked-by="lockedBy"
                    @select="form.template = $event"
                />
            </div>

            <div v-else-if="step === 2" class="grid gap-5">
                <TranslatedFields v-model="form.title" :label="t('edu_website.settings.name')" required :maxlength="120" :errors="errors" error-prefix="title" />
                <AddressField v-model="form.slug" :web="web" :domain="meta.sites_domain" :error="errors.slug?.[0] ?? null" @state="address = $event" />
            </div>

            <div v-else class="grid gap-4">
                <AppField v-slot="{ id }" :label="t('edu_website.settings.main_language')" :hint="t('edu_website.start.language_hint')" :error="errors.default_locale?.[0] ?? null">
                    <select :id="id" v-model="form.default_locale" class="field-input sm:max-w-64">
                        <option v-for="code in meta.languages" :key="code" :value="code">{{ languageName(code) }}</option>
                    </select>
                </AppField>
                <p class="rounded-lg bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('edu_website.start.what_next') }}</p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-4 sm:px-6">
            <AppButton v-if="step > 1" variant="ghost" :icon="ArrowLeft" @click="step--">{{ t('edu_website.common.back') }}</AppButton>
            <span v-else />
            <AppButton v-if="step < 3" variant="primary" :icon-end="ArrowRight" :disabled="!canNext" @click="step++">{{ t('edu_website.common.next') }}</AppButton>
            <AppButton v-else variant="primary" :icon="Rocket" :loading="saving" @click="start">{{ t('edu_website.start.start') }}</AppButton>
        </div>
    </section>
</template>
