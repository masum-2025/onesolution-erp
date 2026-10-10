<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ListChecks } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { useEducationSetup } from '../setup';

/**
 * A promotion list for one class (or one section of it) from a session to
 * the next session of the same kind. Everyone studying there now is on it,
 * promoted unless someone decides otherwise.
 */
const props = defineProps({
    open: Boolean,
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'made']);

const setup = useEducationSetup();
const form = reactive({ from_session_id: '', to_session_id: '', level_id: '', section_id: '', note: '' });
const sections = ref([]);
const errors = ref({});
const saving = ref(false);

const sessions = computed(() => setup.data.value?.sessions ?? []);
const from = computed(() => setup.session(form.from_session_id));
// The next session: the same kind, starting after the one it comes from.
const targets = computed(() => sessions.value.filter((session) => from.value && session.kind === from.value.kind && session.id !== from.value.id && session.starts_on > from.value.starts_on));
const levels = computed(() =>
    (setup.data.value?.levels ?? [])
        .filter((level) => level.is_active && from.value && setup.program(level.program_id)?.progression === from.value.kind)
        .sort((a, b) => setup.rank(a.id) - setup.rank(b.id)),
);

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        const current = sessions.value.find((session) => session.status === 'open') ?? sessions.value[0];
        Object.assign(form, { from_session_id: current?.id ?? '', to_session_id: '', level_id: '', section_id: '', note: '' });
        errors.value = {};
    },
);
watch(() => form.from_session_id, () => {
    form.to_session_id = [...targets.value].sort((a, b) => a.starts_on.localeCompare(b.starts_on))[0]?.id ?? '';
    if (!levels.value.some((level) => level.id === form.level_id)) form.level_id = '';
});
watch(() => [form.from_session_id, form.level_id], async ([sessionId, levelId]) => {
    form.section_id = '';
    sections.value = [];
    if (!sessionId || !levelId) return;
    try {
        sections.value = (await props.education.list('sections', { session_id: sessionId, level_id: levelId })).data;
    } catch {
        sections.value = [];
    }
});

async function submit() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await props.education.createPromotion({
            from_session_id: form.from_session_id,
            to_session_id: form.to_session_id,
            level_id: form.level_id,
            section_id: form.section_id || null,
            note: form.note.trim() || null,
        });
        toast.success(t('education.new_promotion.made', { number: data.number }));
        emit('made', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (error.data?.field) errors.value = { ...errors.value, [error.data.field]: [error.message] };
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <AppDialog :open="open" :title="t('education.new_promotion.title')" :description="t('education.new_promotion.text')" :icon="ListChecks" @close="emit('close')">
        <form id="education-new-promotion" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="submit">
            <AppField v-slot="{ id }" :label="t('education.new_promotion.from_session')" :error="fieldError('from_session_id')">
                <select :id="id" v-model="form.from_session_id" class="field-input">
                    <option v-for="item in sessions" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.new_promotion.to_session')" :hint="targets.length ? '' : t('education.overview.no_session_text')" :error="fieldError('to_session_id')">
                <select :id="id" v-model="form.to_session_id" class="field-input" :disabled="!targets.length">
                    <option value="">—</option>
                    <option v-for="item in targets" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.new_promotion.level')" :error="fieldError('level_id')">
                <select :id="id" v-model="form.level_id" class="field-input">
                    <option value="">—</option>
                    <option v-for="item in levels" :key="item.id" :value="item.id">{{ setup.levelText(item.id) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.new_promotion.section')" :error="fieldError('section_id')" optional>
                <select :id="id" v-model="form.section_id" class="field-input" :disabled="!sections.length">
                    <option value="">{{ t('education.new_promotion.all_sections') }}</option>
                    <option v-for="item in sections" :key="item.id" :value="item.id">{{ item.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.new_promotion.note')" :error="fieldError('note')" optional class="sm:col-span-2">
                <textarea :id="id" v-model="form.note" rows="2" class="field-input" maxlength="500" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="education-new-promotion" :loading="saving" :disabled="!form.from_session_id || !form.to_session_id || !form.level_id">
                {{ t('education.new_promotion.make') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
