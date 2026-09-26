<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CircleAlert, CircleCheck, ImagePlus, Lock, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { applyBrand, contrastProblem, readableOn } from '@/lib/brand';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: the brand clients see. Colors are checked for contrast
 * as you type (the server checks again); images are uploaded one by one.
 */
const brand = useResource(() => api('/api/partner/brand').then((response) => response.data));
const data = computed(() => brand.data.value);
const canEdit = computed(() => data.value?.can_edit === true);

const TEXTS = ['tagline', 'login_title', 'login_text', 'footer_text'];
const KINDS = ['logo_light', 'logo_dark', 'mark', 'favicon'];
const IMAGE_URLS = { logo_light: 'logo_url', logo_dark: 'logo_dark_url', mark: 'mark_url', favicon: 'favicon_url' };

const form = reactive({});
const errors = ref({});
const saving = ref(false);
const uploading = ref(null);
let initial = '';

watch(data, (value) => {
    if (!value) return;
    const v = value.values;
    Object.assign(form, {
        product_name: v.product_name ?? '',
        primary_color: v.primary_color ?? value.resolved.primary_color,
        secondary_color: v.secondary_color ?? '',
        font_key: v.font_key ?? 'inter',
        support_email: v.support_email ?? '',
        support_phone: v.support_phone ?? '',
        terms_url: v.terms_url ?? '',
        privacy_url: v.privacy_url ?? '',
        ...Object.fromEntries(TEXTS.map((field) => [field, { en: v[field]?.en ?? '', bn: v[field]?.bn ?? '' }])),
    });
    initial = JSON.stringify(form);
});

const dirty = computed(() => data.value && JSON.stringify(form) !== initial);
const primaryProblem = computed(() => contrastProblem(form.primary_color, true));
const secondaryProblem = computed(() => (form.secondary_color ? contrastProblem(form.secondary_color, false) : null));
const previewColor = computed(() => (/^#[0-9A-Fa-f]{6}$/.test(form.primary_color ?? '') ? form.primary_color : '#2B4C9B'));

const nullable = (value) => (typeof value === 'string' && value.trim() !== '' ? value.trim() : null);
const texts = (value) => Object.fromEntries(Object.entries(value).map(([locale, text]) => [locale, nullable(text)]));

async function save() {
    errors.value = {};
    if (primaryProblem.value || secondaryProblem.value) return;
    saving.value = true;
    try {
        const response = await api('/api/partner/brand', {
            method: 'PATCH',
            body: {
                product_name: nullable(form.product_name),
                primary_color: nullable(form.primary_color),
                secondary_color: nullable(form.secondary_color),
                font_key: form.font_key,
                support_email: nullable(form.support_email),
                support_phone: nullable(form.support_phone),
                terms_url: nullable(form.terms_url),
                privacy_url: nullable(form.privacy_url),
                ...Object.fromEntries(TEXTS.map((field) => [field, texts(form[field])])),
            },
        });
        brand.data.value = response.data;
        applyBrand(response.data.resolved);
        toast.success(response.message);
    } catch (error) {
        if (error.status === 422 && Object.keys(error.errors).length) {
            errors.value = Object.fromEntries(Object.entries(error.errors).map(([key, messages]) => [key.split('.')[0], messages[0]]));
        } else if (error.data?.field) {
            errors.value = { [error.data.field]: error.message };
        } else {
            toast.error(error.message);
        }
    } finally {
        saving.value = false;
    }
}

async function upload(kind, event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    uploading.value = kind;
    try {
        const response = await api(`/api/partner/brand/assets/${kind}`, { method: 'POST', body });
        brand.data.value = response.data;
        applyBrand(response.data.resolved);
        toast.success(response.message);
    } catch (error) {
        toast.error(error.field('file') ?? error.message);
    } finally {
        uploading.value = null;
    }
}

async function removeImage(kind) {
    uploading.value = kind;
    try {
        const response = await api(`/api/partner/brand/assets/${kind}`, { method: 'DELETE' });
        brand.data.value = response.data;
        applyBrand(response.data.resolved);
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        uploading.value = null;
    }
}

async function togglePoweredBy(show) {
    try {
        const response = await api('/api/partner/brand/powered-by', { method: 'PUT', body: { show } });
        brand.data.value = response.data;
        applyBrand(response.data.resolved);
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('partner.brand.title')" :description="t('partner.brand.text')">
            <template #actions>
                <AppButton v-if="canEdit" variant="primary" :loading="saving" :disabled="!dirty || !!primaryProblem || !!secondaryProblem" @click="save">
                    {{ t('core.actions.save') }}
                </AppButton>
            </template>
        </PageHeader>

        <div v-if="brand.loading.value && !data" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px]" role="status" :aria-label="t('core.states.loading')">
            <div class="skeleton h-96 rounded-2xl" />
            <div class="skeleton h-72 rounded-2xl" />
        </div>
        <ErrorState v-else-if="brand.error.value" :error="brand.error.value" @retry="brand.reload()" />

        <div v-else-if="data" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_340px]">
            <form class="space-y-6" novalidate @submit.prevent="save">
                <p v-if="!canEdit" class="flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                    <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('partner.brand.read_only') }}
                </p>

                <fieldset class="card space-y-4 p-5" :disabled="!canEdit">
                    <legend class="sr-only">{{ t('partner.brand.identity') }}</legend>
                    <h2 class="text-[14.5px] font-semibold text-fg">{{ t('partner.brand.identity') }}</h2>
                    <AppField :label="t('partner.brand.product_name')" :hint="t('partner.brand.product_name_hint')" :error="errors.product_name">
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.product_name" class="field-input" maxlength="60" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <AppField v-for="field in ['primary_color', 'secondary_color']" :key="field" :label="t(`partner.brand.${field}`)" :error="errors[field]" :optional="field === 'secondary_color'">
                            <template #default="{ id, invalid, describedby }">
                                <div class="flex items-center gap-2">
                                    <input
                                        type="color"
                                        class="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface p-1 disabled:cursor-default"
                                        :value="form[field] || '#2B4C9B'"
                                        :aria-label="t(`partner.brand.${field}`)"
                                        @input="form[field] = $event.target.value.toUpperCase()"
                                    />
                                    <input :id="id" v-model="form[field]" class="field-input font-mono uppercase" maxlength="7" placeholder="#2B4C9B" dir="ltr" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                </div>
                                <p
                                    v-if="(field === 'primary_color' ? primaryProblem : secondaryProblem)"
                                    class="mt-1.5 flex items-start gap-1.5 text-[12.5px] text-bad"
                                    role="alert"
                                >
                                    <CircleAlert class="mt-px size-3.5 shrink-0" aria-hidden="true" />
                                    {{ t(`partner.brand.contrast_${field === 'primary_color' ? primaryProblem : secondaryProblem}`) }}
                                </p>
                                <p v-else-if="form[field]" class="mt-1.5 flex items-center gap-1.5 text-[12.5px] text-ok">
                                    <CircleCheck class="size-3.5" aria-hidden="true" />{{ t('partner.brand.contrast_ok') }}
                                </p>
                            </template>
                        </AppField>
                        <AppField :label="t('partner.brand.font')">
                            <template #default="{ id }">
                                <select :id="id" v-model="form.font_key" class="field-input">
                                    <option v-for="font in data.fonts" :key="font" :value="font">{{ t(`partner.brand.fonts.${font}`) }}</option>
                                </select>
                            </template>
                        </AppField>
                    </div>
                </fieldset>

                <section class="card p-5">
                    <h2 class="text-[14.5px] font-semibold text-fg">{{ t('partner.brand.images') }}</h2>
                    <p class="mt-0.5 text-[12.5px] text-muted">{{ t('partner.brand.image_hint') }}</p>
                    <ul class="mt-4 grid gap-3 sm:grid-cols-2">
                        <li v-for="kind in KINDS" :key="kind" class="flex items-center gap-3 rounded-xl border border-line p-3">
                            <span class="grid size-14 shrink-0 place-items-center overflow-hidden rounded-lg ring-1 ring-line" :class="kind === 'logo_dark' ? 'bg-[#16161b]' : 'bg-subtle'">
                                <img v-if="data.resolved[IMAGE_URLS[kind]]" :src="data.resolved[IMAGE_URLS[kind]]" alt="" class="max-h-12 max-w-12 object-contain" />
                                <ImagePlus v-else class="size-5 text-faint" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-[13px] font-medium text-fg">{{ t(`partner.brand.kinds.${kind}`) }}</span>
                                <span v-if="canEdit" class="mt-1.5 flex gap-1.5">
                                    <label class="inline-flex h-7 cursor-pointer items-center rounded-md border border-line-strong bg-surface px-2 text-[12px] font-medium text-fg transition hover:bg-subtle">
                                        {{ data.resolved[IMAGE_URLS[kind]] ? t('partner.brand.replace') : t('partner.brand.upload') }}
                                        <input type="file" accept="image/png,image/webp,image/jpeg" class="sr-only" :disabled="uploading === kind" @change="upload(kind, $event)" />
                                    </label>
                                    <AppButton
                                        v-if="data.resolved[IMAGE_URLS[kind]]"
                                        size="sm"
                                        variant="danger-soft"
                                        :icon="Trash2"
                                        :loading="uploading === kind"
                                        :aria-label="`${t('partner.brand.remove')}: ${t(`partner.brand.kinds.${kind}`)}`"
                                        @click="removeImage(kind)"
                                    />
                                </span>
                            </span>
                        </li>
                    </ul>
                </section>

                <fieldset class="card space-y-4 p-5" :disabled="!canEdit">
                    <legend class="sr-only">{{ t('partner.brand.texts') }}</legend>
                    <h2 class="text-[14.5px] font-semibold text-fg">{{ t('partner.brand.texts') }}</h2>
                    <div v-for="field in TEXTS" :key="field" class="grid gap-3 sm:grid-cols-2">
                        <AppField
                            v-for="locale in ['en', 'bn']"
                            :key="locale"
                            :label="`${t(`partner.brand.${field}`)} · ${t(locale === 'en' ? 'partner.brand.english' : 'partner.brand.bangla')}`"
                            :error="locale === 'en' ? errors[field] : null"
                            optional
                        >
                            <template #default="{ id }">
                                <!-- Plain elements: v-model on a dynamic <component> would not bind like an input. -->
                                <textarea v-if="field === 'login_text' || field === 'footer_text'" :id="id" v-model="form[field][locale]" rows="2" class="field-input" :lang="locale" />
                                <input v-else :id="id" v-model="form[field][locale]" class="field-input" :lang="locale" />
                            </template>
                        </AppField>
                    </div>
                </fieldset>

                <fieldset class="card space-y-4 p-5" :disabled="!canEdit">
                    <legend class="sr-only">{{ t('partner.brand.contact') }}</legend>
                    <h2 class="text-[14.5px] font-semibold text-fg">{{ t('partner.brand.contact') }}</h2>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField v-for="field in ['support_email', 'support_phone', 'terms_url', 'privacy_url']" :key="field" :label="t(`partner.brand.${field}`)" :error="errors[field]" optional>
                            <template #default="{ id, invalid, describedby }">
                                <input
                                    :id="id"
                                    v-model="form[field]"
                                    class="field-input"
                                    :type="field === 'support_email' ? 'email' : field.endsWith('_url') ? 'url' : 'tel'"
                                    dir="ltr"
                                    :aria-invalid="invalid || undefined"
                                    :aria-describedby="describedby"
                                />
                            </template>
                        </AppField>
                    </div>
                    <div class="flex items-center justify-between gap-3 border-t border-line pt-4">
                        <AppSwitch
                            :model-value="data.powered_by.shown"
                            :label="t('partner.brand.powered_by')"
                            show-label
                            :disabled="!canEdit || (!data.powered_by.removable && data.powered_by.shown)"
                            @update:model-value="togglePoweredBy"
                        />
                        <span v-if="!data.powered_by.removable" class="flex items-center gap-1.5 text-[12px] text-muted">
                            <Lock class="size-3.5" aria-hidden="true" />{{ t('partner.brand.powered_by_locked') }}
                        </span>
                    </div>
                </fieldset>
            </form>

            <!-- Live preview -->
            <aside class="card sticky top-20 overflow-hidden" :aria-label="t('partner.brand.preview')">
                <p class="border-b border-line px-4 py-2.5 text-[12px] font-medium tracking-wide text-muted uppercase">{{ t('partner.brand.preview') }}</p>
                <div class="space-y-4 p-5">
                    <div class="flex items-center gap-2.5">
                        <img v-if="data.resolved.mark_url" :src="data.resolved.mark_url" alt="" class="size-8 object-contain" />
                        <span v-else class="grid size-8 place-items-center rounded-lg text-[14px] font-bold" :style="{ background: previewColor, color: readableOn(previewColor) }">
                            {{ (form.product_name || data.resolved.name || '?').charAt(0).toUpperCase() }}
                        </span>
                        <span class="text-[15px] font-semibold text-fg">{{ form.product_name || data.resolved.name }}</span>
                    </div>
                    <p class="text-[18px] font-semibold text-fg">{{ form.login_title.en || t('auth.login.title', {}) }}</p>
                    <p v-if="form.login_text.en" class="text-[13px] text-muted">{{ form.login_text.en }}</p>
                    <div class="space-y-2">
                        <div class="h-9 rounded-lg border border-line bg-surface" />
                        <div class="h-9 rounded-lg border border-line bg-surface" />
                    </div>
                    <button type="button" tabindex="-1" class="h-10 w-full rounded-lg text-[14px] font-semibold" :style="{ background: previewColor, color: readableOn(previewColor) }">
                        {{ t('partner.brand.preview_button') }}
                    </button>
                    <p class="text-[13px] font-medium" :style="{ color: previewColor }">{{ form.support_email || 'support@example.com' }}</p>
                    <p v-if="data.resolved.powered_by" class="text-center text-[11.5px] text-faint">{{ t('auth.login.powered_by', { name: data.resolved.powered_by }) }}</p>
                </div>
            </aside>
        </div>
    </div>
</template>
