<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowDown, ArrowUp, FilePlus, FileStack, Globe, House, PenLine, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { cleanTexts, textsFor } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import { slugFrom } from '../lib';
import { useSite } from '../useSite';

/**
 * The website's pages in menu order: moved up and down (buttons, so they
 * work by keyboard and on phones), shown in the menu or not, opened to
 * edit their blocks, removed after a confirmation (the home page stays).
 * How many pages the plan allows is shown with where that comes from.
 */
const { web, resource, site, meta, canManage, pick } = useSite();
const list = useResource(() => web.pages());
const pages = computed(() => list.data.value?.data ?? []);
const max = computed(() => meta.value.max_pages?.value ?? 0);
const full = computed(() => pages.value.length >= max.value);

const busy = ref(null);

async function move(index, step) {
    const ids = pages.value.map((page) => page.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + step, 0, id);
    busy.value = id;
    try {
        await web.orderPages(ids);
        await list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function toggleMenu(page) {
    busy.value = page.id;
    try {
        await web.updatePage(page.id, { in_menu: !page.in_menu, base_version: page.version });
        await list.reload();
    } catch (error) {
        toast.error(error.message);
        list.reload();
    } finally {
        busy.value = null;
    }
}

async function remove(page) {
    const confirmed = await confirmAction({
        title: t('edu_website.pages.remove_title'),
        message: t('edu_website.pages.remove_text', { name: pick(page.title), count: formatNumber(page.blocks?.length ?? 0) }),
        confirmLabel: t('edu_website.common.remove'),
        danger: true,
    });
    if (!confirmed) return;
    busy.value = page.id;
    try {
        await web.removePage(page.id, page.version);
        toast.success(t('edu_website.pages.removed'));
        list.reload();
        resource.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

// Adding or renaming a page.
const editing = ref(null);
const form = reactive({ title: {}, slug: '', in_menu: true });
const slugTouched = ref(false);
const errors = ref({});
const saving = ref(false);

function edit(page = null) {
    Object.assign(form, { title: textsFor(page?.title), slug: page?.slug ?? '', in_menu: page?.in_menu ?? true });
    slugTouched.value = !!page;
    errors.value = {};
    editing.value = page ?? 'new';
}

function titleChanged(value) {
    form.title = value;
    if (!slugTouched.value) form.slug = slugFrom(value.en);
}

async function save() {
    saving.value = true;
    errors.value = {};
    const body = { title: cleanTexts(form.title), in_menu: form.in_menu, ...(editing.value === 'new' || !editing.value.is_home ? { slug: form.slug } : {}) };
    try {
        if (editing.value === 'new') await web.createPage(body);
        else await web.updatePage(editing.value.id, { ...body, base_version: editing.value.version });
        toast.success(t('edu_website.pages.saved'));
        editing.value = null;
        list.reload();
        resource.reload();
    } catch (error) {
        if (error.code === 'slug_taken') errors.value = { slug: [error.message] };
        else errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div class="space-y-6">
        <PageHeader :title="t('edu_website.pages.title')" :description="t('edu_website.pages.text')">
            <template v-if="site && canManage" #actions>
                <AppButton variant="primary" :icon="FilePlus" :disabled="full" @click="edit()">{{ t('edu_website.pages.add') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="resource.error.value" :error="resource.error.value" @retry="resource.reload()" />
        <SkeletonRows v-else-if="!resource.data.value" :rows="4" />
        <section v-else-if="!site" class="card">
            <EmptyState :icon="Globe" :title="t('edu_website.overview.none')" :text="t('edu_website.common.start_first')">
                <AppButton variant="primary" to="/website">{{ t('edu_website.common.go_overview') }}</AppButton>
            </EmptyState>
        </section>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2 text-[13px]">
                <span :class="full ? 'font-medium text-warn' : 'text-fg-2'">{{ t('edu_website.pages.count', { count: formatNumber(pages.length), max: formatNumber(max) }) }}</span>
                <SourceBadge v-if="meta.max_pages?.source" v-bind="meta.max_pages.source" />
                <span v-if="full" class="text-muted">· {{ t('edu_website.pages.full') }}</span>
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!pages.length" :icon="FileStack" :title="t('edu_website.pages.empty')" compact />
                <ol v-else class="divide-y divide-line">
                    <li v-for="(page, index) in pages" :key="page.id" class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3 sm:px-5">
                        <div v-if="canManage" class="flex flex-col">
                            <AppButton size="icon-sm" variant="ghost" :icon="ArrowUp" :aria-label="t('edu_website.common.move_up')" :disabled="index === 0 || busy !== null" @click="move(index, -1)" />
                            <AppButton size="icon-sm" variant="ghost" :icon="ArrowDown" :aria-label="t('edu_website.common.move_down')" :disabled="index === pages.length - 1 || busy !== null" @click="move(index, 1)" />
                        </div>
                        <RouterLink :to="`/website/pages/${page.id}`" class="group min-w-0 flex-1">
                            <span class="flex items-center gap-2 text-[14.5px] font-medium group-hover:text-brand-text">
                                <House v-if="page.is_home" class="size-4 text-brand-text" aria-hidden="true" />{{ pick(page.title) }}
                            </span>
                            <span class="block truncate text-[12.5px] text-muted">
                                <span class="font-mono" dir="ltr">/{{ page.is_home ? '' : page.slug }}</span> · {{ t('edu_website.pages.blocks', { count: formatNumber(page.blocks?.length ?? 0) }) }}
                            </span>
                        </RouterLink>
                        <AppBadge v-if="page.is_home" tone="brand">{{ t('edu_website.pages.home') }}</AppBadge>
                        <AppSwitch v-if="canManage" :model-value="page.in_menu" :busy="busy === page.id" :label="t('edu_website.pages.in_menu')" show-label @update:model-value="toggleMenu(page)" />
                        <AppBadge v-else-if="!page.in_menu" tone="outline">{{ t('edu_website.pages.hidden') }}</AppBadge>
                        <div class="flex items-center gap-1">
                            <AppButton size="sm" :icon="PenLine" :to="`/website/pages/${page.id}`">{{ canManage ? t('edu_website.pages.edit') : t('edu_website.pages.open') }}</AppButton>
                            <AppButton v-if="canManage && !page.is_home" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('edu_website.common.remove')" :loading="busy === page.id" @click="remove(page)" />
                        </div>
                    </li>
                </ol>
            </section>
        </template>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('edu_website.pages.add') : t('edu_website.pages.rename')" @close="editing = null">
            <form id="website-page" class="grid gap-4" novalidate @submit.prevent="save">
                <TranslatedFields :model-value="form.title" :label="t('edu_website.pages.name')" required :maxlength="80" :errors="errors" error-prefix="title" @update:model-value="titleChanged" />
                <AppField v-if="editing === 'new' || !editing?.is_home" v-slot="{ id }" :label="t('edu_website.pages.address')" :hint="t('edu_website.pages.address_hint')" :error="errors.slug?.[0] ?? null">
                    <input :id="id" v-model="form.slug" class="field-input font-mono" dir="ltr" maxlength="60" @input="slugTouched = true" />
                </AppField>
                <AppSwitch v-model="form.in_menu" :label="t('edu_website.pages.in_menu')" show-label />
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('edu_website.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="website-page" :loading="saving" :disabled="!form.title.en?.trim() || (editing === 'new' && !form.slug)">{{ t('edu_website.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
