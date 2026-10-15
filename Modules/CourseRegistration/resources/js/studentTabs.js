import { defineAsyncComponent } from 'vue';
import { currentOrganization } from '@/lib/session';
import { whenLoaded } from '@/lib/loaded';
import { t } from '@/lib/i18n';
import { registrationApi } from './api';
import { useRegistrationSetup } from './setup';
import { credits } from './lib';

/**
 * The courses tab of a student's page in Education (see Education's
 * lib/studentTabs.js): their subject registrations, and a card with this
 * session's.
 */
export default [
    {
        key: 'courses',
        module: 'course_registration',
        ns: 'course_registration',
        order: 50,
        label: () => t('course_registration.student_tab.title'),
        component: defineAsyncComponent(() => import('./components/StudentCourses.vue')),
        async summary(studentId) {
            const setup = useRegistrationSetup();
            const [{ data }] = await Promise.all([registrationApi(currentOrganization().id).registrations({ student_id: studentId, per_page: 100 }), whenLoaded(setup)]);
            const now = data.find((row) => row.session_id === setup.sessionId.value) ?? null;
            if (!now) return { value: '—', hint: t('course_registration.student_tab.none', { session: setup.sessionName(setup.sessionId.value) }), tone: 'warn' };
            return {
                value: t('course_registration.credits', { count: Number(credits(now.credits_centi)) }),
                hint: t(`course_registration.statuses.${now.status}`),
                tone: { approved: 'ok', returned: 'bad' }[now.status] ?? null,
            };
        },
    },
];
