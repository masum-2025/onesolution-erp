import { defineAsyncComponent } from 'vue';
import { t } from '@/lib/i18n';

/**
 * The attendance tab of a student's page in Education (see Education's
 * moduleTabs.js): their month as a calendar, with totals.
 */
export default [
    {
        key: 'attendance',
        module: 'student_attendance',
        ns: 'student_attendance',
        order: 15,
        label: () => t('student_attendance.student_tab.title'),
        component: defineAsyncComponent(() => import('./components/StudentAttendanceTab.vue')),
    },
];
