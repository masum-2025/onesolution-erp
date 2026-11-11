<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CalendarClock, Plus, Save, Trash2 } from 'lucide-vue-next';
import { AppButton, AppField, EmptyState, PageFrame, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';

/**
 * A doctor's week: the sessions they sit (weekday, chamber, from and to, how
 * many serials; empty = the usual number in the rules) and which staff
 * sign-in is theirs (then they see their own patients). Saved as a whole
 * with the doctor's version; a session taken out is switched off, its
 * serials stay.
 */
const opd = opdApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const doctors = useResource(() => opd.doctors({ is_active: 1 }));
const doctorList = computed(() => doctors.data.value?.data ?? []);
const doctorId = ref(typeof route.query.doctor === 'string' ? route.query.doctor : '');
const view = ref(null);
const loading = ref(false);
const failed = ref(null);
const sessions = ref([]);
const userId = ref('');
const errors = ref({});
const weekdays = [6, 0, 1, 2, 3, 4, 5]; // Saturday first, as the week runs in Bangladesh; the labels are translated.

async function load() {
    view.value = null;
    errors.value = {};
    if (!doctorId.value) return;
    loading.value = true;
    failed.value = null;
    try {
        view.value = (await opd.schedule(doctorId.value)).data;
        sessions.value = view.value.sessions.map((item) => ({ ...item, serial_limit: item.serial_limit ?? '' }));
        userId.value = view.value.doctor.user_id ?? '';
    } catch (error) {
        failed.value = error;
    } finally {
        loading.value = false;
    }
}
watch(doctorId, () => {
    router.replace({ query: doctorId.value ? { doctor: doctorId.value } : {} });
    load();
}, { immediate: true });
watch(doctorList, (list) => {
    if (!doctorId.value && list.length === 1) doctorId.value = list[0].id;
});

const can = computed(() => view.value?.can ?? {});
const chambers = computed(() => view.value?.chambers ?? []);
function add() {
    sessions.value.push({ id: null, chamber_id: chambers.value[0]?.id ?? '', weekday: 6, starts_at: '17:00', ends_at: '20:00', serial_limit: '' });
}
const error = (index, field) => errors.value[`sessions.${index}.${field}`]?.[0] ?? null;

const saving = ref(false);
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        view.value = (await opd.saveSchedule(doctorId.value, {
            user_id: userId.value || null, base_version: view.value.doctor.version,
            sessions: sessions.value.map((item) => ({
                id: item.id || null, chamber_id: item.chamber_id, weekday: Number(item.weekday), starts_at: item.starts_at, ends_at: item.ends_at,
                serial_limit: item.serial_limit === '' || item.serial_limit === null ? null : Number(item.serial_limit),
            })),
        })).data;
        sessions.value = view.value.sessions.map((item) => ({ ...item, serial_limit: item.serial_limit ?? '' }));
        toast.success(t('outpatient.schedules.saved'));
    } catch (problem) {
        errors.value = problem.errors ?? {};
        toast.error(problem.message);
        if (problem.status === 409) load();
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('outpatient.schedules.title')" :description="t('outpatient.schedules.text')" width="lg" :resource="doctors" guide="outpatient.guide.doctors"
               :empty="{ when: !doctorList.length, icon: CalendarClock, title: t('outpatient.schedules.no_doctors'), text: t('outpatient.schedules.no_doctors_text') }">
        <template #actions>
            <AppButton v-if="view && can.manage" variant="primary" :icon="Save" :loading="saving" @click="save">{{ t('outpatient.schedules.save') }}</AppButton>
        </template>
        <div class="mb-4 flex flex-wrap items-end gap-3">
            <AppField v-slot="{ id }" :label="t('outpatient.schedules.doctor')" class="min-w-64">
                <select :id="id" v-model="doctorId" class="field-input">
                    <option value="">{{ t('outpatient.schedules.pick_doctor') }}</option>
                    <option v-for="doctor in doctorList" :key="doctor.id" :value="doctor.id">{{ textIn(doctor.name) }}<template v-if="doctor.specialty"> · {{ doctor.specialty }}</template></option>
                </select>
            </AppField>
            <AppButton variant="ghost" size="sm" @click="router.push({ name: 'outpatient-doctor' })">{{ t('outpatient.schedules.add_doctor') }}</AppButton>
        </div>

        <p v-if="loading" class="text-[13px] text-muted">{{ t('outpatient.common.loading') }}</p>
        <p v-else-if="failed" class="card p-4 text-[13px] text-bad" role="alert">{{ failed.message }}</p>
        <template v-else-if="view">
            <section class="card mb-4 p-4">
                <h2 class="mb-1 text-[14px] font-semibold">{{ t('outpatient.schedules.sign_in') }}</h2>
                <p class="mb-3 text-[12.5px] text-muted">{{ t('outpatient.schedules.sign_in_text') }}</p>
                <AppField v-slot="{ id }" :label="t('outpatient.schedules.staff')" :error="errors.user_id?.[0]" class="max-w-sm">
                    <select :id="id" v-model="userId" class="field-input" :disabled="!can.manage">
                        <option value="">{{ t('outpatient.schedules.no_sign_in') }}</option>
                        <option v-for="person in view.staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                        <option v-if="userId && !view.staff.some((person) => person.id === userId)" :value="userId">{{ t('outpatient.schedules.linked') }}</option>
                    </select>
                </AppField>
            </section>

            <section class="card">
                <div class="flex items-center justify-between border-b border-line px-4 py-2.5">
                    <h2 class="text-[13.5px] font-semibold">{{ t('outpatient.schedules.week') }}</h2>
                    <AppButton v-if="can.manage && chambers.length" size="sm" variant="ghost" :icon="Plus" @click="add">{{ t('outpatient.schedules.add') }}</AppButton>
                </div>
                <EmptyState v-if="!chambers.length" :icon="CalendarClock" :title="t('outpatient.schedules.no_chambers')" :text="t('outpatient.schedules.no_chambers_text')" compact />
                <p v-else-if="!sessions.length" class="px-4 py-3 text-[13px] text-muted">{{ t('outpatient.schedules.no_sessions') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="(item, index) in sessions" :key="item.id ?? `new-${index}`" class="grid gap-3 px-4 py-3 sm:grid-cols-[1fr_1.4fr_auto_auto_7rem_auto] sm:items-end">
                        <AppField v-slot="{ id }" :label="t('outpatient.schedules.weekday')">
                            <select :id="id" v-model.number="item.weekday" class="field-input" :disabled="!can.manage">
                                <option v-for="day in weekdays" :key="day" :value="day">{{ t(`outpatient.weekday.${day}`) }}</option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('outpatient.schedules.chamber')" :error="error(index, 'chamber_id')">
                            <select :id="id" v-model="item.chamber_id" class="field-input" :disabled="!can.manage">
                                <option v-for="chamber in chambers" :key="chamber.id" :value="chamber.id">{{ chamber.name }}<template v-if="chamber.room"> ({{ chamber.room }})</template></option>
                            </select>
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('outpatient.schedules.from')" :error="error(index, 'starts_at')">
                            <input :id="id" v-model="item.starts_at" type="time" class="field-input" dir="ltr" :disabled="!can.manage" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('outpatient.schedules.to')" :error="error(index, 'ends_at')">
                            <input :id="id" v-model="item.ends_at" type="time" class="field-input" dir="ltr" :disabled="!can.manage" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('outpatient.schedules.limit')" :error="error(index, 'serial_limit')">
                            <input :id="id" v-model="item.serial_limit" type="number" min="1" max="500" inputmode="numeric" class="field-input"
                                   :placeholder="t('outpatient.schedules.usual', { count: view.usual_limit })" :disabled="!can.manage" />
                        </AppField>
                        <AppButton v-if="can.manage" size="sm" variant="ghost" :icon="Trash2" :aria-label="t('outpatient.schedules.remove')" @click="sessions.splice(index, 1)" />
                    </li>
                </ul>
                <p v-if="sessions.length" class="border-t border-line px-4 py-2 text-[12px] text-muted">{{ t('outpatient.schedules.remove_hint') }}</p>
            </section>
        </template>
        <EmptyState v-else-if="doctorList.length" :icon="CalendarClock" :title="t('outpatient.schedules.pick_doctor')" compact />
    </PageFrame>
</template>
