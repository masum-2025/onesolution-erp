<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, ClipboardList, PenLine, SearchX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { fieldText } from '../lib';
import BomEditor from '../components/BomEditor.vue';
import OrderDialog from '../components/OrderDialog.vue';
import StyleDialog from '../components/StyleDialog.vue';

/** One style: buyer, sizes, colours, own fields, its bill of materials (edited here), and its orders. */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const route = useRoute();
const view = useResource(() => api.style(route.params.id));
const style = computed(() => view.data.value?.data ?? null);
const buyer = computed(() => view.data.value?.meta?.buyer ?? null);
const fields = computed(() => setup.fields('style').filter((field) => style.value?.extra?.[field.key] !== undefined));
const money = (minor) => formatMoney({ amount: minor, currency: setup.rules.value.order_currency });
const editing = ref(false);
const ordering = ref(false);
function bomSaved(data) {
    if (data === null) view.reload();
    else view.data.value = { ...view.data.value, data: { ...view.data.value.data, ...data } };
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="{ name: 'fac-styles' }" :icon="ArrowLeft" class="mb-3">{{ t('factory_erp.styles.back') }}</AppButton>
        <SkeletonRows v-if="view.loading.value && !style" :rows="6" />
        <section v-else-if="view.error.value?.status === 404" class="card"><EmptyState :icon="SearchX" :title="t('factory_erp.styles.not_found')" /></section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else-if="style">
            <PageHeader :title="`${style.code} · ${style.name}`" :description="buyer?.name ?? ''">
                <template v-if="setup.can('merchandise')" #actions>
                    <AppButton :icon="PenLine" @click="editing = true">{{ t('factory_erp.styles.edit') }}</AppButton>
                    <AppButton variant="primary" :icon="ClipboardList" @click="ordering = true">{{ t('factory_erp.orders.new') }}</AppButton>
                </template>
            </PageHeader>

            <div class="card mb-5 grid grid-cols-1 gap-x-6 gap-y-3 p-5 text-[13.5px] sm:grid-cols-3">
                <div><p class="text-muted">{{ t('factory_erp.fields_.sizes') }}</p><p class="font-medium" dir="ltr">{{ style.sizes.join(' · ') }}</p></div>
                <div><p class="text-muted">{{ t('factory_erp.fields_.colors') }}</p><p class="flex flex-wrap gap-1"><AppBadge v-for="color in style.colors" :key="color" tone="outline">{{ color }}</AppBadge></p></div>
                <div><p class="text-muted">{{ t('factory_erp.styles.orders') }}</p><p class="tabular font-medium">{{ formatNumber(style.orders) }}</p></div>
                <div v-if="style.product_type"><p class="text-muted">{{ t('factory_erp.fields_.product_type') }}</p><p class="font-medium">{{ style.product_type }}</p></div>
                <div v-for="field in fields" :key="field.key"><p class="text-muted">{{ field.label }}</p><p class="font-medium">{{ fieldText(field, style.extra[field.key], money) }}</p></div>
                <div v-if="style.note" class="sm:col-span-3"><p class="text-muted">{{ t('factory_erp.fields_.note') }}</p><p class="whitespace-pre-line">{{ style.note }}</p></div>
            </div>

            <BomEditor :style-record="style" :editable="setup.can('merchandise')" @saved="bomSaved" />

            <StyleDialog :open="editing" :style="style" @close="editing = false" @saved="editing = false; view.reload()" @conflict="editing = false; view.reload()" />
            <OrderDialog :open="ordering" :style-id="style.id" @close="ordering = false" />
        </template>
    </div>
</template>
