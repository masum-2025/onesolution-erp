<script setup>
import { computed, ref } from 'vue';
import { Ban, Scissors } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { newOpId } from '@/lib/billing';
import { formatDate, formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';
import { cutAllowed, piecesOf, sheetToLines } from '../lib';
import SizeColorSheet from './SizeColorSheet.vue';

/**
 * The cutting table for an order: cut against ordered for each size and
 * colour, every lay (numbered within the order), a new lay typed on the
 * same size × colour sheet, and a wrong lay voided with a reason (its
 * pieces stop counting). The first lay puts a confirmed order into
 * production, so the page reloads the order after it.
 */
const props = defineProps({
    order: { type: Object, required: true },
    style: { type: Object, required: true },
});
const emit = defineEmits(['changed']);
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const lays = useResource(() => api.cuttings(props.order.id));
const cut = computed(() => lays.data.value?.meta?.cut ?? {});
const ordered = computed(() => Object.fromEntries((props.order.lines ?? []).map((line) => [`${line.size}|${line.color}`, line.quantity])));
const running = computed(() => ['confirmed', 'in_production'].includes(props.order.status));
const canCut = computed(() => running.value && setup.can('produce'));
const allowanceBp = computed(() => setup.rules.value.over_cut_bp ?? 0);
const totalCut = computed(() => Object.values(cut.value).reduce((sum, value) => sum + value, 0));
const totalOrdered = computed(() => Object.values(ordered.value).reduce((sum, value) => sum + value, 0));
const percent = computed(() => (totalOrdered.value ? Math.min(100, Math.round((totalCut.value * 100) / totalOrdered.value)) : 0));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const cellTone = (key) => {
    const done = cut.value[key] ?? 0;
    if (done >= (ordered.value[key] ?? 0)) return 'text-ok font-semibold';
    return done > 0 ? 'text-fg' : 'text-muted';
};

// A new lay.
const making = ref(false);
const sheet = ref({});
const cutOn = ref('');
const note = ref('');
const busy = ref(false);
let opId = '';
function startLay() {
    sheet.value = {};
    cutOn.value = setup.data.value?.today ?? '';
    note.value = '';
    opId = newOpId();
    making.value = true;
}
const sheetBad = computed(() => Object.values(sheet.value).some((text) => piecesOf(text) === null));
const laySum = computed(() => sheetToLines(sheet.value).reduce((sum, line) => sum + line.quantity, 0));
// Cells typed above what may still be cut (the server checks again).
const tooMany = computed(() => sheetToLines(sheet.value).filter((line) => line.quantity > cutAllowed(ordered.value[`${line.size}|${line.color}`] ?? 0, allowanceBp.value) - (cut.value[`${line.size}|${line.color}`] ?? 0)));
async function saveLay() {
    busy.value = true;
    try {
        const made = await api.cut(props.order.id, { cut_on: cutOn.value, lines: sheetToLines(sheet.value).filter((line) => line.quantity > 0), ...(note.value.trim() ? { note: note.value.trim() } : {}), op_id: opId });
        toast.success(t('factory_erp.cutting.saved', { lay: formatNumber(made.data.lay_number) }));
        making.value = false;
        lays.reload();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

// Voiding a wrong lay.
const voiding = ref(null);
const reason = ref('');
async function voidLay() {
    busy.value = true;
    try {
        await api.voidCutting(props.order.id, voiding.value.id, { base_version: voiding.value.version, reason: reason.value.trim() });
        toast.success(t('factory_erp.cutting.voided', { lay: formatNumber(voiding.value.lay_number) }));
        voiding.value = null;
        lays.reload();
    } catch (error) {
        toast.error(error.code === 'version_conflict' ? t('factory_erp.conflict') : error.message);
        lays.reload();
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="space-y-5">
        <section class="card">
            <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                <div class="min-w-0 flex-1">
                    <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.cutting.title') }}</h2>
                    <p class="text-[12.5px] text-muted">{{ t('factory_erp.cutting.text', { cut: formatNumber(totalCut), ordered: formatNumber(totalOrdered), percent: formatNumber(percent) }) }}</p>
                </div>
                <AppButton v-if="canCut" size="sm" variant="primary" :icon="Scissors" @click="startLay">{{ t('factory_erp.cutting.new') }}</AppButton>
            </header>
            <div class="px-5 pt-3">
                <div class="h-2 w-full overflow-hidden rounded-full bg-subtle" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="t('factory_erp.cutting.title')">
                    <div class="h-full rounded-full bg-brand transition-all" :style="{ inlineSize: `${percent}%` }" />
                </div>
            </div>
            <p v-if="setup.can('produce') && order.status === 'draft'" class="px-5 pt-3 text-[12.5px] text-muted">{{ t('factory_erp.cutting.confirm_first') }}</p>
            <div class="overflow-x-auto p-3 sm:p-4">
                <table class="w-full min-w-max text-[13px]">
                    <thead>
                        <tr class="text-[12px] text-muted">
                            <th scope="col" class="px-2 py-2 text-start font-medium">{{ t('factory_erp.sheet.color') }}</th>
                            <th v-for="size in style.sizes" :key="size" scope="col" class="px-2 py-2 text-center font-semibold text-fg-2">{{ size }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="color in style.colors" :key="color">
                            <th scope="row" class="whitespace-nowrap px-2 py-1.5 text-start font-medium text-fg">{{ color }}</th>
                            <td v-for="size in style.sizes" :key="size" class="tabular px-2 py-1.5 text-center">
                                <template v-if="ordered[`${size}|${color}`] || cut[`${size}|${color}`]">
                                    <span :class="cellTone(`${size}|${color}`)">{{ formatNumber(cut[`${size}|${color}`] ?? 0) }}</span>
                                    <span class="text-[11.5px] text-muted"> / {{ formatNumber(ordered[`${size}|${color}`] ?? 0) }}</span>
                                </template>
                                <span v-else class="text-muted">·</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <header class="border-b border-line px-5 py-3">
                <h2 class="text-[14px] font-semibold text-fg">{{ t('factory_erp.cutting.lays') }}</h2>
            </header>
            <SkeletonRows v-if="lays.loading.value && !lays.data.value" :rows="2" />
            <ErrorState v-else-if="lays.error.value" compact :error="lays.error.value" @retry="lays.reload()" />
            <EmptyState v-else-if="!(lays.data.value?.data ?? []).length" compact :icon="Scissors" :title="t('factory_erp.cutting.empty_title')" :text="t('factory_erp.cutting.empty_text')" />
            <ul v-else class="divide-y divide-line">
                <li v-for="lay in lays.data.value.data" :key="lay.id" class="flex flex-wrap items-center gap-3 px-5 py-3" :class="lay.voided_at ? 'opacity-70' : ''">
                    <span class="tabular grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-[13px] font-bold text-fg">{{ formatNumber(lay.lay_number) }}</span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] font-medium text-fg" :class="lay.voided_at ? 'line-through' : ''">
                            {{ t('factory_erp.cutting.lay_pieces', { lay: formatNumber(lay.lay_number), pieces: formatNumber(lay.pieces) }) }}
                        </p>
                        <p class="text-[12px] text-muted">
                            {{ day(lay.cut_on) }} · {{ lay.lines.map((line) => `${line.size}/${line.color} ${formatNumber(line.quantity)}`).join(' · ') }}
                            <template v-if="lay.note"> · {{ lay.note }}</template>
                        </p>
                        <p v-if="lay.voided_at" class="text-[12px] text-bad">{{ t('factory_erp.cutting.voided_because', { reason: lay.void_reason }) }}</p>
                    </div>
                    <AppBadge v-if="lay.voided_at" tone="bad">{{ t('factory_erp.cutting.void_badge') }}</AppBadge>
                    <AppButton v-else-if="canCut" size="sm" variant="ghost" :icon="Ban" @click="voiding = lay; reason = ''">{{ t('factory_erp.cutting.void') }}</AppButton>
                </li>
            </ul>
        </section>

        <AppDialog :open="making" :icon="Scissors" :title="t('factory_erp.cutting.new_title', { number: order.number })" :description="t('factory_erp.cutting.new_text', { percent: formatNumber(allowanceBp / 100) })" @close="making = false">
            <AppField v-slot="{ id }" :label="t('factory_erp.cutting.day')">
                <input :id="id" v-model="cutOn" type="date" class="field-input sm:w-48" :max="setup.data.value?.today" />
            </AppField>
            <div class="mt-3 rounded-xl border border-line p-2">
                <SizeColorSheet v-model="sheet" :sizes="style.sizes" :colors="style.colors" />
            </div>
            <p v-if="tooMany.length" class="mt-2 text-[12.5px] text-bad" role="alert">
                {{ t('factory_erp.cutting.too_many', { cells: tooMany.map((line) => `${line.size}/${line.color}`).join(', ') }) }}
            </p>
            <AppField v-slot="{ id }" class="mt-3" :label="t('factory_erp.issues.note')">
                <input :id="id" v-model="note" class="field-input" maxlength="300" :placeholder="t('factory_erp.cutting.note_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="making = false">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="sheetBad || laySum === 0 || tooMany.length > 0 || !cutOn" @click="saveLay">
                    {{ t('factory_erp.cutting.save', { pieces: formatNumber(laySum) }) }}
                </AppButton>
            </template>
        </AppDialog>

        <AppDialog :open="voiding !== null" tone="bad" confirm :icon="Ban" :title="t('factory_erp.cutting.void_title', { lay: formatNumber(voiding?.lay_number ?? 0) })" :description="t('factory_erp.cutting.void_text')" @close="voiding = null">
            <AppField v-slot="{ id }" :label="t('factory_erp.steps.reason')">
                <input :id="id" v-model="reason" class="field-input" maxlength="300" :placeholder="t('factory_erp.cutting.void_hint')" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="voiding = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="busy" :disabled="reason.trim().length < 3" @click="voidLay">{{ t('factory_erp.cutting.void') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
