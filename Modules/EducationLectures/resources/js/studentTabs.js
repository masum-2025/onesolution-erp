import { defineAsyncComponent } from 'vue';
import { t } from '@/lib/i18n';

/**
 * The lectures tab of a student's page in Education (see Education's
 * moduleTabs.js): the published lectures of their section now.
 */
export default [
    {
        key: 'lectures',
        module: 'education_lectures',
        ns: 'education_lectures',
        order: 55,
        label: () => t('education_lectures.student_tab.title'),
        component: defineAsyncComponent(() => import('./components/StudentLectures.vue')),
    },
];
