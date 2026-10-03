<script setup>
import { computed, ref } from 'vue';
import { ArrowLeft, Cable } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import { accountTree, postableAccounts } from '../lib';
import BooksGate from '../components/BooksGate.vue';

/**
 * Where other modules' entries go: each posting key a module declares, and
 * the company's account for it (only accounts of the type the key needs).
 */
const org = currentOrganization();
const books = accountingApi(org.id);

const list = useResource(() => Promise.all([books.postingAccounts(), books.accounts()]));
const keys = computed(() => list.data.value?.[0].data ?? []);
const accounts = computed(() => {
    const allowed = new Set(postableAccounts(list.data.value?.[1].data ?? []).map((account) => account.id));
    return accountTree(list.data.value?.[1].data ?? []).flat.filter((account) => allowed.has(account.id));
});
const busy = ref(null);

async function choose(item, accountId) {
    if (!accountId || accountId === item.account_id) return;
    busy.value = item.key;
    try {
        await books.setPostingAccount(item.key, { account_id: accountId, ...(item.version ? { base_version: item.version } : {}) });
        toast.success(t('accounting.posting.saved'));
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('accounting.common.conflict') : error.message);
    } finally {
        busy.value = null;
        list.reload();
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('accounting.posting.title')" :description="t('accounting.posting.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'accounting' }" :icon="ArrowLeft">{{ t('accounting.journal.back') }}</AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!keys.length" :icon="Cable" :title="t('accounting.posting.empty')" compact />
                <ul v-else class="divide-y divide-line">
                    <li v-for="item in keys" :key="item.key" class="grid gap-2 px-5 py-3.5 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] sm:items-center">
                        <div class="min-w-0">
                            <div class="text-[14px] font-medium text-fg">{{ item.label }}</div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2 text-[12px] text-muted">
                                <span class="font-mono" dir="ltr">{{ item.key }}</span>
                                <AppBadge tone="outline">{{ t(`accounting.types.${item.type}`) }}</AppBadge>
                                <AppBadge v-if="!item.module_enabled" tone="neutral">{{ t('accounting.posting.module_off') }}</AppBadge>
                            </div>
                        </div>
                        <AppField v-slot="{ id }" :label="t('accounting.posting.account')" sr-only-label>
                            <select :id="id" class="field-input" :value="item.account_id ?? ''" :disabled="!can('accounting.manage') || busy === item.key" @change="choose(item, $event.target.value)">
                                <option value="">{{ t('accounting.posting.not_chosen') }}</option>
                                <option v-for="account in accounts.filter((a) => a.type === item.type)" :key="account.id" :value="account.id">{{ account.code }} · {{ account.name }}</option>
                            </select>
                        </AppField>
                    </li>
                </ul>
            </section>
        </BooksGate>
    </div>
</template>
