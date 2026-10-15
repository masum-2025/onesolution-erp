import { defineAsyncComponent } from 'vue';
import { currentOrganization } from '@/lib/session';
import { whenLoaded } from '@/lib/loaded';
import { t } from '@/lib/i18n';
import { feesApi } from './api';
import { useFeesSetup } from './setup';

/**
 * The fees tab of a student's page in Education (see Education's
 * lib/studentTabs.js): what they owe, their bills, receipts and discounts,
 * and a card with what is owed now.
 */
export default [
    {
        key: 'fees',
        module: 'education_fees',
        ns: 'education_fees',
        order: 30,
        label: () => t('education_fees.student_tab.title'),
        component: defineAsyncComponent(() => import('./components/StudentFeesPanel.vue')),
        async summary(studentId) {
            const setup = useFeesSetup();
            const [{ data }] = await Promise.all([feesApi(currentOrganization().id).student(studentId), whenLoaded(setup)]);
            if (data.overdue_minor > 0) return { value: setup.money(data.owed_minor), hint: t('education_fees.student_tab.overdue', { amount: setup.money(data.overdue_minor) }), tone: 'bad' };
            if (data.owed_minor > 0) return { value: setup.money(data.owed_minor), hint: t('education_fees.student_tab.owed'), tone: 'warn' };
            return { value: setup.money(0), hint: data.advance_minor > 0 ? t('education_fees.student_tab.advance', { amount: setup.money(data.advance_minor) }) : t('education_fees.student_tab.clear'), tone: 'ok' };
        },
    },
];
