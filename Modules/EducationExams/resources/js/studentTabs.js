import { defineAsyncComponent } from 'vue';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { examsApi } from './api';
import { pointShown } from './labels';

/**
 * The results tab of a student's page in Education (see Education's
 * lib/studentTabs.js): published results and report cards, and a card with
 * the latest result. Teachers see it only for students whose whole result
 * they may see (the server answers "not found" otherwise; the card hides).
 */
export default [
    {
        key: 'results',
        module: 'education_exams',
        ns: 'education_exams',
        order: 40,
        label: () => t('education_exams.student_tab.title'),
        component: defineAsyncComponent(() => import('./components/StudentResults.vue')),
        async summary(studentId) {
            const { data } = await examsApi(currentOrganization().id).transcript(studentId);
            const latest = data.results[0] ?? null;
            if (!latest) return { value: '—', hint: t('education_exams.student_tab.none'), tone: null };
            return { value: `${latest.letter ?? '—'} · ${pointShown(latest.gpa_centi)}`, hint: textIn(latest.name), tone: latest.passed ? 'ok' : 'bad' };
        },
    },
];
