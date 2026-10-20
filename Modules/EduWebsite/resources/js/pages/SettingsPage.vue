<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Check, Globe, ImagePlus, Save, TriangleAlert, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { languageName, t } from '@/lib/i18n';
import { cleanTexts, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import AddressField from '../components/AddressField.vue';
import DomainCard from '../components/DomainCard.vue';
import MediaPicker from '../components/MediaPicker.vue';
import TemplateCard from '../components/TemplateCard.vue';
import { COLOUR_SETS, carriesWhiteText } from '../lib';
import { useSite } from '../useSite';

/**
 * The website's settings: name and tagline per language, its address
 * (people who may publish), template (those the rules offer, with where a
 * level above set that), colours (ready pairs or one's own, checked for
 * white text), logo, contact details and languages. Saved together, with
 * the version seen (someone else's change in between is not overwritten).
 */
const { web, resource, site, meta, canManage, canPublish } = useSite();

const form = reactive({ title: {}, tagline: {}, slug: '', template: 'school', primary: '#0f6b4b', accent: '#f5b301', logo: '', share: '', contact: {}, locales: [], default_locale: 'bn' });
const errors = ref({});
const saving = ref(false);
const address = ref('ok');
const media = ref({});

function fill(value) {
    if (!value) return;
    Object.assign(form, {
        title: textsFor(value.title),
        tagline: textsFor(value.tagline),
        slug: value.slug,
        template: value.template,
        primary: value.theme?.primary ?? '#0f6b4b',
        accent: value.theme?.accent ?? '#f5b301',
        logo: value.logo_media_id ?? '',
        share: value.share_media_id ?? '',
        contact: {
            address: textsFor(value.contact?.address),
            hours: textsFor(value.contact?.hours),
            phone: value.contact?.phone ?? '',
            email: value.contact?.email ?? '',
            map_url: value.contact?.map_url ?? '',
        },
        locales: [...(value.locales ?? [])],
        default_locale: value.default_locale,
    });
    errors.value = {};
}
watch(site, fill, { immediate: true });

// Thumbnails of the logo and the sharing picture.
watch(
    site,
    async (value) => {
        const wanted = [value?.logo_media_id, value?.share_media_id].filter(Boolean);
        if (!wanted.length || wanted.every((id) => media.value[id])) return;
        try {
            media.value = Object.fromEntries((await web.media('image')).data.map((row) => [row.id, row]));
        } catch {
            // Only the thumbnail is missing; the setting still shows.
        }
    },
    { immediate: true },
);

// Which picture is being chosen: 'logo' or 'share'.
const picking = ref(null);
function pickPicture(ids, rows) {
    rows.forEach((row) => (media.value[row.id] = row));
    form[picking.value] = ids[0] ?? '';
    picking.value = null;
}

const readable = computed(() => carriesWhiteText(form.primary));
const slugChanged = computed(() => !!site.value && form.slug !== site.value.slug);
const canSave = computed(() => canManage.value && !!form.title[form.default_locale]?.trim() && form.locales.length > 0 && (!slugChanged.value || address.value === 'ok'));
const lockedBy = computed(() => meta.value.template_source?.name ?? '');

function toggleLocale(code) {
    form.locales = form.locales.includes(code) ? form.locales.filter((value) => value !== code) : [...form.locales, code];
    if (!form.locales.includes(form.default_locale) && form.locales.length) form.default_locale = form.locales[0];
}

async function save() {
    const text = (value) => String(value ?? '').trim() || null;
    const body = {
        base_version: site.value.version,
        title: cleanTexts(form.title),
        tagline: cleanTexts(form.tagline),
        template: form.template,
        theme: { primary: form.primary.toLowerCase(), accent: form.accent.toLowerCase() },
        logo_media_id: form.logo || null,
        share_media_id: form.share || null,
        contact: {
            address: cleanTexts(form.contact.address),
            hours: cleanTexts(form.contact.hours),
            phone: text(form.contact.phone),
            email: text(form.contact.email),
            map_url: text(form.contact.map_url),
        },
        locales: form.locales,
        default_locale: form.default_locale,
        ...(slugChanged.value && canPublish.value ? { slug: form.slug } : {}),
    };
    saving.value = true;
    errors.value = {};
    try {
        await web.update(body);
        toast.success(t('edu_website.settings.saved'));
        resource.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(error.message);
            resource.reload();
            return;
        }
        errors.value = error.errors ?? {};
        toast.error(Object.keys(errors.value).length ? t('edu_website.common.check_fields') : error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.settings.title')" :description="t('edu_website.settings.text')" />

        <ErrorState v-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <SkeletonRows v-else-if="!resource.data.value" :rows="6" />
        <section v-else-if="!site" class="card">
            <EmptyState :icon="Globe" :title="t('edu_website.overview.none')" :text="t('edu_website.common.start_first')">
                <AppButton variant="primary" to="/website">{{ t('edu_website.common.go_overview') }}</AppButton>
            </EmptyState>
        </section>

        <form v-else class="space-y-6" novalidate @submit.prevent="save">
            <p v-if="!canManage" class="rounded-lg bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('edu_website.common.view_only') }}</p>

            <fieldset class="card space-y-5 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.identity') }}</legend>
                <h2 class="text-[15px] font-semibold">{{ t('edu_website.settings.identity') }}</h2>
                <TranslatedFields :required-locale="form.default_locale" v-model="form.title" :label="t('edu_website.settings.name')" required :maxlength="120" :errors="errors" error-prefix="title" />
                <TranslatedFields :required-locale="form.default_locale" v-model="form.tagline" :label="t('edu_website.settings.tagline')" :maxlength="160" :errors="errors" error-prefix="tagline" />
                <AddressField v-if="canPublish" v-model="form.slug" :web="web" :domain="meta.sites_domain" :current="site.slug" :error="fieldError('slug')" @state="address = $event" />
                <AppField v-else :label="t('edu_website.settings.address')" :hint="t('edu_website.settings.address_publishers')">
                    <p class="font-mono text-[14px]" dir="ltr">{{ site.addresses.site.replace(/^https?:\/\//, '') }}</p>
                </AppField>
                <p v-if="slugChanged && site.published_at" class="flex items-start gap-2 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px]">
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('edu_website.settings.address_change_warning') }}
                </p>
            </fieldset>

            <fieldset class="card space-y-4 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.template') }}</legend>
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="me-auto text-[15px] font-semibold">{{ t('edu_website.settings.template') }}</h2>
                    <SourceBadge v-if="meta.template_source && meta.template_source.kind !== 'default'" v-bind="meta.template_source" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <TemplateCard
                        v-for="choice in meta.template_choices"
                        :key="choice.key"
                        :template="choice.key"
                        :offered="choice.offered || choice.key === site.template"
                        :selected="form.template === choice.key"
                        :primary="form.primary"
                        :accent="form.accent"
                        :locked-by="lockedBy"
                        @select="form.template = $event"
                    />
                </div>
            </fieldset>

            <fieldset class="card space-y-4 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.colours') }}</legend>
                <h2 class="text-[15px] font-semibold">{{ t('edu_website.settings.colours') }}</h2>
                <ul class="flex flex-wrap gap-3" :aria-label="t('edu_website.settings.colour_sets')">
                    <li v-for="set in COLOUR_SETS" :key="set.key">
                        <button
                            type="button"
                            class="flex items-center gap-2 rounded-full border py-1.5 pe-3 ps-1.5 text-[13px] transition"
                            :class="form.primary === set.primary && form.accent === set.accent ? 'border-brand ring-2 ring-brand' : 'border-line hover:border-line-strong'"
                            :aria-pressed="form.primary === set.primary && form.accent === set.accent"
                            @click="Object.assign(form, { primary: set.primary, accent: set.accent })"
                        >
                            <span class="flex" aria-hidden="true">
                                <span class="size-6 rounded-full ring-2 ring-surface" :style="{ background: set.primary }" />
                                <span class="-ms-2 size-6 rounded-full ring-2 ring-surface" :style="{ background: set.accent }" />
                            </span>
                            {{ t(`edu_website.colours.${set.key}`) }}
                        </button>
                    </li>
                </ul>
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-for="key in ['primary', 'accent']" :key="key" v-slot="{ id }" :label="t(`edu_website.settings.${key}`)" :hint="t(`edu_website.settings.${key}_hint`)" :error="fieldError(`theme.${key}`)">
                        <div class="flex items-center gap-2">
                            <input :id="`${id}-pick`" v-model="form[key]" type="color" class="h-10 w-14 cursor-pointer rounded-md border border-line bg-surface p-1" :aria-label="t(`edu_website.settings.${key}`)" />
                            <input :id="id" v-model="form[key]" class="field-input w-32 font-mono" dir="ltr" maxlength="7" />
                        </div>
                    </AppField>
                </div>
                <p v-if="!readable" class="flex items-start gap-2 rounded-lg bg-warn-soft px-3 py-2 text-[12.5px]">
                    <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />{{ t('edu_website.settings.contrast_low') }}
                </p>
                <p v-else class="flex items-center gap-2 text-[12.5px] text-ok"><Check class="size-4" aria-hidden="true" />{{ t('edu_website.settings.contrast_ok') }}</p>
            </fieldset>

            <fieldset class="card space-y-4 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.logo') }}</legend>
                <h2 class="text-[15px] font-semibold">{{ t('edu_website.settings.logo') }}</h2>
                <div class="flex flex-wrap items-center gap-4">
                    <span class="flex size-16 items-center justify-center overflow-hidden rounded-xl border border-line bg-subtle">
                        <img v-if="form.logo && media[form.logo]?.thumb" :src="media[form.logo].thumb" alt="" class="size-full object-cover" />
                        <span v-else class="flex size-full items-center justify-center text-[22px] font-bold text-white" :style="{ background: form.primary }" aria-hidden="true">{{ (form.title[form.default_locale] || form.title.en || '?').slice(0, 1).toUpperCase() }}</span>
                    </span>
                    <div class="space-y-2">
                        <p class="text-[12.5px] text-muted">{{ t('edu_website.settings.logo_hint') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <AppButton size="sm" :icon="ImagePlus" @click="picking = 'logo'">{{ form.logo ? t('edu_website.media.change') : t('edu_website.media.choose') }}</AppButton>
                            <AppButton v-if="form.logo" size="sm" variant="ghost" :icon="X" @click="form.logo = ''">{{ t('edu_website.settings.no_logo') }}</AppButton>
                        </div>
                    </div>
                </div>
                <div class="flex flex-wrap items-center gap-4 border-t border-line pt-4">
                    <span class="flex h-16 w-[122px] items-center justify-center overflow-hidden rounded-lg border border-line bg-subtle text-muted">
                        <img v-if="form.share && media[form.share]?.thumb" :src="media[form.share].thumb" alt="" class="size-full object-cover" />
                        <ImagePlus v-else class="size-6" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1 space-y-2">
                        <p class="text-[13px] font-medium">{{ t('edu_website.settings.share') }}</p>
                        <p class="text-[12.5px] text-muted">{{ t('edu_website.settings.share_hint') }}</p>
                        <div class="flex flex-wrap gap-2">
                            <AppButton size="sm" :icon="ImagePlus" @click="picking = 'share'">{{ form.share ? t('edu_website.media.change') : t('edu_website.media.choose') }}</AppButton>
                            <AppButton v-if="form.share" size="sm" variant="ghost" :icon="X" @click="form.share = ''">{{ t('edu_website.media.remove_from_block') }}</AppButton>
                        </div>
                    </div>
                </div>
            </fieldset>

            <fieldset class="card space-y-5 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.contact') }}</legend>
                <h2 class="text-[15px] font-semibold">{{ t('edu_website.settings.contact') }}</h2>
                <TranslatedFields :required-locale="form.default_locale" v-model="form.contact.address" :label="t('edu_website.settings.contact_address')" multiline :maxlength="300" :errors="errors" error-prefix="contact.address" />
                <TranslatedFields :required-locale="form.default_locale" v-model="form.contact.hours" :label="t('edu_website.settings.contact_hours')" :maxlength="200" :errors="errors" error-prefix="contact.hours" />
                <div class="grid gap-4 sm:grid-cols-2">
                    <AppField v-slot="{ id }" :label="t('edu_website.settings.contact_phone')" :error="fieldError('contact.phone')" optional>
                        <input :id="id" v-model="form.contact.phone" class="field-input" dir="ltr" inputmode="tel" maxlength="40" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('edu_website.settings.contact_email')" :error="fieldError('contact.email')" optional>
                        <input :id="id" v-model="form.contact.email" type="email" class="field-input" dir="ltr" maxlength="150" />
                    </AppField>
                </div>
                <AppField v-slot="{ id }" :label="t('edu_website.settings.contact_map')" :hint="t('edu_website.settings.contact_map_hint')" :error="fieldError('contact.map_url')" optional>
                    <input :id="id" v-model="form.contact.map_url" class="field-input" dir="ltr" inputmode="url" maxlength="500" placeholder="https://maps.google.com/…" />
                </AppField>
            </fieldset>

            <fieldset class="card space-y-4 p-5 sm:p-6" :disabled="!canManage">
                <legend class="sr-only">{{ t('edu_website.settings.languages') }}</legend>
                <h2 class="text-[15px] font-semibold">{{ t('edu_website.settings.languages') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('edu_website.settings.languages_hint') }}</p>
                <div class="flex flex-wrap gap-3">
                    <label v-for="code in meta.languages" :key="code" class="flex cursor-pointer items-center gap-2 rounded-lg border border-line px-3 py-2 text-[14px]">
                        <input type="checkbox" class="size-4 accent-[var(--color-brand)]" :checked="form.locales.includes(code)" @change="toggleLocale(code)" />
                        {{ languageName(code) }}
                    </label>
                </div>
                <p v-if="fieldError('locales')" class="text-[12.5px] text-bad">{{ fieldError('locales') }}</p>
                <AppField v-slot="{ id }" :label="t('edu_website.settings.main_language')" :error="fieldError('default_locale')">
                    <select :id="id" v-model="form.default_locale" class="field-input sm:max-w-64">
                        <option v-for="code in form.locales" :key="code" :value="code">{{ languageName(code) }}</option>
                    </select>
                </AppField>
            </fieldset>

            <div v-if="canManage" class="sticky bottom-0 z-10 -mx-4 flex flex-wrap items-center justify-end gap-3 border-t border-line bg-canvas/95 px-4 py-3 backdrop-blur sm:mx-0 sm:rounded-xl sm:border">
                <p class="me-auto text-[12.5px] text-muted">{{ t('edu_website.settings.save_hint') }}</p>
                <AppButton variant="ghost" @click="fill(site)">{{ t('edu_website.common.undo_changes') }}</AppButton>
                <AppButton variant="primary" type="submit" :icon="Save" :loading="saving" :disabled="!canSave">{{ t('edu_website.common.save') }}</AppButton>
            </div>

            <MediaPicker :open="picking !== null" :web="web" kind="image" :selected="picking && form[picking] ? [form[picking]] : []" :max-mb="meta.max_media_mb?.value ?? 5" :can-upload="canManage" @close="picking = null" @pick="pickPicture" />
        </form>

        <DomainCard v-if="site" :web="web" :site="site" :rule="meta.own_domain" :can-publish="canPublish" @changed="resource.reload()" />
    </div>
</template>
