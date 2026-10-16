<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Search, Shirt } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import StyleDialog from '../components/StyleDialog.vue';

/** The company's styles by number, with their buyer, sizes and colours; each opens to its bill of materials. */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const route = useRoute();
const router = useRouter();
const search = ref('');
const list = useResource(() => api.styles({ q: search.value.trim() }));
let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => list.reload(), 300);
});
const rows = computed(() => list.data.value?.data ?? []);
const buyers = computed(() => list.data.value?.meta?.buyers ?? {});

const making = ref(false);
watch([() => route.query.new, () => setup.data.value], ([value, data]) => {
    if (value && data) {
        making.value = true;
        router.replace({ query: { ...route.query, new: undefined } });
    }
}, { immediate: true });
function saved(style) {
    making.value = false;
    router.push({ name: 'fac-style', params: { id: style.id } });
}
</script>

<template>
    <div>
        <PageHeader :title="t('factory_erp.styles.title')" :description="t('factory_erp.styles.text')">
            <template v-if="setup.can('merchandise')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="making = true">{{ t('factory_erp.styles.new') }}</AppButton>
            </template>
        </PageHeader>
        <AppField v-slot="{ id }" :label="t('factory_erp.styles.search')" sr-only-label class="mb-4 sm:w-96">
            <span class="relative block">
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('factory_erp.styles.search')" autocomplete="off" />
            </span>
        </AppField>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <div v-else-if="!rows.length" class="card">
            <EmptyState :icon="Shirt" :title="t('factory_erp.styles.empty_title')" :text="t('factory_erp.styles.empty_text')">
                <AppButton v-if="setup.can('merchandise')" variant="primary" :icon="Plus" @click="making = true">{{ t('factory_erp.styles.new') }}</AppButton>
            </EmptyState>
        </div>
        <section v-else class="card">
            <ul class="divide-y divide-line">
                <li v-for="style in rows" :key="style.id">
                    <RouterLink :to="{ name: 'fac-style', params: { id: style.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                        <span class="min-w-0 flex-1 basis-56">
                            <span class="block text-[14px] font-semibold text-fg"><span class="font-mono" dir="ltr">{{ style.code }}</span> · {{ style.name }}</span>
                            <span class="block text-[12.5px] text-muted">{{ buyers[style.buyer_id]?.name ?? '—' }}<template v-if="style.product_type"> · {{ style.product_type }}</template></span>
                        </span>
                        <span class="text-[12.5px] text-muted" dir="ltr">{{ style.sizes.join(' · ') }}</span>
                        <span class="flex flex-wrap gap-1"><AppBadge v-for="color in style.colors" :key="color" tone="outline">{{ color }}</AppBadge></span>
                        <AppBadge v-if="!style.is_active" tone="neutral">{{ t('factory_erp.off') }}</AppBadge>
                    </RouterLink>
                </li>
            </ul>
        </section>

        <StyleDialog :open="making" @close="making = false" @saved="saved" @conflict="making = false" />
    </div>
</template>
