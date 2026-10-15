<script setup>
import { computed, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ArrowRight, ClipboardList, UserPlus } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits, registrationTone } from '../lib';

/**
 * One student's subject registrations, every session, newest first; each
 * opens to its subjects. Someone who registers students can start this
 * session's registration from here. The courses tab of the student's page
 * in Education.
 */
const props = defineProps({ studentId: { type: String, required: true } });
const api = registrationApi(currentOrganization().id);
const setup = useRegistrationSetup();
const router = useRouter();

const list = useResource(() => api.registrations({ student_id: props.studentId, per_page: 100 }), { immediate: false });
watch(() => props.studentId, () => list.reload(), { immediate: true });
const rows = computed(() => list.data.value?.data ?? []);
// This session (the one course registration works in) has no registration yet.
const canStart = computed(() => setup.can('register') && setup.sessionId.value && !rows.value.some((row) => row.session_id === setup.sessionId.value));

const opening = ref(false);
async function start() {
    opening.value = true;
    try {
        const { data } = await api.open({ student_id: props.studentId, session_id: setup.sessionId.value });
        router.push({ name: 'crs-registration', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        opening.value = false;
    }
}
</script>

<template>
    <div>
        <div v-if="canStart" class="mb-4 flex justify-end">
            <AppButton size="sm" variant="primary" :icon="UserPlus" :loading="opening" @click="start">{{ t('course_registration.student_tab.start', { session: setup.sessionName(setup.sessionId.value) }) }}</AppButton>
        </div>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!rows.length" :icon="ClipboardList" compact :title="t('course_registration.student_tab.empty_title')" :text="t('course_registration.student_tab.empty_text')" />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id">
                    <RouterLink :to="{ name: 'crs-registration', params: { id: row.id } }" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium text-fg">{{ setup.sessionName(row.session_id) || '—' }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ t('course_registration.credits', { count: Number(credits(row.credits_centi)) }) }}
                                <template v-if="row.submitted_at"> · {{ t('course_registration.registrations.handed_in', { date: formatDate(row.submitted_at) }) }}</template>
                            </span>
                        </span>
                        <AppBadge v-if="row.overload" tone="warn">{{ t('course_registration.registrations.overload') }}</AppBadge>
                        <AppBadge :tone="registrationTone(row.status)" dot>{{ t(`course_registration.statuses.${row.status}`) }}</AppBadge>
                        <ArrowRight class="size-4 text-faint rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>
    </div>
</template>
