<script setup>
import { computed, reactive, ref } from 'vue';
import { RouterLink } from 'vue-router';
import { ArrowDown, ArrowUp, Eye, FileText, Home, LayoutList, Lock, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, AppSwitch, PageFrame, TranslatedFields, confirmAction, toast } from '@/ui';
import { api } from '@/lib/http';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import WebsiteNav from './WebsiteNav.vue';
import { changedMeanwhile, openPreview, useWebsite } from './useWebsite';

/**
 * The website's pages: add one, change its title, address and search
 * details, show it in the menu or not, move it up or down, delete it (the
 * home page stays). Changes are drafts until the owner publishes.
 */
const { resource, site, can, mainLocale, pick } = useWebsite();
const pages = computed(() => site.value?.pages ?? []);

const editing = ref(null); // null, 'new' or a page
const form = reactive({ title: {}, slug: '', in_menu: true, seo: { title: {}, description: {}, image_url: '', noindex: false } });
const errors = ref({});
const saving = ref(false);
const busy = ref(null);

function open(page = null) {
    errors.value = {};
    Object.assign(form, {
        title: { ...(page?.title ?? {}) },
        slug: page?.slug ?? '',
        in_menu: page?.in_menu ?? true,
        seo: {
            title: { ...(page?.seo?.title ?? {}) },
            description: { ...(page?.seo?.description ?? {}) },
            image_url: page?.seo?.image_url ?? '',
            noindex: !!page?.seo?.noindex,
        },
    });
    editing.value = page ?? 'new';
}

// A new page's address follows its title until it is typed by hand.
let slugTouched = false;
function titleChanged(value) {
    form.title = value;
    if (editing.value === 'new' && !slugTouched) {
        form.slug = (value.en ?? '').toLowerCase().normalize('NFKD').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
    }
}

function flatErrors(error) {
    return Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, messages]) => [key, messages[0]]));
}

async function save() {
    errors.value = {};
    const isNew = editing.value === 'new';
    const body = {
        title: form.title,
        in_menu: form.in_menu,
        seo: { ...form.seo, image_url: form.seo.image_url.trim() || null },
        ...(isNew || !editing.value.is_home ? { slug: form.slug.trim() } : {}),
        ...(isNew ? {} : { base_version: editing.value.version }),
    };
    saving.value = true;
    try {
        const response = isNew
            ? await api('/api/partner/website/pages', { method: 'POST', body })
            : await api(`/api/partner/website/pages/${editing.value.id}`, { method: 'PATCH', body });
        toast.success(response.message);
        editing.value = null;
        slugTouched = false;
        resource.reload();
    } catch (error) {
        errors.value = flatErrors(error);
        if (!Object.keys(errors.value).length) toast.error(error.message);
        if (changedMeanwhile(error)) {
            editing.value = null;
            resource.reload();
        }
    } finally {
        saving.value = false;
    }
}

async function toggleMenu(page, value) {
    busy.value = page.id;
    try {
        await api(`/api/partner/website/pages/${page.id}`, { method: 'PATCH', body: { base_version: page.version, in_menu: value } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
        resource.reload();
    }
}

async function move(index, step) {
    const ids = pages.value.map((page) => page.id);
    [ids[index], ids[index + step]] = [ids[index + step], ids[index]];
    busy.value = 'order';
    try {
        await api('/api/partner/website/pages/order', { method: 'PUT', body: { ids } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
        resource.reload();
    }
}

async function remove(page) {
    const confirmed = await confirmAction({
        title: t('website.pages.delete_title', { title: pick(page.title) }),
        message: t('website.pages.delete_text', { count: formatNumber(page.blocks.length) }),
        confirmLabel: t('website.pages.delete'),
        danger: true,
    });
    if (!confirmed) return;
    try {
        const response = await api(`/api/partner/website/pages/${page.id}`, { method: 'DELETE' });
        toast.success(response.message);
    } catch (error) {
        toast.error(error.message);
    } finally {
        resource.reload();
    }
}

async function preview(page) {
    try {
        await openPreview(page.is_home ? null : page.slug);
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('website.title')" :description="t('website.pages.text')" :resource="resource" width="lg">
        <template #actions>
            <AppButton v-if="can.write" variant="primary" :icon="Plus" @click="open()">{{ t('website.pages.add') }}</AppButton>
        </template>

        <WebsiteNav />

        <p v-if="!site" class="card p-5 text-[13.5px] text-muted">{{ t('website.not_started') }}</p>

        <template v-else>
            <p v-if="!can.write" class="mb-4 flex items-center gap-2 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                <Lock class="size-4 shrink-0 text-muted" aria-hidden="true" />{{ t('website.read_only') }}
            </p>

            <ul class="card divide-y divide-line">
                <li v-for="(page, index) in pages" :key="page.id" class="flex flex-wrap items-center gap-3 px-4 py-3.5 sm:px-5">
                    <component :is="page.is_home ? Home : FileText" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    <span class="min-w-0 flex-1">
                        <RouterLink :to="`/partner/website/pages/${page.id}`" class="block truncate text-[14px] font-medium text-fg hover:text-brand-text hover:underline">{{ pick(page.title) }}</RouterLink>
                        <span class="block truncate text-[12.5px] text-muted">
                            <span class="font-mono" dir="ltr">/{{ page.is_home ? '' : page.slug }}</span>
                            · {{ t('website.pages.sections', { count: formatNumber(page.blocks.length) }) }}
                        </span>
                    </span>
                    <AppBadge v-if="page.is_home" tone="brand">{{ t('website.pages.home') }}</AppBadge>
                    <AppSwitch
                        :model-value="page.in_menu"
                        :label="t('website.pages.in_menu')"
                        show-label
                        :disabled="!can.write"
                        :busy="busy === page.id"
                        @update:model-value="toggleMenu(page, $event)"
                    />
                    <span class="flex items-center gap-1">
                        <template v-if="can.write">
                            <AppButton size="sm" variant="ghost" :icon="ArrowUp" :disabled="index === 0 || busy === 'order'" :aria-label="t('website.move_up')" @click="move(index, -1)" />
                            <AppButton size="sm" variant="ghost" :icon="ArrowDown" :disabled="index === pages.length - 1 || busy === 'order'" :aria-label="t('website.move_down')" @click="move(index, 1)" />
                        </template>
                        <AppButton size="sm" variant="ghost" :icon="Eye" :aria-label="`${t('website.overview.preview')} ${pick(page.title)}`" @click="preview(page)" />
                        <AppButton size="sm" :icon="LayoutList" @click="$router.push(`/partner/website/pages/${page.id}`)">{{ t('website.editor.sections') }}</AppButton>
                        <AppButton v-if="can.write" size="sm" :icon="Pencil" :aria-label="`${t('website.pages.edit')} ${pick(page.title)}`" @click="open(page)" />
                        <AppButton v-if="can.write && !page.is_home" size="sm" variant="danger-soft" :icon="Trash2" :aria-label="`${t('website.pages.delete')} ${pick(page.title)}`" @click="remove(page)" />
                    </span>
                </li>
            </ul>
            <p class="mt-3 text-[12.5px] text-muted">{{ t('website.overview.drafts_note') }}</p>
        </template>

        <AppDialog :open="!!editing" :title="editing === 'new' ? t('website.pages.add') : t('website.pages.edit')" :icon="FileText" size="lg" @close="editing = null">
            <form id="page-form" class="space-y-4" novalidate @submit.prevent="save">
                <TranslatedFields :model-value="form.title" :label="t('website.pages.title_field')" required :required-locale="mainLocale" :maxlength="80" :errors="errors" error-prefix="title" autofocus @update:model-value="titleChanged" />
                <AppField v-if="editing === 'new' || !editing?.is_home" :label="t('website.pages.slug')" :hint="t('website.pages.slug_hint')" :error="errors.slug">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.slug" class="field-input font-mono" dir="ltr" maxlength="80" autocapitalize="off" spellcheck="false" :aria-invalid="invalid || undefined" :aria-describedby="describedby" @input="slugTouched = true" />
                    </template>
                </AppField>
                <AppSwitch v-model="form.in_menu" :label="t('website.pages.in_menu')" show-label />

                <fieldset class="space-y-4 rounded-xl border border-line p-4">
                    <legend class="px-1 text-[13px] font-semibold text-fg">{{ t('website.seo.title') }}</legend>
                    <p class="text-[12.5px] text-muted">{{ t('website.seo.page_hint') }}</p>
                    <TranslatedFields v-model="form.seo.title" :label="t('website.seo.search_title')" :maxlength="70" :errors="errors" error-prefix="seo.title" />
                    <TranslatedFields v-model="form.seo.description" :label="t('website.seo.description')" multiline :maxlength="170" :errors="errors" error-prefix="seo.description" />
                    <AppField :label="t('website.seo.image_url')" :hint="t('website.seo.image_hint')" :error="errors['seo.image_url']" optional>
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.seo.image_url" class="field-input font-mono" dir="ltr" maxlength="300" placeholder="https://" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>
                    <AppSwitch v-model="form.seo.noindex" :label="t('website.seo.noindex')" show-label />
                </fieldset>
            </form>
            <template #footer>
                <AppButton @click="editing = null">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="page-form" variant="primary" :loading="saving">{{ t('core.actions.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
