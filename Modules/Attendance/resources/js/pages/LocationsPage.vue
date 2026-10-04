<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Crosshair, MapPin, PenLine, Plus } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatNumber } from '@/lib/format';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { attendanceApi } from '../api';
import { decimalToMicro, microToDecimal, positionFix } from '../lib';

/**
 * Workplaces people check in from when their unit asks for a location
 * check: a point (typed, or where this device is now) and a radius. A
 * unit's workplaces count for the units below it too.
 */
const org = currentOrganization();
const attendance = attendanceApi(org.id);
const list = useResource(() => attendance.locations());
const locations = computed(() => list.data.value?.data ?? []);
const required = computed(() => list.data.value?.meta.required ?? false);

const editing = ref(null);
const saving = ref(false);
const locating = ref(false);
const errors = ref({});
const form = reactive({ name: '', latitude: '', longitude: '', radius: '', is_active: true });

function edit(location = null) {
    Object.assign(form, {
        name: location?.name ?? '',
        latitude: location ? microToDecimal(location.latitude_micro) : '',
        longitude: location ? microToDecimal(location.longitude_micro) : '',
        radius: location ? String(location.radius_m) : '',
        is_active: location?.is_active ?? true,
    });
    errors.value = {};
    editing.value = location ?? 'new';
}

function here() {
    if (!globalThis.navigator?.geolocation) {
        toast.error(t('attendance.locations.no_gps'));
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            const fix = positionFix(position.coords);
            Object.assign(form, { latitude: microToDecimal(fix.latitude_micro), longitude: microToDecimal(fix.longitude_micro) });
            toast.success(t('attendance.locations.found', { accuracy: formatNumber(fix.accuracy_m) }));
            locating.value = false;
        },
        () => {
            toast.error(t('attendance.locations.gps_denied'));
            locating.value = false;
        },
        { enableHighAccuracy: true, timeout: 15000 },
    );
}

async function save() {
    const latitude = decimalToMicro(form.latitude);
    const longitude = decimalToMicro(form.longitude);
    if (latitude === null || longitude === null) {
        errors.value = { latitude_micro: [t('attendance.locations.bad_point')] };
        return;
    }
    const body = { name: form.name.trim(), latitude_micro: latitude, longitude_micro: longitude, ...(form.radius ? { radius_m: Number(form.radius) } : {}) };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await attendance.createLocation(body);
        else await attendance.updateLocation(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('attendance.locations.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        if (error.code === 'version_conflict') list.reload();
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('attendance.locations.title')" :description="t('attendance.locations.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'attendance' }" :icon="ArrowLeft">{{ t('attendance.common.back') }}</AppButton>
                <AppButton v-if="can('attendance.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('attendance.locations.add') }}</AppButton>
            </template>
        </PageHeader>

        <p class="mb-4 rounded-xl px-4 py-3 text-[13px]" :class="required ? 'bg-brand-soft text-brand-text' : 'bg-subtle text-muted'" role="status">
            {{ required ? t('attendance.locations.required_on', { unit: org.name }) : t('attendance.locations.required_off', { unit: org.name }) }}
        </p>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!locations.length" :icon="MapPin" :title="t('attendance.locations.empty')" :text="t('attendance.locations.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="location in locations" :key="location.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><MapPin class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ location.name }}</span>
                        <span class="block text-[12.5px] text-muted">
                            <span class="tabular" dir="ltr">{{ microToDecimal(location.latitude_micro) }}, {{ microToDecimal(location.longitude_micro) }}</span>
                            · {{ t('attendance.locations.radius_of', { metres: formatNumber(location.radius_m) }) }}<template v-if="location.unit_name"> · {{ location.unit_name }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="!location.is_active" tone="neutral">{{ t('attendance.locations.off') }}</AppBadge>
                    <AppButton v-if="can('attendance.manage')" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('attendance.locations.edit')" @click="edit(location)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('attendance.locations.add') : t('attendance.locations.edit')" :icon="MapPin" @close="editing = null">
            <form id="attendance-location" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('attendance.locations.name')" :error="fieldError('name')" class="sm:col-span-2">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="120" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('attendance.locations.latitude')" :error="fieldError('latitude_micro')">
                    <input :id="id" v-model="form.latitude" inputmode="decimal" class="field-input tabular" dir="ltr" placeholder="23.810331" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('attendance.locations.longitude')" :error="fieldError('longitude_micro')">
                    <input :id="id" v-model="form.longitude" inputmode="decimal" class="field-input tabular" dir="ltr" placeholder="90.412521" />
                </AppField>
                <div class="sm:col-span-2">
                    <AppButton size="sm" variant="secondary" :icon="Crosshair" :loading="locating" @click="here">{{ t('attendance.locations.use_here') }}</AppButton>
                </div>
                <AppField v-slot="{ id }" :label="t('attendance.locations.radius')" :hint="t('attendance.locations.radius_hint')" :error="fieldError('radius_m')" optional>
                    <input :id="id" v-model="form.radius" type="number" min="20" max="5000" step="10" class="field-input tabular text-end" />
                </AppField>
                <div v-if="editing !== 'new'" class="flex items-end">
                    <AppSwitch v-model="form.is_active" :label="t('attendance.locations.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('attendance.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="attendance-location" :loading="saving" :disabled="form.name.trim().length < 2 || !form.latitude || !form.longitude">{{ t('attendance.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
