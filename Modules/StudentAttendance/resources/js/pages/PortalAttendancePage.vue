<script setup>
import { computed, ref } from 'vue';
import { useRoute } from 'vue-router';
import { UserX } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import EmptyState from '@/components/EmptyState.vue';
import { t } from '@/lib/i18n';
import { portalAttendanceApi } from '../api';
import StudentAttendanceTab from '../components/StudentAttendanceTab.vue';

/**
 * A student's attendance in the client's portal, a month at a time: their
 * own, or a child's when a parent opens it from the child's record. The
 * day's state only (no periods), with the month's totals.
 */
const route = useRoute();
const student = ref(null);
const missing = ref(null);
const load = computed(() => {
    const record = route.params.record || null;
    return (month) => portalAttendanceApi.month(record, month);
});
function failed(error) {
    missing.value = error.status === 404 ? error : null;
}
</script>

<template>
    <PageFrame :title="t('student_attendance.portal.title')" :description="student ? t('student_attendance.portal.text', { name: student.name }) : ''" width="full">
        <section v-if="missing" class="card">
            <EmptyState :icon="UserX" :title="t('student_attendance.portal.no_student')" :text="missing.message" />
        </section>
        <StudentAttendanceTab v-else :load="load" @loaded="(data) => (student = data?.student ?? null)" @failed="failed" />
    </PageFrame>
</template>
