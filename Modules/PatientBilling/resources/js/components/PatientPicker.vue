<script setup>
import { ref, watch } from 'vue';
import { Search } from 'lucide-vue-next';
import { formatMoney } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { money } from '../api';

/**
 * Find the patient to bill: patient number, mobile or name (through billing's
 * own patient search). v-model is the chosen patient (with their advance).
 */
const props = defineProps({
    modelValue: { type: Object, default: null },
    search: { type: Function, required: true },
    currency: { type: String, default: null },
});
const emit = defineEmits(['update:modelValue']);
const term = ref('');
const found = ref([]);
const busy = ref(false);
let timer = null;
watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        busy.value = true;
        try {
            found.value = (await props.search(value.trim())).data ?? [];
        } catch (error) {
            toast.error(error.message);
        } finally {
            busy.value = false;
        }
    }, 300);
});
</script>

<template>
    <div class="grid gap-2">
        <div v-if="modelValue" class="flex items-center justify-between gap-3 rounded-lg bg-subtle px-3 py-2 text-[13.5px]">
            <span>
                <span class="font-medium">{{ modelValue.name }}</span>
                <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ modelValue.mrn }}</span><template v-if="modelValue.mobile"> · <span dir="ltr">{{ modelValue.mobile }}</span></template><template v-if="modelValue.advance_balance_minor"> · {{ t('patient_billing.pick.advance', { amount: formatMoney(money(modelValue.advance_balance_minor, currency)) }) }}</template></span>
            </span>
            <button type="button" class="text-[12.5px] font-medium text-accent" @click="emit('update:modelValue', null)">{{ t('patient_billing.pick.change') }}</button>
        </div>
        <template v-else>
            <div class="relative">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="term" type="search" class="field-input ps-9" :placeholder="t('patient_billing.pick.search')" :aria-label="t('patient_billing.pick.search')" />
            </div>
            <p v-if="busy" class="text-[12.5px] text-muted">{{ t('patient_billing.common.loading') }}</p>
            <p v-else-if="term.trim().length >= 2 && !found.length" class="text-[12.5px] text-muted">{{ t('patient_billing.pick.none') }}</p>
            <ul v-if="found.length" class="max-h-56 divide-y divide-line overflow-auto rounded-lg ring-1 ring-line">
                <li v-for="patient in found" :key="patient.id">
                    <button type="button" class="w-full px-3 py-2 text-start text-[13.5px] hover:bg-subtle" @click="emit('update:modelValue', patient)">
                        <span class="font-medium">{{ patient.name }}</span>
                        <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ patient.mrn }}</span> · {{ t('patient_billing.common.years', { count: patient.age ?? '—' }) }}<template v-if="patient.mobile"> · <span dir="ltr">{{ patient.mobile }}</span></template></span>
                    </button>
                </li>
            </ul>
        </template>
    </div>
</template>
