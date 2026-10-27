<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { RouterLink, useRoute, useRouter } from 'vue-router';
import { Briefcase, Globe, Lock, PenLine, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';
import OpeningEditor from '../components/OpeningEditor.vue';
import { recruitmentApi } from '../api';
import { nameIn, openingTone, salaryText } from '../lib';

/**
 * The unit's job openings: open ones first (with how many people are at
 * each step), drafts, closed and filled; a new one (also from the header
 * "New" menu, ?new=1), changing, opening and closing them. A card opens
 * the opening's board. Without a careers address public jobs are listed
 * nowhere yet: a note leads to the settings.
 */
const route = useRoute();
const router = useRouter();
const org = currentOrganization();
const calls = recruitmentApi(org.id);

const status = ref('open');
const list = useResource(() => calls.openings({ status: status.value === 'all' ? undefined : status.value }));
const items = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => !!list.data.value?.meta?.can_manage);
const noAddress = computed(() => list.data.value && !list.data.value.meta?.careers_slug);
watch(status, () => list.reload());

const options = useResource(() => calls.options());
const stages = computed(() => options.data.value?.data?.stages ?? []);

const editing = ref(null); // 'new' | an opening
onMounted(() => {
    if (route.query.new) {
        editing.value = 'new';
        router.replace({ query: { ...route.query, new: undefined } });
    }
});
function saved() {
    editing.value = null;
    list.reload();
}

const busy = ref(null);
async function step(item, name) {
    const confirmed = await confirmAction({
        title: t(`recruitment.openings.${name}_title`, { title: item.title }),
        message: t(`recruitment.openings.${name}_text`),
        confirmLabel: t(`recruitment.openings.${name}`),
        danger: name === 'close',
    });
    if (!confirmed) return;
    busy.value = item.id;
    try {
        await calls.stepOpening(item.id, name, { base_version: item.version });
        toast.success(t(`recruitment.openings.${name}_done`));
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') list.reload();
        toast.error(error.errors?.closes_on?.[0] ?? error.message);
    } finally {
        busy.value = null;
    }
}

const statuses = computed(() => ['open', 'draft', 'closed', 'filled', 'all'].map((value) => ({ value, label: value === 'all' ? t('recruitment.common.all') : t(`recruitment.opening_status.${value}`) })));
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('recruitment.openings.title')" :description="t('recruitment.openings.text')">
            <template #actions>
                <AppSegmented v-model="status" size="sm" :label="t('recruitment.openings.title')" :options="statuses" />
                <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="editing = 'new'">{{ t('recruitment.openings.new') }}</AppButton>
            </template>
        </PageHeader>

        <p v-if="noAddress && canManage" class="rounded-xl border border-warn/40 bg-warn/10 px-4 py-3 text-[13.5px]">
            {{ t('recruitment.openings.no_address') }}
            <RouterLink to="/recruitment/settings" class="font-medium text-brand underline">{{ t('recruitment.openings.set_address') }}</RouterLink>
        </p>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!items.length" class="card">
            <EmptyState :icon="Briefcase" :title="t(`recruitment.openings.empty_${status}`)" :text="t('recruitment.openings.empty_text')" compact>
                <AppButton v-if="canManage" variant="primary" :icon="Plus" @click="editing = 'new'">{{ t('recruitment.openings.new') }}</AppButton>
            </EmptyState>
        </section>
        <ul v-else class="grid gap-4 md:grid-cols-2">
            <li v-for="item in items" :key="item.id" class="card flex flex-col p-4">
                <div class="flex items-start justify-between gap-3">
                    <RouterLink :to="`/recruitment/openings/${item.id}`" class="min-w-0">
                        <h2 class="truncate text-[16px] font-semibold hover:text-brand">{{ item.title }}</h2>
                        <p class="text-[12.5px] text-muted">
                            {{ item.unit_name }}<template v-if="item.position"> · {{ nameIn(item.position.title, i18n.locale) }}</template>
                        </p>
                    </RouterLink>
                    <AppBadge :tone="openingTone(item.status)">{{ t(`recruitment.opening_status.${item.status}`) }}</AppBadge>
                </div>
                <p class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-[12.5px] text-muted">
                    <span class="inline-flex items-center gap-1"><component :is="item.is_public ? Globe : Lock" class="size-3.5" aria-hidden="true" />{{ item.is_public ? t('recruitment.openings.public') : t('recruitment.openings.internal') }}</span>
                    <span>{{ t('recruitment.openings.places', { hired: formatNumber(item.hired_count), needed: formatNumber(item.vacancies) }) }}</span>
                    <span v-if="salaryText(item)">{{ salaryText(item) }}</span>
                    <span v-if="item.closes_on">{{ t('recruitment.openings.closes', { date: formatDate(`${item.closes_on}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' }) }) }}</span>
                </p>
                <RouterLink :to="`/recruitment/openings/${item.id}`" class="mt-3 flex flex-wrap gap-1.5" :aria-label="t('recruitment.openings.board', { title: item.title })">
                    <span v-for="stage in stages" :key="stage.key" class="inline-flex items-center gap-1.5 rounded-lg bg-subtle px-2.5 py-1 text-[12.5px]">
                        {{ nameIn(stage.name, i18n.locale) }}
                        <b class="tabular">{{ formatNumber(item.stage_counts[stage.key] ?? 0) }}</b>
                    </span>
                </RouterLink>
                <div v-if="canManage" class="mt-3 flex flex-wrap gap-2 border-t border-line pt-3">
                    <AppButton size="sm" variant="ghost" :icon="PenLine" @click="editing = item">{{ t('recruitment.openings.edit') }}</AppButton>
                    <AppButton v-if="['draft', 'closed'].includes(item.status)" size="sm" variant="primary" :loading="busy === item.id" @click="step(item, 'open')">{{ t('recruitment.openings.open') }}</AppButton>
                    <AppButton v-if="item.status === 'open'" size="sm" variant="ghost" :loading="busy === item.id" @click="step(item, 'close')">{{ t('recruitment.openings.close') }}</AppButton>
                </div>
            </li>
        </ul>

        <OpeningEditor
            :open="editing !== null"
            :opening="editing === 'new' ? null : editing"
            :options="options.data.value?.data ?? {}"
            :calls="calls"
            @close="editing = null"
            @saved="saved"
        />
    </div>
</template>
