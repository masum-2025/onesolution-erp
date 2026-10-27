<script setup>
import { reactive, ref, watch } from 'vue';
import { CalendarClock } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { nextSlot } from '../lib';

/**
 * An interview: when (the company's own time), how long, in person, by
 * phone or video, where (a room or a link), and who interviews (people who
 * may interview or manage recruitment where the job is). The interviewers
 * are told; the candidate too, unless switched off.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    application: { type: Object, default: null },
    calls: { type: Object, required: true },
});
const emit = defineEmits(['close', 'scheduled']);

const MODES = ['in_person', 'phone', 'video'];
const form = reactive({ starts_at: '', minutes: 45, mode: 'in_person', place: '', interviewer_ids: [], tell_candidate: true });
const people = ref([]);
const errors = ref({});
const saving = ref(false);

watch(() => props.open, async (open) => {
    if (!open || !props.application) return;
    Object.assign(form, { starts_at: nextSlot(), minutes: 45, mode: 'in_person', place: '', interviewer_ids: [], tell_candidate: true });
    errors.value = {};
    try {
        people.value = (await props.calls.interviewers(props.application.id)).data ?? [];
    } catch (error) {
        people.value = [];
        toast.error(error.message);
    }
});

function toggle(id) {
    form.interviewer_ids = form.interviewer_ids.includes(id) ? form.interviewer_ids.filter((item) => item !== id) : [...form.interviewer_ids, id];
}

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const response = await props.calls.schedule(props.application.id, { ...form, minutes: Number(form.minutes), place: form.place.trim() || null });
        toast.success(t('recruitment.interview.scheduled'));
        emit('scheduled', response.data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('recruitment.interview.title')" :description="application ? t('recruitment.interview.text', { name: application.name }) : ''" :icon="CalendarClock" @close="emit('close')">
        <form id="recruitment-interview" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
            <AppField v-slot="{ id }" :label="t('recruitment.interview.when')" :hint="t('recruitment.interview.when_hint')" :error="fieldError('starts_at')">
                <input :id="id" v-model="form.starts_at" type="datetime-local" class="field-input" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.interview.minutes')" :error="fieldError('minutes')">
                <input :id="id" v-model.number="form.minutes" type="number" min="10" max="480" step="5" class="field-input tabular text-end" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('recruitment.interview.mode')" :error="fieldError('mode')">
                <select :id="id" v-model="form.mode" class="field-input">
                    <option v-for="mode in MODES" :key="mode" :value="mode">{{ t(`recruitment.modes.${mode}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t(form.mode === 'video' ? 'recruitment.interview.link' : 'recruitment.interview.place')" :error="fieldError('place')" optional>
                <input :id="id" v-model="form.place" class="field-input" maxlength="300" :dir="form.mode === 'video' ? 'ltr' : undefined" />
            </AppField>
            <fieldset class="sm:col-span-2">
                <legend class="mb-2 text-[13px] font-medium text-fg-2">{{ t('recruitment.interview.who') }}</legend>
                <p v-if="!people.length" class="text-[12.5px] text-muted">{{ t('recruitment.interview.nobody') }}</p>
                <div v-else class="flex flex-wrap gap-2">
                    <label v-for="person in people" :key="person.id" class="inline-flex cursor-pointer items-center gap-2 rounded-lg border px-3 py-1.5 text-[13.5px]" :class="form.interviewer_ids.includes(person.id) ? 'border-brand bg-brand/10' : 'border-line'">
                        <input type="checkbox" class="size-4 accent-[var(--brand)]" :checked="form.interviewer_ids.includes(person.id)" @change="toggle(person.id)" />
                        {{ person.name }}
                    </label>
                </div>
                <p v-if="fieldError('interviewer_ids')" class="mt-1 text-[12.5px] text-bad" role="alert">{{ fieldError('interviewer_ids') }}</p>
            </fieldset>
            <div class="sm:col-span-2">
                <AppSwitch v-model="form.tell_candidate" :label="t('recruitment.interview.tell')" show-label />
            </div>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('recruitment.common.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="recruitment-interview" :loading="saving" :disabled="!form.starts_at || !form.interviewer_ids.length">{{ t('recruitment.interview.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
