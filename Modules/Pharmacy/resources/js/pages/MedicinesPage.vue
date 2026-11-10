<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { PenLine, Pill, Plus, Search, ShieldAlert } from 'lucide-vue-next';
import { MoneyInput, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { formatMoney } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { pharmacyApi } from '../api';

/** The medicine list: brand, generic, strength and form, MRP and selling price, packs; found by any name or barcode. */
const ph = pharmacyApi(currentOrganization().id);
const setup = useResource(() => ph.setup());
const options = computed(() => setup.data.value?.data?.options ?? {});
const forms = computed(() => setup.data.value?.data?.forms ?? []);
const currency = computed(() => setup.data.value?.data?.currency ?? null);
const canManage = computed(() => !!setup.data.value?.data?.can?.manage);

const search = ref('');
const genericId = ref('');
const page = ref(1);
const list = useResource(() => ph.medicines({ q: search.value.trim() || undefined, generic_id: genericId.value || undefined, page: page.value }));
let typing = null;
watch(search, () => {
    clearTimeout(typing);
    typing = setTimeout(() => {
        page.value = 1;
        list.reload();
    }, 300);
});
watch([genericId, page], () => list.reload());
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? { total: 0, page: 1, last_page: 1 });
const money = (amount) => formatMoney({ amount, currency: currency.value });

const blank = () => ({ brand_name: { en: '', bn: '' }, sku: '', barcode: '', generic_id: options.value.generic_id?.[0]?.id ?? '', manufacturer_id: '', strength: '', form: 'tablet',
    dgda_no: '', mrp_minor: null, sale_price_minor: null, pieces_per_strip: 10, strips_per_box: 10, requires_prescription: false, is_active: true });
const form = reactive(blank());
const editing = ref(null);
const saving = ref(false);
const errors = ref({});
function edit(row = null) {
    Object.assign(form, row ? {
        brand_name: { en: row.brand_name.en ?? '', bn: row.brand_name.bn ?? '' }, sku: row.sku ?? '', barcode: row.barcode ?? '', generic_id: row.generic_id, manufacturer_id: row.manufacturer_id ?? '',
        strength: row.strength ?? '', form: row.form, dgda_no: row.dgda_no ?? '', mrp_minor: row.mrp_minor, sale_price_minor: row.sale_price_minor, pieces_per_strip: row.pieces_per_strip,
        strips_per_box: row.strips_per_box, requires_prescription: row.requires_prescription, is_active: row.is_active,
    } : blank());
    errors.value = {};
    editing.value = row ?? 'new';
}
const aboveMrp = computed(() => form.sale_price_minor !== null && form.mrp_minor !== null && form.sale_price_minor > form.mrp_minor);
async function save() {
    const body = {
        brand_name: Object.fromEntries(Object.entries(form.brand_name).filter(([, value]) => value?.trim())), sku: form.sku.trim(), barcode: form.barcode.trim() || null,
        generic_id: form.generic_id, manufacturer_id: form.manufacturer_id || null, strength: form.strength.trim() || null, form: form.form, dgda_no: form.dgda_no.trim() || null,
        mrp_minor: form.mrp_minor, sale_price_minor: form.sale_price_minor, pieces_per_strip: form.pieces_per_strip || null, strips_per_box: form.strips_per_box || null,
        requires_prescription: form.requires_prescription,
    };
    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await ph.createMedicine({ ...body, op_id: crypto.randomUUID() });
        else await ph.updateMedicine(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        toast.success(t('pharmacy.medicines.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const exported = computed(() => ({
    module: 'pharmacy',
    page: 'pharmacy.medicines',
    columns: [
        { key: 'sku', label: t('pharmacy.medicines.sku') },
        { key: 'brand', label: t('pharmacy.medicines.brand'), value: (row) => textIn(row.brand_name) },
        { key: 'generic', label: t('pharmacy.medicines.generic'), value: (row) => textIn(row.generic) },
        { key: 'strength', label: t('pharmacy.medicines.strength') },
        { key: 'form', label: t('pharmacy.medicines.form'), value: (row) => t(`pharmacy.forms.${row.form}`) },
        { key: 'mrp_minor', label: 'MRP', type: 'money' },
        { key: 'sale_price_minor', label: t('pharmacy.medicines.price'), type: 'money' },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('pharmacy.medicines.title')" :description="t('pharmacy.medicines.text')" width="full" :resource="list" :export="exported" guide="pharmacy.guide.medicines"
               :guide-settings="{ name: 'pharmacy-generic' }">
        <template v-if="canManage" #actions>
            <AppButton variant="primary" :icon="Plus" @click="edit()">{{ t('pharmacy.medicines.add') }}</AppButton>
        </template>
        <template #top>
            <section class="card mb-4 flex flex-wrap items-end gap-3 p-4 print:hidden">
                <div class="relative min-w-0 flex-1">
                    <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                    <input v-model="search" class="field-input ps-9" :placeholder="t('pharmacy.medicines.search')" :aria-label="t('pharmacy.medicines.search')" />
                </div>
                <select v-model="genericId" class="field-input w-auto" :aria-label="t('pharmacy.medicines.generic')">
                    <option value="">{{ t('pharmacy.medicines.all_generics') }}</option>
                    <option v-for="generic in options.generic_id ?? []" :key="generic.id" :value="generic.id">{{ textIn(generic.name) }}</option>
                </select>
            </section>
        </template>

        <section class="card">
            <p v-if="!rows.length" class="p-8 text-center text-[13.5px] text-muted">{{ search || genericId ? t('pharmacy.medicines.none_found') : t('pharmacy.medicines.empty') }}</p>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Pill class="size-5" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px] font-medium">{{ textIn(row.brand_name) }} <span class="text-[12.5px] text-muted">{{ row.strength }} · {{ t(`pharmacy.forms.${row.form}`) }}</span></span>
                        <span class="block text-[12.5px] text-muted">{{ textIn(row.generic) }}<template v-if="textIn(row.manufacturer)"> · {{ textIn(row.manufacturer) }}</template> · <span class="font-mono" dir="ltr">{{ row.sku }}</span></span>
                    </span>
                    <AppBadge v-if="row.is_controlled" tone="warn"><ShieldAlert class="me-1 inline size-3.5" aria-hidden="true" />{{ t('pharmacy.sell.controlled') }}</AppBadge>
                    <AppBadge v-if="!row.is_active" tone="neutral">{{ t('pharmacy.medicines.off') }}</AppBadge>
                    <span class="tabular w-36 text-end text-[13px]"><span class="block font-semibold">{{ money(row.sale_price_minor) }}</span><span class="block text-muted">MRP {{ money(row.mrp_minor) }}</span></span>
                    <AppButton v-if="canManage" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('pharmacy.medicines.edit')" @click="edit(row)" />
                </li>
            </ul>
            <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3 text-[13px] print:hidden">
                <AppButton size="sm" variant="ghost" :disabled="meta.page <= 1" @click="page--">{{ t('core.actions.previous') }}</AppButton>
                <span class="text-muted">{{ meta.page }} / {{ meta.last_page }}</span>
                <AppButton size="sm" variant="ghost" :disabled="meta.page >= meta.last_page" @click="page++">{{ t('core.actions.next') }}</AppButton>
            </div>
        </section>

        <AppDialog :open="editing !== null" size="lg" :title="editing === 'new' ? t('pharmacy.medicines.add') : t('pharmacy.medicines.edit')" :icon="Pill" @close="editing = null">
            <p v-if="!(options.generic_id ?? []).length" class="mb-3 rounded-lg bg-warn-soft p-3 text-[13px]">
                {{ t('pharmacy.medicines.need_generic') }} <RouterLink :to="{ name: 'pharmacy-generic' }" class="font-medium underline">{{ t('pharmacy.medicines.add_generic') }}</RouterLink>
            </p>
            <form id="pharmacy-medicine" class="grid gap-4 sm:grid-cols-3" novalidate @submit.prevent="save">
                <div class="sm:col-span-3"><TranslatedFields v-model="form.brand_name" :label="t('pharmacy.medicines.brand')" :errors="errors" error-prefix="brand_name" required :maxlength="150" /></div>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.generic')" :error="errors.generic_id?.[0]">
                    <select :id="id" v-model="form.generic_id" class="field-input">
                        <option v-for="generic in options.generic_id ?? []" :key="generic.id" :value="generic.id">{{ textIn(generic.name) }}{{ generic.is_controlled ? ' ⚠' : '' }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.strength')" :error="errors.strength?.[0]" optional>
                    <input :id="id" v-model="form.strength" class="field-input" maxlength="40" placeholder="500 mg" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.form')" :error="errors.form?.[0]">
                    <select :id="id" v-model="form.form" class="field-input">
                        <option v-for="kind in forms" :key="kind" :value="kind">{{ t(`pharmacy.forms.${kind}`) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.sku')" :error="errors.sku?.[0]">
                    <input :id="id" v-model="form.sku" class="field-input font-mono" dir="ltr" maxlength="40" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.barcode')" :error="errors.barcode?.[0]" optional>
                    <input :id="id" v-model="form.barcode" class="field-input font-mono" dir="ltr" maxlength="64" inputmode="numeric" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.manufacturer')" :error="errors.manufacturer_id?.[0]" optional>
                    <select :id="id" v-model="form.manufacturer_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="maker in options.manufacturer_id ?? []" :key="maker.id" :value="maker.id">{{ textIn(maker.name) }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.mrp')" :hint="t('pharmacy.medicines.mrp_hint')" :error="errors.mrp_minor?.[0]">
                    <MoneyInput :id="id" v-model="form.mrp_minor" :currency="currency" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.price')" :hint="t('pharmacy.medicines.price_hint')" :error="errors.sale_price_minor?.[0] ?? (aboveMrp ? t('pharmacy.medicines.above_mrp') : null)">
                    <MoneyInput :id="id" v-model="form.sale_price_minor" :currency="currency" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.dgda')" :error="errors.dgda_no?.[0]" optional>
                    <input :id="id" v-model="form.dgda_no" class="field-input font-mono" dir="ltr" maxlength="40" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.per_strip')" :error="errors.pieces_per_strip?.[0]" optional>
                    <input :id="id" v-model.number="form.pieces_per_strip" type="number" min="1" class="field-input tabular" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('pharmacy.medicines.per_box')" :error="errors.strips_per_box?.[0]" optional>
                    <input :id="id" v-model.number="form.strips_per_box" type="number" min="1" class="field-input tabular" />
                </AppField>
                <div class="flex flex-col justify-end gap-2">
                    <AppSwitch v-model="form.requires_prescription" :label="t('pharmacy.medicines.rx')" show-label />
                    <AppSwitch v-if="editing !== 'new'" v-model="form.is_active" :label="t('pharmacy.medicines.in_use')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('pharmacy.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="pharmacy-medicine" :loading="saving" :disabled="!form.brand_name.en?.trim() || !form.sku.trim() || !form.generic_id || !form.mrp_minor">{{ t('pharmacy.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
