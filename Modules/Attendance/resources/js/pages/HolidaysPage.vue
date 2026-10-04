<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ArrowLeft, CalendarHeart, ChevronLeft, ChevronRight, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';

/**
 * Days off of a year: the company's, and those of this unit (and the units
 * above it). Weekends come from the rules, not from this list.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const year = ref(new Date().getFullYear());
const list = useResource(() => attendance.holidays(year.value));
const holidays = computed(() => list.data.value?.data ?? []);
watch(year, () => list.reload());

const adding = ref(false);
const saving = ref(false);
const errors = ref({});
const form = reactive({ on: '', name: { en: '', bn: '' }, onlyHere: false });
const isUnit = computed(() => !['company', 'personal'].includes(org.organization_type));

function add() {
    Object.assign(form, { on: `${year.value}-01-01`, name: { en: '', bn: '' }, onlyHere: false });
    errors.value = {};
    adding.value = true;
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await attendance.addHoliday({
            on: form.on,
            name: Object.fromEntries(Object.entries(form.name).filter(([, value]) => value?.trim())),
            ...(form.onlyHere ? { unit_id: org.id } : {}),
        });
        toast.success(t('attendance.holidays.added'));
        adding.value = false;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function remove(holiday) {
    const confirmed = await confirmAction({ title: t('attendance.holidays.remove_title', { name: holiday.name }), message: t('attendance.holidays.remove_text'), confirmLabel: t('attendance.holidays.remove'), danger: true });
    if (!confirmed) return;
    try {
        await attendance.removeHoliday(holiday.id);
        toast.success(t('attendance.holidays.removed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.holidays.title')" :description="t('attendance.holidays.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'attendance' }" :icon="ArrowLeft">{{ t('attendance.common.back') }}</AppButton>
                <div class="flex items-center gap-1">
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronLeft" :aria-label="t('attendance.holidays.previous_year')" @click="year--" />
                    <span class="tabular min-w-14 text-center text-[14px] font-semibold">{{ formatNumber(year, { useGrouping: false }) }}</span>
                    <AppButton size="icon-sm" variant="ghost" :icon="ChevronRight" :aria-label="t('attendance.holidays.next_year')" @click="year++" />
                </div>
                <AppButton v-if="can('attendance.manage')" variant="primary" :icon="Plus" @click="add">{{ t('attendance.holidays.add') }}</AppButton>
            </template>
        </PageHeader>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!holidays.length" :icon="CalendarHeart" :title="t('attendance.holidays.empty')" :text="t('attendance.holidays.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="holiday in holidays" :key="holiday.id" class="flex items-center gap-4 px-5 py-3">
                    <span class="w-36 shrink-0 text-[13px] text-muted">{{ formatDate(`${holiday.on}T00:00:00Z`, { weekday: 'short', day: 'numeric', month: 'long', timeZone: 'UTC' }) }}</span>
                    <span class="min-w-0 flex-1 truncate text-[14px] font-medium">{{ holiday.name }}</span>
                    <AppBadge v-if="holiday.unit_name" tone="outline">{{ holiday.unit_name }}</AppBadge>
                    <AppButton v-if="can('attendance.manage')" size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('attendance.holidays.remove')" @click="remove(holiday)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="adding" :title="t('attendance.holidays.add')" @close="adding = false">
            <form id="attendance-holiday" class="grid gap-4" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('attendance.holidays.date')" :error="errors.on?.[0]">
                    <input :id="id" v-model="form.on" type="date" class="field-input" />
                </AppField>
                <TranslatedFields v-model="form.name" :label="t('attendance.holidays.name')" :errors="errors" required :maxlength="80" />
                <AppSwitch v-if="isUnit" v-model="form.onlyHere" :label="t('attendance.holidays.only_here', { unit: org.name })" show-label />
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="adding = false">{{ t('attendance.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="attendance-holiday" :loading="saving" :disabled="!form.on || !form.name.en?.trim()">{{ t('attendance.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
