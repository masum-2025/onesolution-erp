<script setup>
import { computed, ref, watch } from 'vue';
import { RouterLink, onBeforeRouteLeave, useRoute } from 'vue-router';
import { ArrowDown, ArrowLeft, ArrowUp, Eye, EyeOff, Lock, Monitor, Pencil, Plus, RefreshCw, Smartphone, Trash2, X } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, AppSegmented, AppSwitch, DateInput, PageFrame, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { languageName, t } from '@/lib/i18n';
import SchemaField from './SchemaField.vue';
import { blankData, cleanData, summaryOf, withDefaults } from './blockForm';
import { changedMeanwhile, openPreview, useWebsite } from './useWebsite';

/**
 * One page of the website, section by section: add a section of any kind,
 * fill it in (the form is drawn from the server's description of that
 * kind), move it, hide it or show it between two days, delete it. The
 * drafts show beside it in a live preview, reloaded after every save.
 */
const route = useRoute();
const { resource, site, choices, can, mainLocale, pick } = useWebsite();
const page = computed(() => site.value?.pages.find((item) => item.id === route.params.id) ?? null);
const blocks = computed(() => page.value?.blocks ?? []);
const fieldsOf = (type) => choices.value.fields?.[type] ?? [];

// Editing one section at a time: { id (null when new), type, data, is_visible, visible_from, visible_until, version }.
const editing = ref(null);
const saved = ref('');
const errors = ref({});
const saving = ref(false);
const busy = ref(null);
const dirty = computed(() => !!editing.value && JSON.stringify(editing.value) !== saved.value);

async function keepOrDiscard() {
    if (!dirty.value) return true;
    return !!(await confirmAction({ title: t('website.unsaved_title'), message: t('website.unsaved_text'), confirmLabel: t('website.leave'), danger: true }));
}

async function edit(block) {
    if (!(await keepOrDiscard())) return;
    errors.value = {};
    editing.value = {
        id: block.id, type: block.type, data: withDefaults(fieldsOf(block.type), block.data),
        is_visible: block.is_visible, visible_from: block.visible_from ?? '', visible_until: block.visible_until ?? '', version: block.version,
    };
    saved.value = JSON.stringify(editing.value);
}

const adding = ref(false);
async function add(type) {
    adding.value = false;
    if (!(await keepOrDiscard())) return;
    errors.value = {};
    editing.value = { id: null, type, data: blankData(fieldsOf(type)), is_visible: true, visible_from: '', visible_until: '', version: null };
    saved.value = '';
}

async function closeForm() {
    if (await keepOrDiscard()) editing.value = null;
}

async function save() {
    const value = editing.value;
    errors.value = {};
    const body = {
        data: cleanData(value.data),
        is_visible: value.is_visible,
        visible_from: value.visible_from || null,
        visible_until: value.visible_until || null,
        ...(value.id ? { base_version: value.version } : { type: value.type }),
    };
    saving.value = true;
    try {
        const response = value.id
            ? await api(`/api/partner/website/blocks/${value.id}`, { method: 'PATCH', body })
            : await api(`/api/partner/website/pages/${page.value.id}/blocks`, { method: 'POST', body });
        toast.success(response.message);
        editing.value = null;
        await resource.reload();
        refreshPreview();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
        toast.error(Object.keys(errors.value).length ? t('website.settings.check_fields') : error.message);
        if (changedMeanwhile(error)) {
            editing.value = null;
            resource.reload();
        }
    } finally {
        saving.value = false;
    }
}

async function quick(key, request) {
    busy.value = key;
    try {
        await request();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
        await resource.reload();
        refreshPreview();
    }
}

function toggle(block) {
    quick(block.id, () => api(`/api/partner/website/blocks/${block.id}`, { method: 'PATCH', body: { base_version: block.version, is_visible: !block.is_visible } }));
}

function move(index, step) {
    const ids = blocks.value.map((block) => block.id);
    [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
    quick('order', () => api(`/api/partner/website/pages/${page.value.id}/blocks/order`, { method: 'PUT', body: { ids } }));
}

async function remove(block) {
    const confirmed = await confirmAction({
        title: t('website.editor.delete_title', { type: t(`website.types.${block.type}`) }),
        message: t('website.editor.delete_text'),
        confirmLabel: t('website.editor.delete'),
        danger: true,
    });
    if (!confirmed) return;
    if (editing.value?.id === block.id) editing.value = null;
    quick(block.id, () => api(`/api/partner/website/blocks/${block.id}`, { method: 'DELETE' }));
}

// The live preview: a signed link (an hour), this page, one language; reloaded after each save.
const previewBase = ref(null);
const previewAt = ref(0);
const previewKey = ref(0);
const device = ref('desktop');
const lang = ref(null);
const pane = ref('sections'); // small screens show one side at a time

async function refreshPreview() {
    if (!site.value) return;
    try {
        if (!previewBase.value || Date.now() - previewAt.value > 50 * 60 * 1000) {
            previewBase.value = (await api('/api/partner/website/preview-link', { method: 'POST' })).data.url;
            previewAt.value = Date.now();
        }
        previewKey.value++;
    } catch (error) {
        toast.error(error.message);
    }
}
watch(site, (value) => {
    if (value && !previewBase.value) {
        lang.value ??= value.default_locale;
        refreshPreview();
    }
}, { immediate: true });

const previewUrl = computed(() => {
    if (!previewBase.value || !page.value) return null;
    const query = new URLSearchParams({ lang: lang.value ?? mainLocale.value });
    if (!page.value.is_home) query.set('page', page.value.slug);
    return `${previewBase.value}&${query}`;
});
const languages = computed(() => (site.value?.locales ?? []).map((code) => ({ value: code, label: languageName(code) })));
const devices = computed(() => [
    { value: 'desktop', label: t('website.editor.desktop'), icon: Monitor },
    { value: 'phone', label: t('website.editor.phone'), icon: Smartphone },
]);

onBeforeRouteLeave(keepOrDiscard);
</script>

<template>
    <PageFrame :title="page ? pick(page.title) : t('website.title')" :description="t('website.editor.text')" :resource="resource" width="full">
        <template #actions>
            <AppButton :icon="ArrowLeft" @click="$router.push('/partner/website/pages')">{{ t('website.nav.pages') }}</AppButton>
            <AppButton v-if="page" :icon="Eye" @click="openPreview(page.is_home ? null : page.slug).catch((error) => toast.error(error.message))">{{ t('website.overview.preview') }}</AppButton>
        </template>

        <p v-if="!page" class="card p-5 text-[13.5px] text-muted">
            {{ t('website.editor.missing') }}
            <RouterLink to="/partner/website/pages" class="font-medium text-brand-text hover:underline">{{ t('website.nav.pages') }}</RouterLink>
        </p>

        <template v-else>
            <AppSegmented v-model="pane" class="mb-4 lg:hidden" block :label="t('website.editor.panes')" :options="[{ value: 'sections', label: t('website.editor.sections') }, { value: 'preview', label: t('website.overview.preview') }]" />

            <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.15fr)] lg:items-start">
                <!-- Sections and the open form -->
                <div class="space-y-4" :class="pane === 'preview' ? 'max-lg:hidden' : ''">
                    <p v-if="!can.write" class="flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                        <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('website.read_only') }}
                    </p>

                    <section v-if="editing" class="card p-5">
                        <div class="mb-4 flex items-center gap-2">
                            <h2 class="min-w-0 flex-1 text-[15px] font-semibold text-fg">
                                {{ editing.id ? t('website.editor.edit', { type: t(`website.types.${editing.type}`) }) : t('website.editor.new', { type: t(`website.types.${editing.type}`) }) }}
                            </h2>
                            <AppButton size="sm" variant="ghost" :icon="X" :aria-label="t('core.actions.cancel')" @click="closeForm" />
                        </div>
                        <form id="block-form" class="space-y-5" novalidate @submit.prevent="save">
                            <SchemaField
                                v-for="field in fieldsOf(editing.type)"
                                :key="field.key"
                                :field="field"
                                :model-value="editing.data[field.key]"
                                :path="`data.${field.key}`"
                                :errors="errors"
                                :main-locale="mainLocale"
                                :choices="choices"
                                :root="editing.data"
                                @update:model-value="editing.data[field.key] = $event"
                            />
                            <fieldset class="space-y-4 rounded-xl border border-line p-4">
                                <legend class="px-1 text-[13px] font-semibold text-fg">{{ t('website.editor.when') }}</legend>
                                <AppSwitch v-model="editing.is_visible" :label="t('website.editor.visible')" show-label />
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <AppField :label="t('website.from')" :error="errors.visible_from" optional>
                                        <template #default="{ id }"><DateInput :id="id" v-model="editing.visible_from" /></template>
                                    </AppField>
                                    <AppField :label="t('website.until')" :error="errors.visible_until" optional>
                                        <template #default="{ id }"><DateInput :id="id" v-model="editing.visible_until" :min="editing.visible_from" /></template>
                                    </AppField>
                                </div>
                            </fieldset>
                            <p v-if="errors.type" class="text-[12.5px] text-bad" role="alert">{{ errors.type }}</p>
                        </form>
                        <div class="mt-5 flex justify-end gap-2">
                            <AppButton @click="closeForm">{{ t('core.actions.cancel') }}</AppButton>
                            <AppButton type="submit" form="block-form" variant="primary" :loading="saving">{{ t('core.actions.save') }}</AppButton>
                        </div>
                    </section>

                    <section class="card">
                        <div class="flex items-center gap-2 px-5 pt-4">
                            <h2 class="min-w-0 flex-1 text-[14px] font-semibold text-fg">{{ t('website.editor.sections') }}</h2>
                            <AppButton v-if="can.write" size="sm" variant="primary" :icon="Plus" @click="adding = true">{{ t('website.editor.add') }}</AppButton>
                        </div>
                        <p v-if="!blocks.length" class="px-5 py-4 text-[13px] text-muted">{{ t('website.editor.empty') }}</p>
                        <ul v-else class="mt-2 divide-y divide-line">
                            <li v-for="(block, index) in blocks" :key="block.id" class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-5" :class="editing?.id === block.id ? 'bg-brand/5' : ''">
                                <span class="min-w-0 flex-1" :class="block.is_visible ? '' : 'opacity-60'">
                                    <span class="block text-[13.5px] font-medium text-fg">{{ t(`website.types.${block.type}`) }}</span>
                                    <span class="block truncate text-[12.5px] text-muted">{{ summaryOf(block.data, pick) || t(`website.types.${block.type}_text`) }}</span>
                                </span>
                                <AppBadge v-if="!block.is_visible" tone="neutral">{{ t('website.editor.hidden') }}</AppBadge>
                                <AppBadge v-else-if="block.visible_from || block.visible_until" tone="warn">{{ t('website.editor.dated') }}</AppBadge>
                                <span v-if="can.write" class="flex items-center gap-0.5">
                                    <AppButton size="sm" variant="ghost" :icon="ArrowUp" :disabled="index === 0 || busy === 'order'" :aria-label="t('website.move_up')" @click="move(index, -1)" />
                                    <AppButton size="sm" variant="ghost" :icon="ArrowDown" :disabled="index === blocks.length - 1 || busy === 'order'" :aria-label="t('website.move_down')" @click="move(index, 1)" />
                                    <AppButton size="sm" variant="ghost" :icon="block.is_visible ? EyeOff : Eye" :loading="busy === block.id" :aria-label="block.is_visible ? t('website.editor.hide') : t('website.editor.show')" @click="toggle(block)" />
                                    <AppButton size="sm" :icon="Pencil" :aria-label="t('website.editor.edit', { type: t(`website.types.${block.type}`) })" @click="edit(block)" />
                                    <AppButton size="sm" variant="danger-soft" :icon="Trash2" :aria-label="t('website.editor.delete')" @click="remove(block)" />
                                </span>
                            </li>
                        </ul>
                    </section>
                    <p class="text-[12.5px] text-muted">{{ t('website.overview.drafts_note') }}</p>
                </div>

                <!-- Live preview -->
                <section class="card overflow-hidden lg:sticky lg:top-4" :class="pane === 'sections' ? 'max-lg:hidden' : ''">
                    <div class="flex flex-wrap items-center gap-2 border-b border-line px-3 py-2">
                        <AppSegmented v-model="device" size="sm" icon-only :label="t('website.editor.device')" :options="devices" />
                        <AppSegmented v-if="languages.length > 1" v-model="lang" size="sm" :label="t('website.settings.languages')" :options="languages" />
                        <span class="flex-1" />
                        <AppButton size="sm" variant="ghost" :icon="RefreshCw" :aria-label="t('website.editor.reload')" @click="refreshPreview" />
                    </div>
                    <div class="grid place-items-center bg-subtle/60 p-2">
                        <iframe
                            v-if="previewUrl"
                            :key="previewKey"
                            :src="previewUrl"
                            :title="t('website.editor.preview_title')"
                            class="h-[75vh] rounded-lg border border-line bg-white transition-[width]"
                            :class="device === 'phone' ? 'w-[390px] max-w-full' : 'w-full'"
                            referrerpolicy="no-referrer"
                        />
                        <p v-else class="p-10 text-[13px] text-muted">{{ t('core.states.loading') }}</p>
                    </div>
                </section>
            </div>
        </template>

        <AppDialog :open="adding" :title="t('website.editor.add')" :icon="Plus" size="lg" @close="adding = false">
            <div class="grid gap-2 sm:grid-cols-2">
                <button
                    v-for="type in choices.block_types"
                    :key="type"
                    type="button"
                    class="rounded-xl border border-line p-3.5 text-start transition hover:border-brand hover:bg-brand/5"
                    @click="add(type)"
                >
                    <span class="block text-[13.5px] font-medium text-fg">{{ t(`website.types.${type}`) }}</span>
                    <span class="block text-[12.5px] text-muted">{{ t(`website.types.${type}_text`) }}</span>
                </button>
            </div>
            <template #footer>
                <AppButton @click="adding = false">{{ t('core.actions.cancel') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
