<script setup>
import { computed, reactive, ref } from 'vue';
import { BedDouble, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, AppSwitch, PageFrame, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { bedLooks, ipdApi } from '../api';

/**
 * Beds of each ward: code, the billing service of the daily charge, in use.
 * A bed that ever had a patient is switched off instead of removed.
 */
const ipd = ipdApi(currentOrganization().id);
const beds = useResource(() => ipd.beds());
const wards = useResource(() => ipd.wards());
const wardList = computed(() => wards.data.value?.data ?? []);
const wardName = (id) => textIn(wardList.value.find((ward) => ward.id === id)?.name ?? {});
const rows = computed(() => [...(beds.data.value?.data ?? [])].sort((a, b) => wardName(a.ward_id).localeCompare(wardName(b.ward_id)) || a.code.localeCompare(b.code)));

const editing = ref(null);
const draft = reactive({ ward_id: '', code: '', service_code: '', is_active: true });
const errors = ref({});
function open(bed = null) {
    Object.assign(draft, { ward_id: bed?.ward_id ?? wardList.value[0]?.id ?? '', code: bed?.code ?? '', service_code: bed?.service_code ?? '', is_active: bed?.is_active ?? true });
    errors.value = {};
    editing.value = bed ?? { id: null };
}
const saving = ref(false);
async function save() {
    saving.value = true;
    errors.value = {};
    const bed = editing.value;
    try {
        await ipd.saveBed(bed.id, { ward_id: draft.ward_id, code: draft.code.trim(), service_code: draft.service_code.trim().toUpperCase(), is_active: draft.is_active, ...(bed.id ? { base_version: bed.version } : {}) });
        toast.success(t('inpatient.beds.saved'));
        editing.value = null;
        beds.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function remove(bed) {
    const yes = await confirmAction({ title: t('inpatient.beds.remove_title', { code: bed.code }), message: t('inpatient.beds.remove_text'), confirmLabel: t('inpatient.beds.remove'), danger: true });
    if (!yes) return;
    try {
        await ipd.removeBed(bed.id, bed.version);
        beds.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('inpatient.beds.title')" :description="t('inpatient.beds.text')" width="lg" :resource="beds" guide="inpatient.guide.wards"
               :empty="{ when: !rows.length, icon: BedDouble, title: t('inpatient.beds.empty'), text: wardList.length ? t('inpatient.beds.empty_text') : t('inpatient.beds.no_wards') }">
        <template #actions>
            <AppButton variant="primary" :icon="Plus" :disabled="!wardList.length" @click="open()">{{ t('inpatient.beds.new') }}</AppButton>
        </template>
        <section class="card overflow-x-auto">
            <table class="w-full text-[13.5px]">
                <thead>
                    <tr class="border-b border-line text-[12px] text-muted">
                        <th class="px-4 py-2 text-start font-medium">{{ t('inpatient.beds.ward') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('inpatient.beds.code') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('inpatient.beds.service_code') }}</th>
                        <th class="px-4 py-2 text-start font-medium">{{ t('inpatient.beds.status') }}</th>
                        <th class="px-4 py-2"><span class="sr-only">{{ t('inpatient.beds.actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line">
                    <tr v-for="bed in rows" :key="bed.id" :class="!bed.is_active && 'opacity-60'">
                        <td class="px-4 py-2">{{ wardName(bed.ward_id) }}</td>
                        <td class="px-4 py-2 font-medium" dir="ltr">{{ bed.code }}</td>
                        <td class="px-4 py-2 font-mono text-[12.5px]" dir="ltr">{{ bed.service_code }}</td>
                        <td class="px-4 py-2"><AppBadge :tone="bedLooks[bed.status].tone">{{ bed.is_active ? t(`inpatient.bed_status.${bed.status}`) : t('inpatient.beds.off') }}</AppBadge></td>
                        <td class="px-4 py-2 text-end">
                            <AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="t('inpatient.beds.edit')" @click="open(bed)" />
                            <AppButton size="sm" variant="ghost" :icon="Trash2" :aria-label="t('inpatient.beds.remove')" @click="remove(bed)" />
                        </td>
                    </tr>
                </tbody>
            </table>
        </section>

        <AppDialog :open="!!editing" :title="editing?.id ? t('inpatient.beds.edit') : t('inpatient.beds.new')" :icon="BedDouble" @close="editing = null">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('inpatient.beds.ward')" :error="errors.ward_id?.[0]">
                    <select :id="id" v-model="draft.ward_id" class="field-input"><option v-for="ward in wardList" :key="ward.id" :value="ward.id">{{ textIn(ward.name) }}</option></select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.beds.code')" :error="errors.code?.[0]"><input :id="id" v-model="draft.code" class="field-input" maxlength="20" dir="ltr" placeholder="M-1" /></AppField>
                <AppField v-slot="{ id }" :label="t('inpatient.beds.service_code')" :hint="t('inpatient.beds.service_hint')" :error="errors.service_code?.[0]">
                    <input :id="id" v-model="draft.service_code" class="field-input" maxlength="30" dir="ltr" />
                </AppField>
                <AppSwitch v-model="draft.is_active" :label="t('inpatient.beds.in_use')" show-label />
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('inpatient.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" :disabled="!draft.ward_id || !draft.code.trim() || !draft.service_code.trim()" @click="save">{{ t('inpatient.beds.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
