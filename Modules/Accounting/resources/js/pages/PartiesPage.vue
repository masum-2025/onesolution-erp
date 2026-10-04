<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { ChevronLeft, ChevronRight, Search, UserPlus, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';
import BooksGate from '../components/BooksGate.vue';
import PartyDialog from '../components/PartyDialog.vue';

/**
 * Customers (or vendors): search, inactive ones on request, add one. Opening
 * a party shows what it owes and its statement.
 */
const org = currentOrganization();
const books = accountingApi(org.id);
const route = useRoute();
const role = computed(() => route.meta.role ?? 'customers');
const writer = computed(() => can(role.value === 'customers' ? 'accounting.sell' : 'accounting.buy'));

const search = ref('');
const inactive = ref(false);
const page = ref(1);
const list = useResource(() => books.parties({ role: role.value, q: search.value.trim(), inactive: inactive.value ? 1 : undefined, page: page.value, per_page: 25 }));
const parties = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const dialog = reactive({ open: false, party: null });

let timer = null;
watch([search, inactive], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        list.reload();
    }, 300);
});
watch(role, () => {
    page.value = 1;
    list.reload();
});
onBeforeUnmount(() => clearTimeout(timer));

function go(to) {
    page.value = to;
    list.reload();
}
</script>

<template>
    <div>
        <PageHeader :title="t(`accounting.parties.${role}`)" :description="t(`accounting.parties.${role}_text`)">
            <template #actions>
                <AppButton v-if="writer" variant="primary" :icon="UserPlus" @click="Object.assign(dialog, { open: true, party: null })">
                    {{ t(role === 'customers' ? 'accounting.parties.add_customer' : 'accounting.parties.add_vendor') }}
                </AppButton>
            </template>
        </PageHeader>

        <BooksGate>
            <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center">
                <AppField v-slot="{ id }" :label="t('accounting.parties.search')" sr-only-label class="flex-1">
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                        <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('accounting.parties.search')" autocomplete="off" />
                    </div>
                </AppField>
                <AppSwitch v-model="inactive" :label="t('accounting.parties.show_inactive')" show-label />
            </div>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="8" avatar />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState
                    v-else-if="!parties.length"
                    :icon="Users"
                    :title="search ? t('accounting.parties.none_title') : t(`accounting.parties.empty_${role}`)"
                    :text="search ? t('accounting.parties.none_text') : t('accounting.parties.empty_text')"
                    compact
                />
                <ul v-else class="divide-y divide-line">
                    <li v-for="party in parties" :key="party.id">
                        <RouterLink :to="{ name: 'accounting-party', params: { id: party.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                            <span class="grid size-10 shrink-0 place-items-center rounded-full bg-brand-soft text-[13px] font-semibold text-brand-text" aria-hidden="true">
                                {{ Array.from(party.name)[0] }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ party.name }}</span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    <span v-if="party.code" class="font-mono" dir="ltr">{{ party.code }} · </span>{{ party.phone || party.email || '' }}
                                </span>
                            </span>
                            <AppBadge v-if="party.is_customer && party.is_vendor" tone="outline">{{ t('accounting.parties.is_customer') }} · {{ t('accounting.parties.is_vendor') }}</AppBadge>
                            <AppBadge v-if="!party.is_active" tone="neutral">{{ t('accounting.parties.inactive') }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('accounting.list.page', { page: formatNumber(meta.page), pages: formatNumber(meta.last_page) }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </BooksGate>

        <PartyDialog :open="dialog.open" :party="dialog.party" :role="role" @close="dialog.open = false" @saved="list.reload()" />
    </div>
</template>
