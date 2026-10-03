<script setup>
import { computed, reactive, ref } from 'vue';
import { Archive, ArchiveRestore, ArrowLeft, FolderTree, PenLine, Plus, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * The company's chart of accounts as an indented list in code order.
 * People who manage the books add, rename, regroup and archive accounts;
 * the server keeps types fixed once accounts carry entries.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const types = ['asset', 'liability', 'equity', 'income', 'expense'];

const showArchived = ref(false);
const search = ref('');
const list = useResource(() => books.accounts(showArchived.value));
const all = computed(() => list.data.value?.data ?? []);
const rows = computed(() => {
    const flat = accountTree(all.value).flat;
    const term = search.value.trim().toLowerCase();
    return term ? flat.filter((account) => account.code.toLowerCase().includes(term) || account.name.toLowerCase().includes(term)) : flat;
});
const groups = computed(() => accountTree(all.value).flat.filter((account) => account.is_group && account.status === 'active'));

const editing = ref(null); // null | 'new' | account
const saving = ref(false);
const errors = ref({});
const form = reactive({ code: '', name_en: '', name_bn: '', parent_id: '', type: '', is_group: false });

function edit(account = null) {
    Object.assign(form, {
        code: account?.code ?? '',
        name_en: account?.names.en ?? '',
        name_bn: account?.names.bn ?? '',
        parent_id: account?.parent_id ?? '',
        type: account?.parent_id ? '' : (account?.type ?? ''),
        is_group: account?.is_group ?? false,
    });
    errors.value = {};
    editing.value = account ?? 'new';
}

async function save() {
    const body = {
        code: form.code.trim(),
        name: { en: form.name_en.trim(), ...(form.name_bn.trim() ? { bn: form.name_bn.trim() } : {}) },
        parent_id: form.parent_id || null,
        is_group: form.is_group,
        ...(form.parent_id ? {} : { type: form.type || null }),
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await books.createAccount(body);
        else await books.updateAccount(editing.value.id, changed(editing.value, body));
        toast.success(t('accounting.accounts.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('accounting.common.conflict'));
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

/** Only what changed (the server refuses type changes on used accounts), with the version seen. */
function changed(account, body) {
    const changes = { base_version: account.version };
    if (body.code !== account.code) changes.code = body.code;
    if (JSON.stringify(body.name) !== JSON.stringify(account.names)) changes.name = body.name;
    if ((body.parent_id ?? null) !== (account.parent_id ?? null)) changes.parent_id = body.parent_id;
    if (body.is_group !== account.is_group) changes.is_group = body.is_group;
    if (body.type && body.type !== account.type) changes.type = body.type;
    return changes;
}

async function setStatus(account, status) {
    if (status === 'archived') {
        const confirmed = await confirmAction({
            title: t('accounting.accounts.archive_title', { name: account.name }),
            message: t('accounting.accounts.archive_text'),
            confirmLabel: t('accounting.accounts.archive'),
        });
        if (!confirmed) return;
    }
    try {
        await books.updateAccount(account.id, { base_version: account.version, status });
        toast.success(t(status === 'archived' ? 'accounting.accounts.archived_done' : 'accounting.accounts.restored'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
        list.reload();
    }
}

function toggleArchived(value) {
    showArchived.value = value;
    list.reload();
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.accounts.title')" :description="t('accounting.accounts.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
                <AppButton v-if="can('accounting.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('accounting.accounts.add') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <AppField v-slot="{ id }" :label="t('accounting.accounts.search')" sr-only-label class="flex-1">
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                        <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('accounting.accounts.search')" autocomplete="off" />
                    </div>
                </AppField>
                <AppSwitch :model-value="showArchived" :label="t('accounting.accounts.show_archived')" show-label @update:model-value="toggleArchived" />
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="10" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="FolderTree" :title="t('accounting.accounts.empty_title')" :text="t('accounting.accounts.empty_text')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="account in rows" :key="account.id" class="flex items-center gap-3 py-2.5 pe-4 sm:pe-5" :style="{ paddingInlineStart: `${1.25 + account.depth * 1.25}rem` }">
                        <span class="w-14 shrink-0 font-mono text-[12.5px] text-muted" dir="ltr">{{ account.code }}</span>
                        <span class="min-w-0 flex-1 truncate text-[13.5px]" :class="account.is_group ? 'font-semibold text-fg' : 'text-fg'">{{ account.name }}</span>
                        <span class="hidden text-[12px] text-muted sm:inline">{{ t(`accounting.types.${account.type}`) }}</span>
                        <AppBadge v-if="account.is_group" tone="outline">{{ t('accounting.accounts.group') }}</AppBadge>
                        <AppBadge v-if="account.status === 'archived'" tone="neutral">{{ t('accounting.accounts.archived') }}</AppBadge>
                        <template v-if="can('accounting.manage')">
                            <AppButton size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('accounting.accounts.edit')" @click="edit(account)" />
                            <AppButton
                                size="icon-sm"
                                variant="ghost"
                                :icon="account.status === 'archived' ? ArchiveRestore : Archive"
                                :aria-label="account.status === 'archived' ? t('accounting.accounts.restore') : t('accounting.accounts.archive')"
                                @click="setStatus(account, account.status === 'archived' ? 'active' : 'archived')"
                            />
                        </template>
                    </li>
                </ul>
            </section>
        </BooksGate>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('accounting.accounts.add') : t('accounting.accounts.edit')" @close="editing = null">
            <form id="accounting-account" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('accounting.accounts.code')" :error="fieldError('code')">
                    <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="20" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.parent')" :error="fieldError('parent_id')">
                    <select :id="id" v-model="form.parent_id" class="field-input">
                        <option value="">{{ t('accounting.accounts.no_parent') }}</option>
                        <option v-for="group in groups" :key="group.id" :value="group.id" :disabled="editing !== 'new' && group.id === editing?.id">
                            {{ '  '.repeat(group.depth) }}{{ group.code }} · {{ group.name }}
                        </option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.name_en')" :error="fieldError('name.en') ?? fieldError('name')">
                    <input :id="id" v-model="form.name_en" class="field-input" lang="en" maxlength="120" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.name_bn')" :error="fieldError('name.bn')" optional>
                    <input :id="id" v-model="form.name_bn" class="field-input" lang="bn" maxlength="120" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('accounting.accounts.type')" :error="fieldError('type')">
                    <select :id="id" v-model="form.type" class="field-input" :disabled="!!form.parent_id">
                        <option value="">{{ form.parent_id ? t('accounting.accounts.type_from_group') : '—' }}</option>
                        <option v-for="type in types" :key="type" :value="type">{{ t(`accounting.types.${type}`) }}</option>
                    </select>
                </AppField>
                <div class="flex items-end sm:col-span-2">
                    <AppSwitch v-model="form.is_group" :label="t('accounting.accounts.is_group')" show-label />
                </div>
                <p v-if="fieldError('is_group')" class="text-[12.5px] text-bad sm:col-span-2">{{ fieldError('is_group') }}</p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('accounting.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="accounting-account" :loading="saving" :disabled="!form.code.trim() || !form.name_en.trim()">{{ t('accounting.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
