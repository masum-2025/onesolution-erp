<script setup>
import { computed, reactive, ref, useId, watch } from 'vue';
import { CalendarClock, Check, Copy, Eye, Hourglass, Info, Lock, RotateCcw, Ruler, ShieldAlert, TriangleAlert, X } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDrawer from '@/components/AppDrawer.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppTabs from '@/components/AppTabs.vue';
import ErrorState from '@/components/ErrorState.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import BoundsEditor from './BoundsEditor.vue';
import RuleHistory from './RuleHistory.vue';
import RuleTrace from './RuleTrace.vue';
import RuleValueInput from './RuleValueInput.vue';
import RuleValueTable from './RuleValueTable.vue';
import { useResource } from '@/lib/useResource';
import { formatBounds, formatRuleValue, supportsBounds } from '@/lib/ruleValues';
import { formatDateTime } from '@/lib/format';
import { confirmAction } from '@/lib/dialogs';
import { emit as emitEvent } from '@/lib/events';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

const props = defineProps({
    open: Boolean,
    ruleKey: { type: String, default: null },
    adapter: { type: Object, required: true },
    scopeName: { type: String, default: '' },
});

const emit = defineEmits(['close', 'changed']);

const titleId = useId();
const rule = useResource(() => props.adapter.show(props.ruleKey), { immediate: false, keepData: false });
const data = computed(() => rule.data.value);

const tab = ref('change');
const copied = ref(false);
const historyRef = ref(null);

watch(
    () => [props.open, props.ruleKey],
    ([open, key]) => {
        if (!open || !key) return;
        tab.value = 'change';
        rule.reload();
    },
    { immediate: true },
);

const canEdit = computed(() => props.adapter.canEdit());

const tabs = computed(() =>
    [
        { key: 'change', label: canEdit.value ? t('rules.drawer.tabs.change') : t('rules.drawer.tabs.details') },
        props.adapter.supports.history && { key: 'history', label: t('rules.drawer.tabs.history') },
        props.adapter.supports.trace && { key: 'trace', label: t('rules.drawer.tabs.trace') },
    ].filter(Boolean),
);

const source = computed(() => {
    const value = data.value;
    if (!value) return { kind: 'default' };
    if (value.own.value) return { kind: 'self' };
    if (!value.source) return { kind: 'default' };
    return { kind: 'inherited', name: value.source.name, level: value.source.level };
});

// ---- change form ---------------------------------------------------------
const form = reactive({ mode: 'set', value: null, bounds: undefined, country: '', schedule: false, when: '', reason: '' });
const errors = reactive({ reason: null, value: null, when: null, country: null, form: null });
const saving = ref(false);
const preview = ref(null);
const previewing = ref(false);

const modes = computed(() => {
    const list = [
        { value: 'set', label: t('rules.modes.set') },
        { value: 'lock', label: t('rules.modes.lock') },
    ];
    if (data.value && supportsBounds(data.value.type)) list.push({ value: 'constrain', label: t('rules.modes.constrain') });
    return list;
});

function resetForm() {
    const value = data.value;
    if (!value) return;
    const ownValue = value.own.value;
    Object.assign(form, {
        mode: ownValue?.mode === 'lock' ? 'lock' : 'set',
        value: ownValue ? ownValue.value : value.value,
        bounds: value.own.constraint?.value ?? undefined,
        country: '',
        schedule: false,
        when: '',
        reason: '',
    });
    Object.assign(errors, { reason: null, value: null, when: null, country: null, form: null });
    preview.value = null;
}

watch(data, resetForm);
watch(() => form.mode, () => (preview.value = null));

const payloadValue = computed(() => (form.mode === 'constrain' ? form.bounds : form.value));

function body() {
    const result = { mode: form.mode, value: payloadValue.value, reason: form.reason.trim() };
    if (data.value.country_specific && form.country.trim()) result.country_code = form.country.trim().toUpperCase();
    if (form.schedule && form.when) result.effective_from = new Date(form.when).toISOString();
    return result;
}

function validate({ needReason = true } = {}) {
    Object.assign(errors, { reason: null, value: null, when: null, country: null, form: null });
    if (payloadValue.value === undefined) errors.value = form.mode === 'constrain' ? t('rules.drawer.bounds_invalid') : t('rules.drawer.value_invalid');
    if (needReason && form.reason.trim().length < 5) errors.reason = t('core.confirm.reason_short');
    if (form.schedule && (!form.when || new Date(form.when).getTime() <= Date.now())) errors.when = t('rules.drawer.when_invalid');
    if (form.country.trim() && !/^[A-Za-z]{2}$/.test(form.country.trim())) errors.country = t('rules.drawer.country_invalid');
    return !errors.value && !errors.reason && !errors.when && !errors.country;
}

async function runPreview() {
    if (!validate({ needReason: false })) return;
    previewing.value = true;
    try {
        const { reason, effective_from, ...rest } = body();
        preview.value = await props.adapter.preview(data.value.key, rest);
    } catch (error) {
        errors.form = error.message;
    } finally {
        previewing.value = false;
    }
}

async function save() {
    if (!validate()) return;
    saving.value = true;
    const slot = form.mode === 'constrain' ? 'constraint' : 'value';
    const hadOwn = !!(slot === 'value' ? data.value.own.value : data.value.own.constraint);
    const payload = body();
    try {
        const response = await props.adapter.set(data.value.key, payload);
        const pending = response.data?.status === 'pending_approval';
        const immediate = !pending && !payload.effective_from;

        const undo = immediate && !hadOwn
            ? {
                  action: {
                      label: t('core.actions.undo'),
                      run: async () => {
                          try {
                              await props.adapter.reset(data.value.key, { reason: t('rules.undo_reason'), slot, ...(payload.country_code ? { country_code: payload.country_code } : {}) });
                              toast.success(t('rules.messages.undone'));
                              await rule.reload();
                              emit('changed');
                          } catch (error) {
                              toast.error(error.message);
                          }
                      },
                  },
              }
            : undefined;

        (pending ? toast.info : toast.success)(response.message ?? t('rules.messages.saved'), undo);
        if (pending) emitEvent('approvals-changed');
        await rule.reload();
        historyRef.value?.reload();
        emit('changed');
    } catch (error) {
        if (error.status === 422 && Object.keys(error.errors ?? {}).length) {
            errors.reason = error.field('reason');
            errors.when = error.field('effective_from');
            errors.country = error.field('country_code');
            errors.form = error.field('value') ?? error.field('mode') ?? (errors.reason || errors.when || errors.country ? null : error.message);
        } else {
            errors.form = error.message;
        }
    } finally {
        saving.value = false;
    }
}

async function resetSlot(slot) {
    const answer = await confirmAction({
        title: slot === 'constraint' ? t('rules.reset.limits_title') : t('rules.reset.value_title'),
        message: t('rules.reset.text', { rule: data.value.label, org: props.scopeName }),
        reason: 'required',
        danger: true,
        confirmLabel: t('rules.reset.submit'),
    });
    if (!answer) return;
    try {
        const response = await props.adapter.reset(data.value.key, { reason: answer.reason, slot });
        toast.success(response.message ?? t('rules.messages.reset'));
        await rule.reload();
        historyRef.value?.reload();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    }
}

async function rollback(entry) {
    const answer = await confirmAction({
        title: t('rules.history.restore_title', { version: entry.snapshot.version }),
        message: t('rules.history.restore_text', { value: formatRuleValue(data.value, entry.snapshot.value) }),
        reason: 'required',
        confirmLabel: t('rules.history.restore'),
    });
    if (!answer) return;
    try {
        const response = await props.adapter.rollback(data.value.key, { version: entry.snapshot.version, reason: answer.reason });
        const pending = response.data?.status === 'pending_approval';
        (pending ? toast.info : toast.success)(response.message);
        if (pending) emitEvent('approvals-changed');
        await rule.reload();
        historyRef.value?.reload();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    }
}

async function review(row, approve) {
    const answer = await confirmAction({
        title: approve ? t('rules.approvals.approve_title') : t('rules.approvals.reject_title'),
        message: t('rules.approvals.review_text', { rule: data.value.label, value: formatRuleValue(data.value, row.value) }),
        reason: approve ? 'optional' : 'required',
        danger: !approve,
        confirmLabel: approve ? t('rules.approvals.approve') : t('rules.approvals.reject'),
    });
    if (!answer) return;
    try {
        await (approve ? props.adapter.approve : props.adapter.reject)(row.id, answer.reason ? { reason: answer.reason } : {});
        toast.success(approve ? t('rules.approvals.approved') : t('rules.approvals.rejected'));
        await rule.reload();
        emit('changed');
    } catch (error) {
        toast.error(error.message);
    }
}

async function copyKey() {
    try {
        await navigator.clipboard.writeText(data.value.key);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        // Clipboard blocked: the key stays visible to select by hand.
    }
}

const minDateTime = computed(() => {
    const now = new Date(Date.now() + 60000);
    now.setSeconds(0, 0);
    return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
});
</script>

<template>
    <AppDrawer :open="open" :labelledby="titleId" @close="emit('close')">
        <template #header>
            <header class="border-b border-line px-5 pt-4 pb-4 sm:px-6">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p v-if="data" class="text-[12px] font-medium text-muted">{{ data.module_name }} · {{ data.category_label }}</p>
                        <div v-else class="skeleton h-3 w-32" />
                        <h2 :id="titleId" class="mt-1 text-[17px] leading-snug font-semibold tracking-[-0.01em] text-fg">
                            <span v-if="data">{{ data.label }}</span>
                            <span v-else class="skeleton inline-block h-5 w-56" />
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="-me-1.5 grid size-8 shrink-0 place-items-center rounded-lg text-muted transition hover:bg-subtle hover:text-fg"
                        :aria-label="t('core.actions.close')"
                        @click="emit('close')"
                    >
                        <X class="size-4" aria-hidden="true" />
                    </button>
                </div>
                <button
                    v-if="data"
                    type="button"
                    class="mt-2 inline-flex items-center gap-1.5 rounded-md bg-subtle px-1.5 py-0.5 font-mono text-[11.5px] text-muted transition hover:text-fg"
                    :aria-label="t('rules.drawer.copy_key')"
                    @click="copyKey"
                >
                    {{ data.key }}
                    <component :is="copied ? Check : Copy" class="size-3" aria-hidden="true" />
                </button>
            </header>
        </template>

        <ErrorState v-if="rule.error.value" :error="rule.error.value" @retry="rule.reload()" />

        <div v-else-if="!data" class="space-y-4 p-6" role="status" :aria-label="t('core.states.loading')">
            <div class="skeleton h-24 w-full rounded-2xl" />
            <div class="skeleton h-4 w-2/3" />
            <div class="skeleton h-40 w-full rounded-2xl" />
        </div>

        <template v-else>
            <div class="px-5 pt-5 sm:px-6">
                <p class="text-[13px] leading-relaxed text-fg-2">{{ data.description }}</p>

                <div class="mt-4 rounded-2xl border border-line bg-gradient-to-b from-subtle/70 to-surface p-4">
                    <p class="text-[12px] font-medium text-muted">{{ t('rules.drawer.effective', { org: scopeName }) }}</p>
                    <RuleValueTable v-if="data.type === 'table' && Array.isArray(data.value) && data.value.length" class="mt-2" :rule="data" :rows="data.value" />
                    <p v-else class="tabular mt-1 text-[24px] leading-tight font-semibold tracking-[-0.02em] break-words text-fg">{{ formatRuleValue(data, data.value) }}</p>
                    <pre v-if="data.type === 'json'" class="mt-3 max-h-56 overflow-auto rounded-lg bg-surface p-3 font-mono text-[12px] text-fg-2 ring-1 ring-line">{{ JSON.stringify(data.value, null, 2) }}</pre>
                    <div class="mt-3 flex flex-wrap gap-1.5">
                        <SourceBadge v-bind="source" />
                        <AppBadge v-if="data.locked_by" tone="neutral" :icon="Lock">{{ t('rules.locked_by', { name: data.locked_by.name ?? '' }) }}</AppBadge>
                        <AppBadge v-if="data.locked_here" tone="brand" :icon="Lock">{{ t('rules.row.locked_here') }}</AppBadge>
                        <AppBadge v-if="data.constraints" tone="outline" :icon="Ruler">{{ t('rules.drawer.limits_from_above', { limits: formatBounds(data, data.constraints) }) }}</AppBadge>
                    </div>
                    <p v-if="data.fell_back" class="mt-3 flex items-start gap-2 text-[12.5px] text-warn">
                        <TriangleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                        {{ t('rules.drawer.fell_back') }}
                    </p>
                </div>

                <div v-if="data.own.pending_approval.length" class="mt-3 space-y-2">
                    <div v-for="row in data.own.pending_approval" :key="row.id" class="rounded-xl border border-warn/25 bg-warn-soft p-3.5">
                        <p class="flex items-center gap-2 text-[13px] font-medium text-fg">
                            <Hourglass class="size-4 text-warn" aria-hidden="true" />
                            {{ t('rules.drawer.pending', { value: row.mode === 'constrain' ? formatBounds(data, row.value) : formatRuleValue(data, row.value) }) }}
                        </p>
                        <p v-if="row.reason" class="mt-1 ps-6 text-[12.5px] text-fg-2">“{{ row.reason }}”</p>
                        <div v-if="canEdit && adapter.level === 'partner'" class="mt-2.5 flex gap-2 ps-6">
                            <AppButton size="sm" variant="primary" @click="review(row, true)">{{ t('rules.approvals.approve') }}</AppButton>
                            <AppButton size="sm" variant="danger-soft" @click="review(row, false)">{{ t('rules.approvals.reject') }}</AppButton>
                        </div>
                        <RouterLink v-else-if="canEdit" to="/approvals" class="mt-1.5 inline-block ps-6 text-[12.5px] font-medium text-brand-text hover:underline">
                            {{ t('rules.drawer.review_link') }}
                        </RouterLink>
                    </div>
                </div>

                <div v-for="row in data.own.scheduled" :key="row.id" class="mt-3 flex items-start gap-2 rounded-xl border border-brand/20 bg-brand-soft p-3.5 text-[13px] text-fg-2">
                    <CalendarClock class="mt-0.5 size-4 shrink-0 text-brand-text" aria-hidden="true" />
                    {{ t('rules.drawer.scheduled', { value: formatRuleValue(data, row.value), date: formatDateTime(row.effective_from) }) }}
                </div>
            </div>

            <div class="mt-5 px-5 sm:px-6">
                <AppTabs v-model="tab" :tabs="tabs" :label="data.label" />
            </div>

            <!-- Change -->
            <div v-if="tab === 'change'" class="px-5 py-5 sm:px-6">
                <div v-if="!canEdit" class="flex items-start gap-2.5 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                    <Info class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                    {{ t('rules.drawer.no_permission') }}
                </div>
                <div v-else-if="!data.editable" class="flex items-start gap-2.5 rounded-xl bg-subtle p-3.5 text-[13px] text-fg-2">
                    <Lock class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                    {{ t(`rules.blocked.${data.edit_blocked_by}`, { name: data.locked_by?.name ?? '' }) }}
                </div>

                <form v-else class="space-y-5" novalidate @submit.prevent="save">
                    <div v-if="data.requires_approval" class="flex items-start gap-2.5 rounded-xl border border-warn/25 bg-warn-soft p-3.5 text-[13px] text-fg-2">
                        <ShieldAlert class="mt-0.5 size-4 shrink-0 text-warn" aria-hidden="true" />
                        {{ t('rules.drawer.needs_approval') }}
                    </div>

                    <div>
                        <AppSegmented v-model="form.mode" :options="modes" :label="t('rules.drawer.mode')" block />
                        <p class="mt-2 text-[12.5px] leading-relaxed text-muted">{{ t(`rules.modes.${form.mode}_hint`) }}</p>
                    </div>

                    <AppField :label="form.mode === 'constrain' ? t('rules.drawer.limits') : t('rules.drawer.value')" :error="errors.value">
                        <template #default="{ id, describedby }">
                            <BoundsEditor v-if="form.mode === 'constrain'" :key="`bounds-${data.key}`" v-model="form.bounds" :rule="data" />
                            <RuleValueInput v-else :id="id" :key="`value-${data.key}-${form.mode}`" v-model="form.value" :rule="data" :describedby="describedby" />
                        </template>
                    </AppField>

                    <AppField v-if="data.country_specific" :label="t('rules.drawer.country')" :hint="t('rules.drawer.country_hint')" :error="errors.country" optional>
                        <template #default="{ id, invalid, describedby }">
                            <input :id="id" v-model="form.country" maxlength="2" class="field-input w-28 uppercase" placeholder="BD" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>

                    <div>
                        <label class="flex items-center gap-2.5 text-[13.5px] text-fg">
                            <input v-model="form.schedule" type="checkbox" class="size-4 rounded accent-brand" />
                            {{ t('rules.drawer.schedule') }}
                        </label>
                        <AppField v-if="form.schedule" class="mt-3" :label="t('rules.drawer.starts')" :error="errors.when" :hint="t('rules.drawer.starts_hint')">
                            <template #default="{ id, invalid, describedby }">
                                <input :id="id" v-model="form.when" type="datetime-local" :min="minDateTime" class="field-input max-w-64" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                            </template>
                        </AppField>
                    </div>

                    <AppField :label="t('core.confirm.reason')" :hint="t('core.confirm.reason_hint')" :error="errors.reason">
                        <template #default="{ id, invalid, describedby }">
                            <textarea :id="id" v-model="form.reason" rows="2" maxlength="500" class="field-input" :placeholder="t('rules.drawer.reason_placeholder')" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </template>
                    </AppField>

                    <p v-if="errors.form" class="flex items-start gap-2 rounded-xl border border-bad/20 bg-bad-soft px-3.5 py-3 text-[13px] text-fg-2" role="alert">
                        <TriangleAlert class="mt-0.5 size-4 shrink-0 text-bad" aria-hidden="true" />
                        {{ errors.form }}
                    </p>

                    <div v-if="preview" class="rounded-xl border border-line" aria-live="polite">
                        <p class="border-b border-line px-3.5 py-2.5 text-[13px] font-medium text-fg">
                            {{ preview.length ? t('rules.preview.changes', { count: preview.length }) : t('rules.preview.none') }}
                        </p>
                        <ul v-if="preview.length" class="max-h-48 divide-y divide-line overflow-y-auto">
                            <li v-for="item in preview" :key="item.organization_id" class="flex flex-wrap items-center gap-2 px-3.5 py-2 text-[12.5px]">
                                <span class="min-w-0 flex-1 truncate font-medium text-fg">{{ item.name }}</span>
                                <span class="rounded bg-bad-soft px-1.5 text-bad line-through decoration-bad/40">{{ formatRuleValue(data, item.from) }}</span>
                                <span class="text-faint" aria-hidden="true">→</span>
                                <span class="rounded bg-ok-soft px-1.5 font-medium text-ok">{{ formatRuleValue(data, item.to) }}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <AppButton v-if="adapter.supports.preview" :icon="Eye" :loading="previewing" @click="runPreview">{{ t('rules.preview.button') }}</AppButton>
                        <AppButton type="submit" variant="primary" :loading="saving">
                            {{ data.requires_approval ? t('rules.drawer.submit_for_approval') : form.schedule ? t('rules.drawer.schedule_submit') : t('rules.drawer.save') }}
                        </AppButton>
                    </div>

                    <div v-if="data.own.value || data.own.constraint" class="rounded-xl border border-line p-4">
                        <p class="text-[13.5px] font-medium text-fg">{{ t('rules.reset.title') }}</p>
                        <p class="mt-1 text-[12.5px] text-muted">{{ t('rules.reset.hint') }}</p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <AppButton v-if="data.own.value" size="sm" variant="danger-soft" :icon="RotateCcw" @click="resetSlot('value')">{{ t('rules.reset.value') }}</AppButton>
                            <AppButton v-if="data.own.constraint" size="sm" variant="danger-soft" :icon="RotateCcw" @click="resetSlot('constraint')">{{ t('rules.reset.limits') }}</AppButton>
                        </div>
                    </div>
                </form>
            </div>

            <RuleHistory
                v-else-if="tab === 'history'"
                ref="historyRef"
                :rule="data"
                :adapter="adapter"
                :can-rollback="canEdit && data.editable && adapter.supports.rollback"
                @rollback="rollback"
            />
            <RuleTrace v-else-if="tab === 'trace'" :rule="data" />
        </template>
    </AppDrawer>
</template>
