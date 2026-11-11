<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Maximize } from 'lucide-vue-next';
import { AppButton } from '@/ui';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';

/**
 * The waiting-room screen of one chamber, for a TV on a signed-in device:
 * for each doctor sitting today the serial being seen now and the ones
 * waiting. Numbers only, never a patient's name. Fresh every 15 seconds; a
 * failed refresh keeps the last numbers and says so.
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const screen = ref(null);
const failed = ref(null);
const loaded = ref(false);
async function load() {
    try {
        screen.value = (await opd.queue(route.params.chamber)).data;
        failed.value = null;
    } catch (error) {
        failed.value = error;
    } finally {
        loaded.value = true;
    }
}
let timer = null;
onMounted(() => {
    load();
    timer = setInterval(load, 15000);
});
onBeforeUnmount(() => clearInterval(timer));
const doctors = computed(() => screen.value?.doctors ?? []);
const fullScreen = () => document.documentElement.requestFullscreen?.().catch(() => {});
</script>

<template>
    <div class="min-h-dvh bg-bg p-4 text-fg sm:p-8">
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" @click="router.push({ name: 'outpatient-serials' })">{{ t('outpatient.queue.back') }}</AppButton>
            <h1 class="text-[22px] font-semibold sm:text-[28px]">
                {{ screen?.chamber?.name ?? t('outpatient.queue.title') }}<template v-if="screen?.chamber?.room"> · {{ t('outpatient.slip.room', { room: screen.chamber.room }) }}</template>
            </h1>
            <span v-if="screen" class="text-[15px] text-muted">{{ formatDate(screen.date) }}</span>
            <AppButton variant="ghost" size="sm" :icon="Maximize" class="ms-auto" @click="fullScreen">{{ t('outpatient.queue.full_screen') }}</AppButton>
        </div>
        <p v-if="failed && !screen" class="card p-6 text-[16px]" role="alert">{{ failed.message }}</p>
        <p v-else-if="failed" class="mb-4 text-[13px] text-warn" role="status">{{ t('outpatient.queue.stale') }}</p>
        <p v-if="!loaded" class="text-[16px] text-muted">{{ t('outpatient.common.loading') }}</p>
        <p v-else-if="screen && !doctors.length" class="card p-8 text-center text-[20px] text-muted">{{ t('outpatient.queue.empty') }}</p>
        <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-live="polite">
            <section v-for="doctor in doctors" :key="doctor.name" class="card p-6">
                <h2 class="text-[20px] font-semibold sm:text-[24px]">{{ doctor.name }}</h2>
                <p class="mt-4 text-[14px] uppercase tracking-wide text-muted">{{ t('outpatient.queue.now') }}</p>
                <p class="text-[72px] font-bold leading-none tabular text-accent sm:text-[96px]">{{ doctor.now[0] ?? '—' }}</p>
                <p class="mt-4 text-[14px] uppercase tracking-wide text-muted">{{ t('outpatient.queue.waiting') }}</p>
                <p class="text-[28px] font-semibold tabular">{{ doctor.waiting.length ? doctor.waiting.join(', ') : '—' }}</p>
                <p v-if="doctor.booked" class="mt-2 text-[14px] text-muted">{{ t('outpatient.queue.booked', { count: doctor.booked }) }}</p>
            </section>
        </div>
    </div>
</template>
