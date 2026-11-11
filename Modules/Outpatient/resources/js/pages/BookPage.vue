<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CalendarClock, Search, Stethoscope, UserPlus } from 'lucide-vue-next';
import { AppBadge, AppButton, AppField, DateInput, EmptyState, PageFrame, localToday, newOpId, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';

/**
 * Giving a serial: find the patient (or register them), pick a date and one
 * of the sessions held that day (free places shown), see whether it is a new
 * visit, a free follow-up or the follow-up fee, then give it: the fee goes on
 * the patient's bill and the slip opens. One tap gives one serial (op id).
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => opd.setup());
const currency = computed(() => setup.data.value?.data?.currency ?? null);

// 1. The patient
const patient = ref(null);
const term = ref('');
const found = ref([]);
const searching = ref(false);
let timer = null;
watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            found.value = (await opd.patients(value.trim())).data;
        } catch (error) {
            toast.error(error.message);
        } finally {
            searching.value = false;
        }
    }, 300);
});

// 2. The day and the session
const date = ref(typeof route.query.date === 'string' ? route.query.date : localToday());
const sessions = useResource(() => opd.sessions({ date: date.value }));
watch(date, () => {
    chosen.value = null;
    sessions.reload();
});
const list = computed(() => sessions.data.value?.data ?? []);
const chosen = ref(null);

// 3. What it costs
const quote = ref(null);
watch([patient, chosen], async () => {
    quote.value = null;
    if (!patient.value || !chosen.value) return;
    try {
        quote.value = (await opd.quote({ session: chosen.value.id, patient: patient.value.id, date: date.value })).data;
    } catch (error) {
        toast.error(error.message);
    }
});

const saving = ref(false);
let opId = newOpId();
async function give() {
    saving.value = true;
    try {
        const serial = (await opd.book({ schedule_id: chosen.value.id, date: date.value, patient_id: patient.value.id, op_id: opId })).data;
        opId = newOpId();
        toast.success(t('outpatient.book.given', { number: serial.serial_no, doctor: serial.doctor?.name ?? '' }));
        router.push({ name: 'outpatient-slip', params: { id: serial.id } });
    } catch (error) {
        toast.error(error.message);
        sessions.reload();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('outpatient.book.title')" :description="t('outpatient.book.text')" width="lg" guide="outpatient.guide.book"
               :guide-settings="{ name: 'module-settings', params: { module: 'outpatient' } }">
        <div class="grid gap-4 lg:grid-cols-2">
            <section class="card p-4">
                <h2 class="mb-3 text-[14px] font-semibold">{{ t('outpatient.book.step_patient') }}</h2>
                <div v-if="patient" class="flex items-center justify-between rounded-lg bg-subtle px-3 py-2 text-[13.5px]">
                    <span><span class="font-medium">{{ patient.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ patient.mrn }}</span> · {{ t('outpatient.common.years', { count: patient.age ?? '—' }) }}</span>
                    <button type="button" class="text-[12.5px] font-medium text-accent" @click="patient = null">{{ t('outpatient.book.change') }}</button>
                </div>
                <div v-else class="grid gap-2">
                    <div class="relative">
                        <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                        <input v-model="term" type="search" class="field-input ps-9" :placeholder="t('outpatient.book.find')" :aria-label="t('outpatient.book.find')" autofocus />
                    </div>
                    <ul v-if="found.length" class="max-h-64 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                        <li v-for="item in found" :key="item.id">
                            <button type="button" class="w-full px-3 py-2 text-start text-[13.5px] hover:bg-subtle" @click="patient = item">
                                <span class="font-medium">{{ item.name }}</span> <span class="font-mono text-[12px] text-muted" dir="ltr">{{ item.mrn }}</span>
                                · {{ t('outpatient.common.years', { count: item.age ?? '—' }) }}<template v-if="item.mobile"> · <span dir="ltr">{{ item.mobile }}</span></template>
                            </button>
                        </li>
                    </ul>
                    <p v-else-if="term.trim().length >= 2 && !searching" class="text-[12.5px] text-muted">{{ t('outpatient.book.no_patient') }}</p>
                    <AppButton variant="ghost" size="sm" :icon="UserPlus" class="justify-self-start" @click="router.push({ name: 'patients-find' })">{{ t('outpatient.book.register') }}</AppButton>
                </div>
            </section>

            <section class="card p-4">
                <h2 class="mb-3 text-[14px] font-semibold">{{ t('outpatient.book.step_session') }}</h2>
                <AppField v-slot="{ id }" :label="t('outpatient.book.date')" class="mb-3"><DateInput :id="id" v-model="date" not-past /></AppField>
                <p v-if="sessions.loading.value" class="text-[13px] text-muted">{{ t('outpatient.common.loading') }}</p>
                <p v-else-if="sessions.error.value" class="text-[13px] text-bad">{{ sessions.error.value.message }}</p>
                <EmptyState v-else-if="!list.length" :icon="CalendarClock" :title="t('outpatient.book.no_sessions', { date: formatDate(date) })" :text="t('outpatient.book.no_sessions_text')" compact />
                <div v-else class="grid gap-2" role="radiogroup" :aria-label="t('outpatient.book.step_session')">
                    <button v-for="item in list" :key="item.id" type="button" role="radio" :aria-checked="chosen?.id === item.id" :disabled="item.free <= 0"
                            class="flex items-center gap-3 rounded-lg px-3 py-2.5 text-start ring-1 transition disabled:cursor-not-allowed disabled:opacity-50"
                            :class="chosen?.id === item.id ? 'bg-accent/10 ring-accent' : 'ring-line hover:bg-subtle'" @click="chosen = item">
                        <Stethoscope class="size-4 shrink-0 text-muted" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14px] font-medium">{{ item.doctor }}</span>
                            <span class="block text-[12.5px] text-muted"><span dir="ltr">{{ item.starts_at }}–{{ item.ends_at }}</span> · {{ item.chamber }}<template v-if="item.room"> ({{ item.room }})</template></span>
                        </span>
                        <AppBadge :tone="item.free > 0 ? 'ok' : 'bad'">{{ item.free > 0 ? t('outpatient.book.free_places', { free: item.free, limit: item.limit }) : t('outpatient.book.full') }}</AppBadge>
                    </button>
                </div>
            </section>
        </div>

        <section v-if="patient && chosen" class="card mt-4 flex flex-wrap items-center gap-3 p-4">
            <div class="min-w-0 flex-1 text-[13.5px]">
                <p class="font-medium">{{ t('outpatient.book.summary', { patient: patient.name, doctor: chosen.doctor, date: formatDate(date) }) }}</p>
                <p v-if="quote" class="text-muted">
                    {{ t(`outpatient.kind.${quote.kind}`) }}<template v-if="quote.last_visit"> · {{ t('outpatient.book.last_visit', { date: formatDate(quote.last_visit) }) }}</template> ·
                    <span class="font-medium text-fg">{{ quote.is_free ? t('outpatient.book.no_fee') : t('outpatient.book.fee', { amount: formatMoney({ amount: quote.price_minor ?? 0, currency }) }) }}</span>
                </p>
            </div>
            <AppButton variant="primary" :loading="saving" :disabled="!quote" @click="give">{{ quote?.is_free ? t('outpatient.book.give_free') : t('outpatient.book.give') }}</AppButton>
        </section>
    </PageFrame>
</template>
