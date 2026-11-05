<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { onBeforeRouteLeave } from 'vue-router';
import { Lock, Plus, RotateCcw, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, AppSwitch, AppTabs, DateInput, PageFrame, TranslatedFields, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { languageName, t } from '@/lib/i18n';
import LinkList from './LinkList.vue';
import WebsiteNav from './WebsiteNav.vue';
import { changedMeanwhile, useWebsite } from './useWebsite';

/**
 * The website's own settings around its pages: look (template, colours,
 * languages), header and notice bar, contact and social links, footer,
 * and search details. Owners change them; everyone else reads them.
 * Saved as drafts; the public sees them after the next publish.
 */
const { resource, site, choices, can, apply } = useWebsite();

const tab = ref('look');
const TABS = computed(() => ['look', 'header', 'contact', 'footer', 'seo'].map((key) => ({ key, label: t(`website.settings.tabs.${key}`) })));
const PRESETS = [
    { key: 'ocean', primary: '#2563eb', accent: '#10b981', highlight: '#f43f5e' },
    { key: 'royal', primary: '#7c3aed', accent: '#0ea5e9', highlight: '#f59e0b' },
    { key: 'forest', primary: '#047857', accent: '#84cc16', highlight: '#f97316' },
    { key: 'sunset', primary: '#ea580c', accent: '#db2777', highlight: '#2563eb' },
    { key: 'navy', primary: '#1e3a8a', accent: '#0891b2', highlight: '#dc2626' },
];
const COLOR_KEYS = ['primary', 'accent', 'highlight'];

function blank() {
    return {
        template: 'aurora',
        locales: ['en'],
        default_locale: 'en',
        colors: { primary: '', accent: '', highlight: '' },
        header: { menu: [], cta_label: {}, cta_url: '', signin_label: {}, signin_url: '' },
        notice: { enabled: false, tag: {}, text: {}, link_label: {}, url: '', extra: {}, from: '', until: '' },
        contact: { phone: '', email: '', address: {} },
        social: Object.fromEntries((choices.value.social ?? []).map((network) => [network, ''])),
        footer: { about: {}, columns: [], copyright: {} },
        seo: { title: {}, description: {}, image_url: '', google_verification: '', bing_verification: '' },
    };
}

const form = reactive(blank());
const saved = ref('');
const errors = ref({});
const saving = ref(false);

// Fill the form from the site (deep copies; missing parts stay blank).
function load() {
    if (!site.value) return;
    const value = blank();
    const settings = JSON.parse(JSON.stringify(site.value.settings ?? {}));
    for (const part of ['colors', 'header', 'notice', 'contact', 'social', 'footer', 'seo']) {
        Object.assign(value[part], Object.fromEntries(Object.entries(settings[part] ?? {}).filter(([, item]) => item !== null)));
    }
    value.footer.columns = (value.footer.columns ?? []).map((column) => ({ title: column.title ?? {}, links: column.links ?? [] }));
    Object.assign(value, { template: site.value.template, locales: [...site.value.locales], default_locale: site.value.default_locale });
    Object.assign(form, value);
    saved.value = JSON.stringify(form);
}
watch(site, load, { immediate: true });

const dirty = computed(() => !!saved.value && JSON.stringify(form) !== saved.value);

function toggleLocale(code, on) {
    form.locales = on ? [...form.locales, code] : form.locales.filter((locale) => locale !== code);
    if (!form.locales.includes(form.default_locale)) form.default_locale = form.locales[0] ?? '';
}

function usePreset(preset) {
    COLOR_KEYS.forEach((key) => (form.colors[key] = preset[key]));
}

function addColumn() {
    form.footer.columns.push({ title: {}, links: [] });
}

// Empty strings are sent as nothing, so optional parts stay optional.
function clean(value) {
    if (Array.isArray(value)) return value.map(clean);
    if (value && typeof value === 'object') {
        return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, clean(item)]).filter(([, item]) => item !== null));
    }
    return typeof value === 'string' ? value.trim() || null : value;
}

async function save() {
    errors.value = {};
    const { template, locales, default_locale: defaultLocale, ...settings } = form;
    saving.value = true;
    try {
        const response = await api('/api/partner/website', {
            method: 'PATCH',
            body: { base_version: site.value.version, template, locales, default_locale: defaultLocale, settings: clean(settings) },
        });
        apply(response.data);
        toast.success(response.message);
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key.replace(/^settings\./, ''), messages[0]]));
        toast.error(Object.keys(errors.value).length ? t('website.settings.check_fields') : error.message);
        if (changedMeanwhile(error)) resource.reload();
    } finally {
        saving.value = false;
    }
}

onBeforeRouteLeave(async () => {
    if (!dirty.value) return true;
    return !!(await confirmAction({ title: t('website.unsaved_title'), message: t('website.unsaved_text'), confirmLabel: t('website.leave'), danger: true }));
});
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.settings.text')" :resource="resource" width="lg">
        <template #actions>
            <AppButton v-if="site && can.settings" variant="primary" :loading="saving" :disabled="!dirty" @click="save">{{ t('core.actions.save') }}</AppButton>
        </template>

        <WebsiteNav />

        <p v-if="!site" class="card p-5 text-[13.5px] text-muted">{{ t('website.not_started') }}</p>

        <template v-else>
            <p v-if="!can.settings" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('website.owner_only') }}
            </p>

            <AppTabs v-model="tab" :tabs="TABS" :label="t('website.settings.tabs_label')" />

            <form class="card mt-4 p-5" novalidate @submit.prevent="save">
                <fieldset :disabled="!can.settings" class="space-y-5">
                    <!-- Look -->
                    <template v-if="tab === 'look'">
                        <AppField :label="t('website.overview.template')" :error="errors.template">
                            <template #default="{ id }">
                                <select :id="id" v-model="form.template" class="field-input">
                                    <option v-for="template in choices.templates" :key="template" :value="template">{{ t(`website.templates.${template}`) }}</option>
                                </select>
                            </template>
                        </AppField>

                        <div>
                            <p class="text-[13px] font-medium text-fg">{{ t('website.settings.colors') }}</p>
                            <p class="text-[12.5px] text-muted">{{ t('website.settings.colors_hint') }}</p>
                            <div class="mt-3 flex flex-wrap gap-2">
                                <button v-for="preset in PRESETS" :key="preset.key" type="button" class="flex items-center gap-2 rounded-full border border-line px-3 py-1.5 text-[12.5px] hover:border-fg/30" @click="usePreset(preset)">
                                    <span class="flex -space-x-1" aria-hidden="true">
                                        <span v-for="key in COLOR_KEYS" :key="key" class="size-3.5 rounded-full ring-2 ring-surface" :style="{ background: preset[key] }" />
                                    </span>
                                    {{ t(`website.settings.presets.${preset.key}`) }}
                                </button>
                            </div>
                            <div class="mt-3 grid gap-4 sm:grid-cols-3">
                                <AppField v-for="key in COLOR_KEYS" :key="key" :label="t(`website.settings.color.${key}`)" :error="errors[`colors.${key}`]" optional>
                                    <template #default="{ id, invalid, describedby }">
                                        <span class="flex items-center gap-2">
                                            <input type="color" :value="form.colors[key] || '#2563eb'" class="h-10 w-12 shrink-0 cursor-pointer rounded-lg border border-line bg-surface" :aria-label="t(`website.settings.color.${key}`)" @input="form.colors[key] = $event.target.value" />
                                            <input :id="id" v-model="form.colors[key]" class="field-input font-mono" dir="ltr" maxlength="7" :placeholder="t('website.settings.brand_color')" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                                            <AppButton v-if="form.colors[key]" size="sm" variant="ghost" :icon="RotateCcw" :aria-label="t('website.settings.brand_color')" @click="form.colors[key] = ''" />
                                        </span>
                                    </template>
                                </AppField>
                            </div>
                        </div>

                        <div>
                            <p class="text-[13px] font-medium text-fg">{{ t('website.settings.languages') }}</p>
                            <div class="mt-2 flex flex-wrap gap-4">
                                <label v-for="code in choices.locales" :key="code" class="flex items-center gap-2 text-[13.5px]">
                                    <input type="checkbox" :checked="form.locales.includes(code)" :disabled="form.locales.length === 1 && form.locales.includes(code)" @change="toggleLocale(code, $event.target.checked)" />
                                    {{ languageName(code) }}
                                </label>
                            </div>
                            <p v-if="errors.locales" class="mt-1 text-[12.5px] text-bad" role="alert">{{ errors.locales }}</p>
                        </div>
                        <AppField :label="t('website.settings.main_language')" :hint="t('website.settings.main_language_hint')" :error="errors.default_locale">
                            <template #default="{ id }">
                                <select :id="id" v-model="form.default_locale" class="field-input">
                                    <option v-for="code in form.locales" :key="code" :value="code">{{ languageName(code) }}</option>
                                </select>
                            </template>
                        </AppField>
                    </template>

                    <!-- Header and notice bar -->
                    <template v-else-if="tab === 'header'">
                        <h3 class="text-[14px] font-semibold text-fg">{{ t('website.settings.menu') }}</h3>
                        <p class="-mt-3 text-[12.5px] text-muted">{{ t('website.settings.menu_hint') }}</p>
                        <LinkList v-model="form.header.menu" :max="8" :main-locale="form.default_locale" :errors="errors" error-prefix="header.menu" :disabled="!can.settings" :add-label="t('website.links.add')" />

                        <div class="grid gap-4 border-t border-line pt-5">
                            <TranslatedFields v-model="form.header.cta_label" :label="t('website.settings.cta_label')" :maxlength="30" :errors="errors" error-prefix="header.cta_label" />
                            <AppField :label="t('website.settings.cta_url')" :error="errors['header.cta_url']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.header.cta_url" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="app:/signup" /></template>
                            </AppField>
                            <TranslatedFields v-model="form.header.signin_label" :label="t('website.settings.signin_label')" :maxlength="30" :errors="errors" error-prefix="header.signin_label" />
                            <AppField :label="t('website.settings.signin_url')" :error="errors['header.signin_url']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.header.signin_url" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="app:/login" /></template>
                            </AppField>
                        </div>

                        <div class="grid gap-4 border-t border-line pt-5">
                            <h3 class="text-[14px] font-semibold text-fg">{{ t('website.settings.notice') }}</h3>
                            <AppSwitch v-model="form.notice.enabled" :label="t('website.settings.notice_on')" show-label :disabled="!can.settings" />
                            <TranslatedFields v-model="form.notice.tag" :label="t('website.settings.notice_tag')" :maxlength="16" :errors="errors" error-prefix="notice.tag" />
                            <TranslatedFields v-model="form.notice.text" :label="t('website.settings.notice_text')" :maxlength="140" :errors="errors" error-prefix="notice.text" />
                            <TranslatedFields v-model="form.notice.link_label" :label="t('website.settings.notice_link_label')" :maxlength="40" :errors="errors" error-prefix="notice.link_label" />
                            <AppField :label="t('website.links.url')" :error="errors['notice.url']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.notice.url" class="field-input font-mono" dir="ltr" maxlength="300" /></template>
                            </AppField>
                            <TranslatedFields v-model="form.notice.extra" :label="t('website.settings.notice_extra')" :maxlength="140" :errors="errors" error-prefix="notice.extra" />
                            <div class="grid gap-4 sm:grid-cols-2">
                                <AppField :label="t('website.from')" :error="errors['notice.from']" optional>
                                    <template #default="{ id }"><DateInput :id="id" v-model="form.notice.from" /></template>
                                </AppField>
                                <AppField :label="t('website.until')" :error="errors['notice.until']" optional>
                                    <template #default="{ id }"><DateInput :id="id" v-model="form.notice.until" :min="form.notice.from" /></template>
                                </AppField>
                            </div>
                        </div>
                    </template>

                    <!-- Contact and social links -->
                    <template v-else-if="tab === 'contact'">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppField :label="t('website.settings.phone')" :error="errors['contact.phone']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.contact.phone" type="tel" class="field-input" dir="ltr" maxlength="30" /></template>
                            </AppField>
                            <AppField :label="t('website.settings.email')" :error="errors['contact.email']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.contact.email" type="email" class="field-input" dir="ltr" maxlength="255" /></template>
                            </AppField>
                        </div>
                        <TranslatedFields v-model="form.contact.address" :label="t('website.settings.address')" multiline :maxlength="200" :errors="errors" error-prefix="contact.address" />
                        <h3 class="border-t border-line pt-5 text-[14px] font-semibold text-fg">{{ t('website.settings.social') }}</h3>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppField v-for="network in choices.social" :key="network" :label="t(`website.social.${network}`)" :error="errors[`social.${network}`]" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.social[network]" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="https://" /></template>
                            </AppField>
                        </div>
                    </template>

                    <!-- Footer -->
                    <template v-else-if="tab === 'footer'">
                        <TranslatedFields v-model="form.footer.about" :label="t('website.settings.about')" multiline :maxlength="300" :errors="errors" error-prefix="footer.about" />
                        <div v-for="(column, index) in form.footer.columns" :key="index" class="space-y-3 rounded-xl border border-line p-4">
                            <div class="flex items-start gap-2">
                                <div class="min-w-0 flex-1">
                                    <TranslatedFields v-model="column.title" :label="t('website.settings.column_title')" required :required-locale="form.default_locale" :maxlength="40" :errors="errors" :error-prefix="`footer.columns.${index}.title`" />
                                </div>
                                <AppButton v-if="can.settings" size="sm" variant="danger-soft" :icon="Trash2" class="mt-6" :aria-label="t('website.settings.remove_column')" @click="form.footer.columns.splice(index, 1)" />
                            </div>
                            <LinkList v-model="column.links" :max="8" :main-locale="form.default_locale" :errors="errors" :error-prefix="`footer.columns.${index}.links`" :disabled="!can.settings" :add-label="t('website.links.add')" />
                        </div>
                        <AppButton v-if="can.settings && form.footer.columns.length < 4" size="sm" :icon="Plus" @click="addColumn">{{ t('website.settings.add_column') }}</AppButton>
                        <TranslatedFields v-model="form.footer.copyright" :label="t('website.settings.copyright')" :maxlength="120" :errors="errors" error-prefix="footer.copyright" />
                    </template>

                    <!-- Search engines -->
                    <template v-else>
                        <p class="text-[12.5px] text-muted">{{ t('website.seo.site_hint') }}</p>
                        <TranslatedFields v-model="form.seo.title" :label="t('website.seo.search_title')" :maxlength="70" :errors="errors" error-prefix="seo.title" />
                        <TranslatedFields v-model="form.seo.description" :label="t('website.seo.description')" multiline :maxlength="170" :errors="errors" error-prefix="seo.description" />
                        <AppField :label="t('website.seo.image_url')" :hint="t('website.seo.image_hint')" :error="errors['seo.image_url']" optional>
                            <template #default="{ id }"><input :id="id" v-model="form.seo.image_url" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="https://" /></template>
                        </AppField>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <AppField :label="t('website.seo.google')" :hint="t('website.seo.verification_hint')" :error="errors['seo.google_verification']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.seo.google_verification" class="field-input font-mono" dir="ltr" maxlength="100" /></template>
                            </AppField>
                            <AppField :label="t('website.seo.bing')" :error="errors['seo.bing_verification']" optional>
                                <template #default="{ id }"><input :id="id" v-model="form.seo.bing_verification" class="field-input font-mono" dir="ltr" maxlength="64" /></template>
                            </AppField>
                        </div>
                    </template>
                </fieldset>
            </form>
            <p class="mt-3 text-[12.5px] text-muted">{{ t('website.overview.drafts_note') }}</p>
        </template>
    </PageFrame>
</template>
