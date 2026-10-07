<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ChevronDown, Plus, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { amountToMinor, fieldToInput, fieldsToApi, milliToText, minorToText, priceQuote, quantityToMilli } from '../lib';
import { useCrmSetup } from '../setup';
import ExtraFields from '../components/ExtraFields.vue';

/**
 * An estimate or quotation made or changed: the customer (and their deal),
 * days, subject; lines from the item list (name, unit and price filled in)
 * or free text for services and labour, quantity, price, discount, VAT;
 * the company's own fields on the quote and on each line; notes and terms.
 * Totals show as you type; the server prices it again on saving.
 */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useCrmSetup();
const editingId = computed(() => (route.name === 'crm-quote-edit' ? route.params.id : null));
const loading = ref(false);
const loadError = ref(null);
const existing = ref(null);
const kind = computed(() => existing.value?.kind ?? route.params.kind ?? 'quotation');
const currency = computed(() => setup.currency.value);
const quoteFields = computed(() => setup.fields('quote'));
const lineFields = computed(() => setup.fields('quote_line'));
const taxCodes = computed(() => setup.data.value?.tax_codes ?? []);
const inclusive = computed(() => existing.value?.prices_include_tax ?? setup.data.value?.quote_prices_include_tax ?? false);

const items = ref([]);
const units = ref({});
const contacts = ref([]);
const deals = ref([]);
const contactSearch = ref('');
const form = reactive({ contact_id: route.query.contact ?? '', deal_id: '', issue_date: '', valid_until: '', subject: '', notes: '', terms: '', extra: {}, lines: [] });
const errors = ref({});
const saving = ref(false);
const blankLine = () => ({ item_id: '', description: '', quantity: '1', unit: '', price: '', discount: '', tax_code_id: '', extra: {}, open: false });

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        if (setup.data.value?.inventory) {
            const base = `/api/organizations/${currentOrganization().id}/inventory`;
            const [list, unitList] = await Promise.all([api(`${base}/items`, { query: { active: 1 } }).catch(() => ({ data: [] })), api(`${base}/units`).catch(() => ({ data: [] }))]);
            items.value = list.data.filter((item) => item.is_active !== false);
            units.value = Object.fromEntries(unitList.data.map((unit) => [unit.id, unit.code]));
        }
        if (editingId.value) {
            const { data } = await crm.quote(editingId.value);
            existing.value = data;
            Object.assign(form, {
                contact_id: data.contact_id, deal_id: data.deal_id ?? '', issue_date: data.issue_date, valid_until: data.valid_until ?? '', subject: data.subject ?? '', notes: data.notes ?? '', terms: data.terms ?? '',
                extra: Object.fromEntries(quoteFields.value.map((field) => [field.key, fieldToInput(field, data.extra?.[field.key], currency.value)])),
                lines: data.lines.map((line) => ({
                    item_id: line.item_id ?? '', description: line.description, quantity: milliToText(line.quantity_milli), unit: line.unit ?? '', price: minorToText(line.unit_price_minor, currency.value),
                    discount: line.discount_minor ? minorToText(line.discount_minor, currency.value) : '', tax_code_id: line.tax_code_id ?? '',
                    extra: Object.fromEntries(lineFields.value.map((field) => [field.key, fieldToInput(field, line.extra?.[field.key], currency.value)])), open: false,
                })),
            });
            contacts.value = data.contact ? [data.contact] : [];
        } else {
            form.extra = Object.fromEntries(quoteFields.value.map((field) => [field.key, '']));
            form.lines = [blankLine()];
            if (form.contact_id) contacts.value = [(await crm.contact(form.contact_id)).data];
        }
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}
watch(() => setup.data.value, (value) => value && load(), { immediate: true });
watch(() => form.contact_id, async (id) => {
    deals.value = id ? (await crm.deals({ contact_id: id, status: 'open' }).catch(() => ({ data: [] }))).data : [];
}, { immediate: true });

async function findContacts() {
    if (contactSearch.value.trim().length < 2) return;
    contacts.value = (await crm.contacts({ q: contactSearch.value.trim() })).data;
}
function pickItem(line) {
    const item = items.value.find((row) => row.id === line.item_id);
    if (!item) return;
    line.description = `${item.sku} ${item.name}`;
    line.unit = units.value[item.unit_id] ?? line.unit;
    if (item.sale_price_minor !== null && item.sale_price_minor !== undefined) line.price = minorToText(item.sale_price_minor, currency.value);
    const code = taxCodes.value.find((row) => row.id === item.tax_code_id);
    if (code) line.tax_code_id = code.id;
}
const rate = (id) => taxCodes.value.find((row) => row.id === id)?.rate_bp ?? 0;
const preview = computed(() => priceQuote(form.lines.map((line) => ({
    quantity_milli: quantityToMilli(line.quantity) ?? 0, unit_price_minor: amountToMinor(line.price, currency.value) ?? 0,
    discount_minor: amountToMinor(line.discount, currency.value) ?? 0, tax_rate_bp: rate(line.tax_code_id),
})), inclusive.value));
const money = (amount) => formatMoney({ amount, currency: currency.value });

async function save() {
    const bad = {};
    const lines = form.lines.map((line, index) => {
        const quantity = quantityToMilli(line.quantity);
        const price = line.price.trim() === '' ? null : amountToMinor(line.price, currency.value);
        const discount = line.discount.trim() === '' ? 0 : amountToMinor(line.discount, currency.value);
        if (!quantity) bad[`lines.${index}.quantity_milli`] = [t('crm.validation.quantity')];
        if (line.price.trim() !== '' && price === null) bad[`lines.${index}.unit_price_minor`] = [t('crm.validation.amount')];
        if (discount === null) bad[`lines.${index}.discount_minor`] = [t('crm.validation.amount')];
        const extra = fieldsToApi(lineFields.value, line.extra, currency.value);
        Object.keys(extra.errors).forEach((key) => { bad[`lines.${index}.extra.${key}`] = [t('crm.validation.amount')]; });
        return {
            ...(line.item_id ? { item_id: line.item_id } : {}), description: line.description.trim() || null, quantity_milli: quantity, unit: line.unit.trim() || null,
            ...(price !== null ? { unit_price_minor: price } : {}), discount_minor: discount ?? 0, tax_code_id: line.tax_code_id || null,
            extra: Object.fromEntries(Object.entries(extra.values).filter(([, value]) => value !== null)),
        };
    });
    const extra = fieldsToApi(quoteFields.value, form.extra, currency.value);
    Object.keys(extra.errors).forEach((key) => { bad[`extra.${key}`] = [t('crm.validation.amount')]; });
    if (Object.keys(bad).length) {
        errors.value = bad;
        return;
    }
    const body = {
        contact_id: form.contact_id, deal_id: form.deal_id || null, issue_date: form.issue_date || undefined, valid_until: form.valid_until || null,
        subject: form.subject.trim() || null, notes: form.notes.trim() || null, terms: form.terms.trim() || null, lines,
        extra: existing.value ? extra.values : Object.fromEntries(Object.entries(extra.values).filter(([, value]) => value !== null)),
    };
    if (!body.issue_date) delete body.issue_date;
    saving.value = true;
    errors.value = {};
    try {
        const { data } = existing.value ? await crm.updateQuote(existing.value.id, { ...body, base_version: existing.value.version }) : await crm.createQuote({ ...body, kind: kind.value });
        toast.success(t('crm.quotes.saved', { number: data.number }));
        router.push({ name: 'crm-quote', params: { id: data.id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        else toast.error(t('crm.quotes.check'));
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <div>
        <AppButton variant="ghost" size="sm" :to="existing ? { name: 'crm-quote', params: { id: existing.id } } : { name: 'crm-quotes' }" :icon="ArrowLeft" class="mb-3">{{ t('crm.quotes.title') }}</AppButton>
        <SkeletonRows v-if="loading || setup.loading.value" :rows="8" />
        <ErrorState v-else-if="loadError || setup.error.value" :error="loadError ?? setup.error.value" @retry="load" />
        <form v-else id="crm-quote" novalidate @submit.prevent="save">
            <PageHeader :title="existing ? t('crm.quotes.edit', { number: existing.number }) : t(`crm.quotes.new_${kind}`)" :description="t(`crm.quotes.help_${kind}`)">
                <template #actions>
                    <AppButton variant="primary" type="submit" :loading="saving" :disabled="!form.contact_id || !form.lines.length">{{ t('crm.common.save') }}</AppButton>
                </template>
            </PageHeader>

            <section class="card mb-5 grid grid-cols-1 gap-4 p-5 sm:grid-cols-2 lg:grid-cols-4">
                <AppField v-slot="{ id }" :label="t('crm.quotes.customer')" :error="errors.contact_id?.[0]" class="sm:col-span-2">
                    <div class="grid gap-2 sm:grid-cols-2">
                        <input v-model="contactSearch" class="field-input" :placeholder="t('crm.contacts.search')" :aria-label="t('crm.contacts.search')" @input="findContacts" />
                        <select :id="id" v-model="form.contact_id" class="field-input">
                            <option value="" disabled>{{ t('crm.deals.choose_contact') }}</option>
                            <option v-for="contact in contacts" :key="contact.id" :value="contact.id">{{ contact.name }}{{ contact.company_name ? ` · ${contact.company_name}` : '' }}</option>
                        </select>
                    </div>
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.quotes.deal')" :error="errors.deal_id?.[0]" optional>
                    <select :id="id" v-model="form.deal_id" class="field-input">
                        <option value="">—</option>
                        <option v-for="deal in deals" :key="deal.id" :value="deal.id">{{ deal.title }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.quotes.subject')" :error="errors.subject?.[0]" optional>
                    <input :id="id" v-model="form.subject" class="field-input" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.quotes.issue_date')" :hint="t('crm.quotes.today_hint')" :error="errors.issue_date?.[0]" optional>
                    <input :id="id" v-model="form.issue_date" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.quotes.valid_until')" :hint="t('crm.quotes.valid_hint')" :error="errors.valid_until?.[0]" optional>
                    <input :id="id" v-model="form.valid_until" type="date" class="field-input" />
                </AppField>
                <div v-if="quoteFields.length" class="sm:col-span-2 lg:col-span-4">
                    <ExtraFields v-model="form.extra" :fields="quoteFields" :errors="errors" :currency="currency" />
                </div>
            </section>

            <!-- Lines -->
            <section class="card mb-5">
                <header class="flex items-center justify-between border-b border-line px-5 py-3">
                    <h2 class="text-[14px] font-semibold">{{ t('crm.quotes.lines') }}</h2>
                    <span class="text-[12px] text-muted">{{ t(inclusive ? 'crm.quotes.vat_inside' : 'crm.quotes.vat_on_top') }}</span>
                </header>
                <p v-if="errors.lines" class="px-5 pt-3 text-[12.5px] text-bad">{{ errors.lines[0] }}</p>
                <ul class="divide-y divide-line">
                    <li v-for="(line, index) in form.lines" :key="index" class="grid gap-3 px-5 py-3 md:grid-cols-[minmax(0,2.4fr)_5rem_5rem_7rem_6rem_8rem_7rem_auto] md:items-start">
                        <div class="grid gap-2">
                            <select v-if="items.length" v-model="line.item_id" class="field-input" :aria-label="t('crm.quotes.item')" @change="pickItem(line)">
                                <option value="">{{ t('crm.quotes.free_line') }}</option>
                                <option v-for="item in items" :key="item.id" :value="item.id">{{ item.sku }} · {{ item.name }}</option>
                            </select>
                            <input v-model="line.description" class="field-input" maxlength="255" :placeholder="t('crm.quotes.description')" :aria-label="t('crm.quotes.description')" />
                            <p v-if="errors[`lines.${index}.description`] || errors[`lines.${index}.item_id`]" class="text-[12px] text-bad">{{ (errors[`lines.${index}.description`] ?? errors[`lines.${index}.item_id`])[0] }}</p>
                        </div>
                        <input v-model="line.quantity" inputmode="decimal" class="field-input tabular text-end" dir="ltr" :aria-label="t('crm.quotes.quantity')" :placeholder="t('crm.quotes.quantity')" />
                        <input v-model="line.unit" class="field-input" maxlength="20" :aria-label="t('crm.quotes.unit')" :placeholder="t('crm.quotes.unit')" />
                        <input v-model="line.price" inputmode="decimal" class="field-input tabular text-end" dir="ltr" :aria-label="t('crm.quotes.price')" :placeholder="t('crm.quotes.price')" />
                        <input v-model="line.discount" inputmode="decimal" class="field-input tabular text-end" dir="ltr" :aria-label="t('crm.quotes.discount')" :placeholder="t('crm.quotes.discount')" />
                        <select v-model="line.tax_code_id" class="field-input" :aria-label="t('crm.quotes.vat')" :disabled="!taxCodes.length">
                            <option value="">{{ t('crm.quotes.no_vat') }}</option>
                            <option v-for="code in taxCodes" :key="code.id" :value="code.id">{{ code.name }}</option>
                        </select>
                        <span class="tabular self-center text-end text-[13.5px] font-semibold">{{ money(preview.lines[index]?.total ?? 0) }}</span>
                        <span class="flex items-center gap-1">
                            <AppButton v-if="lineFields.length" size="icon-sm" variant="ghost" :icon="ChevronDown" :aria-label="t('crm.quotes.line_more')" :aria-expanded="line.open" @click="line.open = !line.open" />
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('crm.quotes.remove_line')" :disabled="form.lines.length === 1" @click="form.lines.splice(index, 1)" />
                        </span>
                        <p v-for="key in ['quantity_milli', 'unit_price_minor', 'discount_minor', 'tax_code_id']" v-show="errors[`lines.${index}.${key}`]" :key="key" class="text-[12px] text-bad md:col-span-8">{{ errors[`lines.${index}.${key}`]?.[0] }}</p>
                        <div v-if="lineFields.length && (line.open || Object.keys(errors).some((key) => key.startsWith(`lines.${index}.extra`)))" class="rounded-xl bg-subtle p-3 md:col-span-8">
                            <ExtraFields v-model="line.extra" :fields="lineFields" :errors="errors" :prefix="`lines.${index}.extra`" :currency="currency" compact />
                        </div>
                    </li>
                </ul>
                <div class="flex flex-wrap items-start justify-between gap-4 border-t border-line px-5 py-4">
                    <AppButton size="sm" variant="secondary" :icon="Plus" :disabled="form.lines.length >= 200" @click="form.lines.push(blankLine())">{{ t('crm.quotes.add_line') }}</AppButton>
                    <dl class="grid min-w-60 grid-cols-[1fr_auto] gap-x-6 gap-y-1 text-[13.5px]">
                        <dt class="text-muted">{{ t('crm.quotes.subtotal') }}</dt><dd class="tabular text-end">{{ money(preview.subtotal) }}</dd>
                        <template v-if="preview.discount"><dt class="text-muted">{{ t('crm.quotes.discounts') }}</dt><dd class="tabular text-end">−{{ money(preview.discount) }}</dd></template>
                        <dt class="text-muted">{{ t('crm.quotes.vat') }}</dt><dd class="tabular text-end">{{ money(preview.tax) }}</dd>
                        <dt class="text-[15px] font-semibold">{{ t('crm.quotes.total') }}</dt><dd class="tabular text-end text-[15px] font-semibold">{{ money(preview.total) }}</dd>
                    </dl>
                </div>
            </section>

            <section class="card grid gap-4 p-5 sm:grid-cols-2">
                <AppField v-slot="{ id }" :label="t('crm.quotes.notes')" :error="errors.notes?.[0]" optional>
                    <textarea :id="id" v-model="form.notes" rows="4" class="field-input" maxlength="5000" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('crm.quotes.terms')" :hint="t('crm.quotes.terms_hint')" :error="errors.terms?.[0]" optional>
                    <textarea :id="id" v-model="form.terms" rows="4" class="field-input" maxlength="5000" />
                </AppField>
            </section>
        </form>
    </div>
</template>
