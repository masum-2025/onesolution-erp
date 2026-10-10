<script setup>
import { computed, reactive, ref } from 'vue';
import { CalendarClock, PenLine } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits, windowState } from '../lib';

/**
 * The session the screens show (shared between them), its registration
 * window in words (not open yet, open, add/drop only, closed) and the
 * rules in one line; people who manage set or change the window.
 */
const emit = defineEmits(['changed']);
const setup = useRegistrationSetup();
const education = registrationApi(currentOrganization().id);

const sessions = computed(() => setup.data.value?.sessions ?? []);
const state = computed(() => windowState(setup.window.value, setup.data.value?.today ?? ''));
const tone = { none: 'neutral', upcoming: 'brand', open: 'ok', add_drop: 'warn', closed: 'outline' };
const rules = computed(() => {
    const value = setup.rules.value;
    const parts = [];
    parts.push(value.max_credits_centi ? t('course_registration.rules_line.credits', { min: credits(value.min_credits_centi ?? 0), max: credits(value.max_credits_centi) }) : t('course_registration.rules_line.no_max'));
    if (value.max_credits_centi && value.overload_credits_centi) parts.push(t('course_registration.rules_line.overload', { count: credits(value.overload_credits_centi) }));
    parts.push(t(`course_registration.rules_line.${value.approval_required ? 'approval' : 'no_approval'}`));
    parts.push(t(`course_registration.rules_line.${value.self_registration ? 'self' : 'staff_only'}`));
    return parts.join(' · ');
});

async function choose(event) {
    await setup.choose(event.target.value);
    emit('changed');
}

// Setting the window.
const editing = ref(false);
const form = reactive({ opens_on: '', closes_on: '', add_drop_until: '' });
const errors = ref({});
const saving = ref(false);
function edit() {
    const window = setup.window.value;
    const session = setup.session.value;
    Object.assign(form, {
        opens_on: window?.opens_on ?? setup.data.value?.today ?? '',
        closes_on: window?.closes_on ?? '',
        add_drop_until: window?.add_drop_until ?? '',
    });
    if (!form.closes_on && session) form.closes_on = session.starts_on > form.opens_on ? session.starts_on : form.opens_on;
    errors.value = {};
    editing.value = true;
}
async function save() {
    saving.value = true;
    errors.value = {};
    try {
        await education.saveWindow(setup.sessionId.value, { ...form, ...(setup.window.value ? { base_version: setup.window.value.version } : {}) });
        toast.success(t('course_registration.window.saved'));
        editing.value = false;
        await setup.reload();
        emit('changed');
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('course_registration.conflict'));
            editing.value = false;
            setup.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <section class="card mb-5 flex flex-col gap-3 p-4 sm:flex-row sm:items-center">
        <label class="flex items-center gap-2 sm:w-64">
            <span class="sr-only">{{ t('course_registration.session') }}</span>
            <select class="field-input" :value="setup.sessionId.value" :disabled="!sessions.length" @change="choose">
                <option v-if="!sessions.length" value="">{{ t('course_registration.no_sessions') }}</option>
                <option v-for="session in sessions" :key="session.id" :value="session.id">{{ textIn(session.name) }}</option>
            </select>
        </label>
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-fg-2" aria-hidden="true"><CalendarClock class="size-[18px]" /></span>
            <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                    {{ t('course_registration.window.title') }}
                    <AppBadge :tone="tone[state]" dot>
                        {{ state === 'none' ? t('course_registration.window.none_short') : t(`course_registration.window.${state}`, {
                            opens: formatDate(setup.window.value?.opens_on), closes: formatDate(setup.window.value?.closes_on), until: formatDate(setup.window.value?.add_drop_until),
                        }) }}
                    </AppBadge>
                </p>
                <p class="mt-0.5 sm:truncate text-[12.5px] text-muted">
                    <template v-if="setup.window.value">{{ t('course_registration.window.dates', { opens: formatDate(setup.window.value.opens_on), closes: formatDate(setup.window.value.closes_on), until: formatDate(setup.window.value.add_drop_until) }) }} · </template>
                    <template v-else-if="state === 'none'">{{ t('course_registration.window.none') }} · </template>
                    {{ rules }}
                </p>
            </div>
        </div>
        <AppButton v-if="setup.can('manage') && setup.sessionId.value" size="sm" :icon="PenLine" @click="edit">{{ setup.window.value ? t('course_registration.window.change') : t('course_registration.window.set') }}</AppButton>
    </section>

    <AppDialog :open="editing" :title="t('course_registration.window.title')" :description="setup.sessionName(setup.sessionId.value)" :icon="CalendarClock" @close="editing = false">
        <form id="crs-window" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('course_registration.window.opens_on')" :error="errors.opens_on?.[0] ?? null">
                <input :id="id" v-model="form.opens_on" type="date" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('course_registration.window.closes_on')" :error="errors.closes_on?.[0] ?? null">
                <input :id="id" v-model="form.closes_on" type="date" class="field-input" :min="form.opens_on" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('course_registration.window.add_drop_until')" :hint="t('course_registration.window.add_drop_hint')" :error="errors.add_drop_until?.[0] ?? null" class="sm:col-span-2">
                <input :id="id" v-model="form.add_drop_until" type="date" class="field-input sm:w-56" :min="form.opens_on" :max="setup.session.value?.ends_on" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="editing = false">{{ t('course_registration.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crs-window" :loading="saving" :disabled="!form.opens_on || !form.closes_on || !form.add_drop_until">{{ t('course_registration.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
