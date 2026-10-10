<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ListChecks, Lock, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { promotionTone } from '../lib';
import NewPromotionDialog from '../components/NewPromotionDialog.vue';

/**
 * Promotion lists of this unit and below, by status: for people who make
 * them (education.promote) and people who approve them
 * (education.approve_promotion), who open "Waiting for approval".
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();
const STATUSES = ['draft', 'pending_approval', 'applied', 'undone', 'cancelled'];

const allowed = computed(() => setup.can('promote') || setup.can('approve_promotion'));
const status = ref(STATUSES.includes(route.query.status) ? route.query.status : 'all');
const list = useResource(() => education.promotions(status.value === 'all' ? {} : { status: status.value }), { immediate: false });
const batches = computed(() => list.data.value?.data ?? []);
const tabs = computed(() => [{ key: 'all', label: t('education.admissions.all') }, ...STATUSES.map((key) => ({ key, label: t(`education.promotion_statuses.${key}`) }))]);

// Read once the reader is known to be allowed (others see why not).
watch(allowed, (yes) => yes && list.reload(), { immediate: true });
watch(status, (value) => {
    router.replace({ query: value === 'all' ? {} : { status: value } });
    list.reload();
});

const making = ref(false);
function made(batch) {
    making.value = false;
    router.push({ name: 'education-promotion', params: { id: batch.id } });
}

const totals = (batch) => ({ promote: 0, repeat: 0, leave: 0, graduate: 0, ...batch.decisions });
const maker = (batch) => batch.people?.[batch.created_by] ?? '';
</script>

<template>
    <div>
        <PageHeader :title="t('education.promotions.title')" :description="t('education.promotions.text')">
            <template v-if="setup.can('promote')" #actions>
                <AppButton variant="primary" :icon="Plus" @click="making = true">{{ t('education.promotions.new') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="!setup.data.value" :rows="4" />
        <section v-else-if="!allowed" class="card">
            <EmptyState :icon="Lock" :title="t('education.promotions.no_access')" />
        </section>
        <template v-else>
            <div class="mb-4 overflow-x-auto"><AppTabs v-model="status" :tabs="tabs" :label="t('education.promotions.title')" /></div>
            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!batches.length" :icon="ListChecks" :title="t('education.promotions.empty_title')" :text="t('education.promotions.empty_text')" compact>
                    <AppButton v-if="setup.can('promote') && status === 'all'" variant="primary" :icon="Plus" @click="making = true">{{ t('education.promotions.new') }}</AppButton>
                </EmptyState>
                <ul v-else class="divide-y divide-line">
                    <li v-for="batch in batches" :key="batch.id">
                        <RouterLink :to="{ name: 'education-promotion', params: { id: batch.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1.5 px-5 py-3.5 transition hover:bg-subtle/60">
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[14px] font-medium text-fg">{{ setup.levelText(batch.level_id) }}</span>
                                <span class="block truncate text-[12.5px] text-muted">
                                    <span class="font-mono" dir="ltr">{{ batch.number }}</span>
                                    · {{ t('education.promotions.from_to', { from: setup.sessionText(batch.from_session_id), to: setup.sessionText(batch.to_session_id) }) }}
                                    <template v-if="maker(batch)"> · {{ t('education.promotions.made_by', { name: maker(batch) }) }}</template>
                                </span>
                                <span class="mt-0.5 block text-[12px] text-faint">{{ t('education.promotions.totals', totals(batch)) }}</span>
                            </span>
                            <AppBadge :tone="promotionTone(batch.status)" dot>{{ t(`education.promotion_statuses.${batch.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </section>
        </template>

        <NewPromotionDialog :open="making" :education="education" @close="making = false" @made="made" />
    </div>
</template>
