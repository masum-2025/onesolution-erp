<script setup>
import { onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { CircleAlert, Clock, FlaskConical, Printer, RotateCw } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { t } from '@/lib/i18n';
import { publicReport } from '../api';
import ReportPaper from '../components/ReportPaper.vue';

/**
 * A lab report opened from its link (SMS or copied): no sign-in, no app
 * around it. Verified values only; an old link and a wrong one each say
 * plainly what to do. Save as PDF from the print window.
 */
const route = useRoute();
const report = ref(null);
const problem = ref(null);
const failed = ref(null);
const loading = ref(true);

async function load() {
    loading.value = true;
    problem.value = null;
    failed.value = null;
    try {
        report.value = (await publicReport(route.params.organization, route.params.token)).data;
    } catch (error) {
        if (error.status === 403) problem.value = { icon: Clock, title: t('diagnostics.public.expired'), text: t('diagnostics.public.expired_text') };
        else if (error.status === 404) problem.value = { icon: CircleAlert, title: t('diagnostics.public.not_found'), text: t('diagnostics.public.not_found_text') };
        else failed.value = error;
    } finally {
        loading.value = false;
    }
}
onMounted(load);
const printReport = () => window.print();
</script>

<template>
    <main class="min-h-dvh bg-subtle px-4 py-6 print:bg-white print:p-0">
        <div v-if="loading" class="flex flex-col items-center gap-3 py-20 text-[13.5px] text-muted" role="status">
            <FlaskConical class="size-10 animate-pulse text-faint" aria-hidden="true" />{{ t('diagnostics.common.loading') }}
        </div>
        <div v-else-if="failed" class="card mx-auto flex max-w-md flex-col items-center gap-3 px-6 py-10 text-center">
            <p class="text-[13.5px] text-muted">{{ failed.message }}</p>
            <AppButton :icon="RotateCw" @click="load">{{ t('diagnostics.public.try_again') }}</AppButton>
        </div>
        <div v-else-if="problem" class="card mx-auto flex max-w-md flex-col items-center gap-3 px-6 py-10 text-center" role="status">
            <span class="grid size-16 place-items-center rounded-full bg-warn-soft text-warn"><component :is="problem.icon" class="size-9" aria-hidden="true" /></span>
            <h1 class="text-[18px] font-semibold text-fg">{{ problem.title }}</h1>
            <p class="max-w-xs text-[13.5px] text-muted">{{ problem.text }}</p>
        </div>
        <template v-else-if="report">
            <div class="mx-auto mb-3 flex max-w-[190mm] justify-end print:hidden">
                <AppButton variant="primary" :icon="Printer" @click="printReport">{{ t('diagnostics.public.print') }}</AppButton>
            </div>
            <ReportPaper :report="report" />
        </template>
    </main>
</template>
