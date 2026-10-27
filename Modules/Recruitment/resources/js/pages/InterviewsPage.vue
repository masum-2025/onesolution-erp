<script setup>
import { computed, ref } from 'vue';
import { CalendarClock, Star } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ApplicationDrawer from '../components/ApplicationDrawer.vue';
import FeedbackDialog from '../components/FeedbackDialog.vue';
import { recruitmentApi } from '../api';

/**
 * The interviews this person is asked to: those that took place without
 * their feedback first, then the ones to come, then the rest. Feedback is
 * given here; the candidate's application (CV, history) opens beside it.
 */
const org = currentOrganization();
const calls = recruitmentApi(org.id);
const list = useResource(() => calls.myInterviews());
const now = ref(Date.now());

const all = computed(() => list.data.value?.data ?? []);
const owed = computed(() => all.value.filter((item) => item.status === 'scheduled' && new Date(item.starts_at).getTime() <= now.value && !item.feedback_given));
const coming = computed(() => all.value.filter((item) => item.status === 'scheduled' && new Date(item.starts_at).getTime() > now.value));
const past = computed(() => all.value.filter((item) => !owed.value.includes(item) && !coming.value.includes(item)).reverse());
const groups = computed(() => [
    { key: 'owed', items: owed.value },
    { key: 'coming', items: coming.value },
    { key: 'past', items: past.value },
].filter((group) => group.items.length));

const giving = ref(null);
const viewing = ref(null);
function saved() {
    giving.value = null;
    list.reload();
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('recruitment.mine.title')" :description="t('recruitment.mine.text')" />

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!all.length" class="card">
            <EmptyState :icon="CalendarClock" :title="t('recruitment.mine.empty')" :text="t('recruitment.mine.empty_text')" compact />
        </section>
        <template v-else>
        <section v-for="group in groups" :key="group.key" class="card" :aria-labelledby="`interviews-${group.key}`">
            <h2 :id="`interviews-${group.key}`" class="border-b border-line px-5 py-3 text-[15px] font-semibold">{{ t(`recruitment.mine.${group.key}`) }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="item in group.items" :key="item.id" class="grid gap-2 px-5 py-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-center">
                    <div class="min-w-0">
                        <button type="button" class="text-start text-[14px] font-medium hover:text-brand" @click="viewing = item.application_id">{{ item.name }}</button>
                        <p class="text-[12.5px] text-muted">{{ item.opening_title }} · {{ item.local }} · {{ t(`recruitment.modes.${item.mode}`) }}<template v-if="item.place"> · {{ item.place }}</template></p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <AppBadge v-if="item.feedback_given" tone="ok">{{ t('recruitment.mine.given') }}</AppBadge>
                        <AppBadge v-else-if="item.status === 'cancelled'" tone="neutral">{{ t('recruitment.interview_status.cancelled') }}</AppBadge>
                        <AppButton v-if="item.status !== 'cancelled' && item.application_active" size="sm" :variant="group.key === 'owed' ? 'primary' : 'secondary'" :icon="Star" @click="giving = item">
                            {{ item.feedback_given ? t('recruitment.drawer.change_feedback') : t('recruitment.drawer.give_feedback') }}
                        </AppButton>
                    </div>
                </li>
            </ul>
        </section>
        </template>

        <FeedbackDialog :open="giving !== null" :interview="giving" :name="giving?.name ?? ''" :calls="calls" @close="giving = null" @saved="saved" />
        <ApplicationDrawer :open="viewing !== null" :application-id="viewing" :calls="calls" @close="viewing = null" @changed="list.reload()" />
    </div>
</template>
