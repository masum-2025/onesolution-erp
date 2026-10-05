<script setup>
import { computed, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import { ClipboardList, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { inventoryApi } from '../api';
import { statusTone } from '../lib';

/** Stock counts, newest first; open one for a warehouse (optionally one category). */
const inventory = inventoryApi(currentOrganization().id);
const router = useRouter();
const list = useResource(() => inventory.counts());
const warehouses = useResource(() => inventory.list('warehouses'));
const categories = useResource(() => inventory.list('categories'));
const warehouseName = (id) => (warehouses.data.value?.data ?? []).find((row) => row.id === id)?.name ?? '';
const counts = computed(() => list.data.value?.data ?? []);

const opening = ref(false);
const form = reactive({ warehouse_id: '', category_id: '', counted_on: '' });
const error = ref(null);
const saving = ref(false);
function open() {
    Object.assign(form, { warehouse_id: (warehouses.data.value?.data ?? []).find((row) => row.is_active)?.id ?? '', category_id: '', counted_on: new Date().toISOString().slice(0, 10) });
    error.value = null;
    opening.value = true;
}
async function save() {
    saving.value = true;
    error.value = null;
    try {
        const { data } = await inventory.openCount({ warehouse_id: form.warehouse_id, category_id: form.category_id || null, counted_on: form.counted_on });
        toast.success(t('inventory.counts.opened'));
        router.push({ name: 'inventory-count', params: { id: data.id } });
    } catch (failure) {
        error.value = failure.message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('inventory.counts.title')" :description="t('inventory.counts.text')">
            <template #actions>
                <AppButton v-if="can('inventory.manage')" variant="primary" :icon="Plus" @click="open">{{ t('inventory.counts.open') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!counts.length" :icon="ClipboardList" :title="t('inventory.counts.empty')" :text="t('inventory.counts.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="count in counts" :key="count.id">
                    <RouterLink :to="{ name: 'inventory-count', params: { id: count.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 hover:bg-surface-2">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ warehouseName(count.warehouse_id) }} <span class="whitespace-nowrap font-mono text-[12px] text-muted" dir="ltr">{{ count.number }}</span></span>
                            <span class="block text-[12.5px] text-muted">{{ formatDate(`${count.counted_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }}</span>
                        </span>
                        <AppBadge :tone="statusTone(count.status)" dot>{{ t(`inventory.status.${count.status}`) }}</AppBadge>
                        <span class="tabular w-32 text-end text-[14px] font-semibold" :class="count.variance_value_minor < 0 ? 'text-bad' : ''">{{ count.status === 'posted' ? formatMoney({ amount: count.variance_value_minor, currency: count.currency }) : '—' }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <AppDialog :open="opening" :title="t('inventory.counts.open')" :description="t('inventory.counts.open_text')" :icon="ClipboardList" @close="opening = false">
            <form id="inventory-count-open" class="grid gap-4" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('inventory.common.warehouse')" :error="error">
                    <select :id="id" v-model="form.warehouse_id" class="field-input">
                        <option v-for="warehouse in (warehouses.data.value?.data ?? []).filter((row) => row.is_active)" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inventory.items.category')" optional>
                    <select :id="id" v-model="form.category_id" class="field-input">
                        <option value="">{{ t('inventory.items.all_categories') }}</option>
                        <option v-for="category in categories.data.value?.data ?? []" :key="category.id" :value="category.id">{{ category.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inventory.counts.day')">
                    <input :id="id" v-model="form.counted_on" type="date" class="field-input" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="opening = false">{{ t('inventory.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="inventory-count-open" :loading="saving" :disabled="!form.warehouse_id || !form.counted_on">{{ t('inventory.counts.open') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
