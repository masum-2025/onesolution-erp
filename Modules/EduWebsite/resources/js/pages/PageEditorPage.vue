<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowDown, ArrowLeft, ArrowUp, Eye, FileQuestion, LayoutTemplate, PenLine, Plus, Save, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { confirmAction } from '@/lib/dialogs';
import { t } from '@/lib/i18n';
import { toast } from '@/lib/toast';
import { useResource } from '@/lib/useResource';
import BlockForm from '../components/BlockForm.vue';
import BlockIcon from '../components/BlockIcon.vue';
import { BLOCKS, BLOCK_TYPES, blockBody, blockForm, blockSummary } from '../lib';
import { useSite } from '../useSite';

/**
 * One page and its blocks, top to bottom as the public sees them. A block
 * is added by its kind, edited in a side panel (fields per kind, each text
 * per language, pictures from the website's files), moved up or down and
 * removed after a confirmation. Changes wait for the next publish; the
 * preview shows them at once.
 */
const route = useRoute();
const { web, resource, meta, canManage, mainLocale, pick } = useSite();
const list = useResource(() => web.pages());
const page = computed(() => (list.data.value?.data ?? []).find((row) => row.id === route.params.id) ?? null);
const blocks = computed(() => page.value?.blocks ?? []);

// Pictures and files by id, for thumbnails in the forms.
const media = ref({});
useResource(async () => {
    const rows = (await web.media()).data;
    media.value = Object.fromEntries(rows.map((row) => [row.id, row]));
});
const remember = (rows) => rows.forEach((row) => (media.value[row.id] = row));

const busy = ref(null);

async function move(index, step) {
    const ids = blocks.value.map((block) => block.id);
    const [id] = ids.splice(index, 1);
    ids.splice(index + step, 0, id);
    busy.value = id;
    try {
        await web.orderBlocks(page.value.id, ids);
        await list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

async function remove(block) {
    const confirmed = await confirmAction({
        title: t('edu_website.editor.remove_title'),
        message: t('edu_website.editor.remove_text', { kind: t(`edu_website.blocks.${block.type}`) }),
        confirmLabel: t('edu_website.common.remove'),
        danger: true,
    });
    if (!confirmed) return;
    busy.value = block.id;
    try {
        await web.removeBlock(page.value.id, block.id, block.version);
        toast.success(t('edu_website.editor.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.code === 'version_conflict') list.reload();
    } finally {
        busy.value = null;
    }
}

// Choosing the kind of a new block, then its fields in the side panel.
const choosing = ref(false);
const editing = ref(null); // { type, block | null }
const form = ref({});
const errors = ref({});
const saving = ref(false);

function open(type, block = null) {
    choosing.value = false;
    form.value = blockForm(type, block?.data ?? {});
    errors.value = {};
    editing.value = { type, block };
}

async function save() {
    const { type, block } = editing.value;
    saving.value = true;
    errors.value = {};
    try {
        const data = blockBody(type, form.value);
        if (block) await web.updateBlock(page.value.id, block.id, data, block.version);
        else await web.addBlock(page.value.id, type, data);
        toast.success(t('edu_website.editor.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(error.message);
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        toast.error(Object.keys(errors.value).length ? t('edu_website.common.check_fields') : error.message);
    } finally {
        saving.value = false;
    }
}

async function preview() {
    const tab = window.open('', '_blank');
    try {
        const { url } = (await web.preview(page.value.is_home ? null : page.value.slug)).data;
        if (tab) {
            tab.opener = null;
            tab.location.href = url;
        } else {
            window.location.href = url;
        }
    } catch (error) {
        tab?.close();
        toast.error(error.message);
    }
}
</script>

<template>
    <div class="space-y-6">
        <RouterLink to="/website/pages" class="inline-flex items-center gap-1.5 text-[13px] text-muted hover:text-fg">
            <ArrowLeft class="size-4" aria-hidden="true" />{{ t('edu_website.pages.title') }}
        </RouterLink>

        <ErrorState v-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <SkeletonRows v-else-if="!list.data.value" :rows="5" />
        <section v-else-if="!page" class="card">
            <EmptyState :icon="FileQuestion" :title="t('edu_website.editor.missing')" :text="t('edu_website.editor.missing_text')">
                <AppButton to="/website/pages">{{ t('edu_website.pages.title') }}</AppButton>
            </EmptyState>
        </section>

        <template v-else>
            <PageHeader :title="pick(page.title)" :description="t('edu_website.editor.text')">
                <template #actions>
                    <AppButton v-if="canManage" :icon="Eye" @click="preview">{{ t('edu_website.overview.preview') }}</AppButton>
                    <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="choosing = true">{{ t('edu_website.editor.add') }}</AppButton>
                </template>
            </PageHeader>

            <p v-if="!canManage" class="rounded-lg bg-subtle px-4 py-3 text-[13px] text-fg-2">{{ t('edu_website.common.view_only') }}</p>

            <section v-if="!blocks.length" class="card">
                <EmptyState :icon="LayoutTemplate" :title="t('edu_website.editor.empty')" :text="t('edu_website.editor.empty_text')">
                    <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="choosing = true">{{ t('edu_website.editor.add') }}</AppButton>
                </EmptyState>
            </section>

            <ol v-else class="space-y-3" :aria-label="t('edu_website.editor.blocks')">
                <li v-for="(block, index) in blocks" :key="block.id" class="card flex items-center gap-3 p-3 sm:p-4">
                    <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-text">
                        <BlockIcon :name="BLOCKS[block.type]?.icon ?? 'text'" class="size-5" />
                    </span>
                    <button type="button" class="min-w-0 flex-1 text-start" :disabled="!canManage" @click="open(block.type, block)">
                        <span class="block text-[14px] font-semibold">{{ t(`edu_website.blocks.${block.type}`) }}</span>
                        <span class="block truncate text-[12.5px] text-muted">{{ blockSummary(block, pick) || t(`edu_website.blocks.${block.type}_text`) }}</span>
                    </button>
                    <div v-if="canManage" class="flex shrink-0 items-center">
                        <AppButton size="icon-sm" variant="ghost" :icon="ArrowUp" :aria-label="t('edu_website.common.move_up')" :disabled="index === 0 || busy !== null" @click="move(index, -1)" />
                        <AppButton size="icon-sm" variant="ghost" :icon="ArrowDown" :aria-label="t('edu_website.common.move_down')" :disabled="index === blocks.length - 1 || busy !== null" @click="move(index, 1)" />
                        <AppButton size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('edu_website.editor.edit')" @click="open(block.type, block)" />
                        <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('edu_website.common.remove')" :loading="busy === block.id" @click="remove(block)" />
                    </div>
                </li>
            </ol>
        </template>

        <AppDialog :open="choosing" size="lg" :title="t('edu_website.editor.choose')" :description="t('edu_website.editor.choose_text')" @close="choosing = false">
            <ul class="grid gap-3 sm:grid-cols-2">
                <li v-for="type in BLOCK_TYPES" :key="type">
                    <button type="button" class="flex w-full items-start gap-3 rounded-lg border border-line p-3 text-start transition hover:border-brand/50 hover:bg-brand-soft/40 focus-visible:ring-2 focus-visible:ring-brand" @click="open(type)">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand-soft text-brand-text"><BlockIcon :name="BLOCKS[type].icon" class="size-5" /></span>
                        <span class="min-w-0">
                            <span class="block text-[14px] font-semibold">{{ t(`edu_website.blocks.${type}`) }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t(`edu_website.blocks.${type}_text`) }}</span>
                        </span>
                    </button>
                </li>
            </ul>
        </AppDialog>

        <AppDrawer :open="editing !== null" :title="editing ? t(`edu_website.blocks.${editing.type}`) : ''" @close="editing = null">
            <form v-if="editing" id="website-block" class="p-5" novalidate @submit.prevent="save">
                <p class="mb-4 text-[13px] text-muted">{{ t(`edu_website.blocks.${editing.type}_text`) }}</p>
                <BlockForm
                    :fields="BLOCKS[editing.type].fields"
                    :form="form"
                    :errors="errors"
                    :media="media"
                    :web="web"
                    :max-mb="meta.max_media_mb?.value ?? 5"
                    :can-upload="canManage"
                    :main-locale="mainLocale"
                    @media="remember"
                />
            </form>
            <template #footer>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-3">
                    <AppButton variant="ghost" @click="editing = null">{{ t('edu_website.common.cancel') }}</AppButton>
                    <AppButton variant="primary" type="submit" form="website-block" :icon="Save" :loading="saving">{{ t('edu_website.common.save') }}</AppButton>
                </div>
            </template>
        </AppDrawer>
    </div>
</template>
