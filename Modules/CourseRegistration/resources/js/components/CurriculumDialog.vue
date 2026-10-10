<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Layers } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';

/**
 * Offer a class's whole curriculum in the session at a campus: one group
 * of each subject not yet offered there.
 */
const props = defineProps({ open: Boolean });
const emit = defineEmits(['close', 'done']);

const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();

const form = reactive({ level_id: '', unit_id: '', group_name: 'A', capacity: 40 });
const errors = ref({});
const saving = ref(false);
const levels = computed(() => (setup.data.value?.levels ?? []).filter((level) => level.program.progression === setup.session.value?.kind));

watch(() => props.open, (open) => {
    if (!open) return;
    const campuses = setup.data.value?.campuses ?? [];
    Object.assign(form, { level_id: levels.value[0]?.id ?? '', unit_id: campuses.find((campus) => campus.id === org.id)?.id ?? campuses[0]?.id ?? '', group_name: 'A', capacity: 40 });
    errors.value = {};
});

async function offer() {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await api.fromCurriculum({ ...form, capacity: Number(form.capacity), session_id: setup.sessionId.value });
        if (data.length) toast.success(t('course_registration.curriculum.done', { count: data.length }));
        else toast.info(t('course_registration.curriculum.none'));
        emit('done', data);
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('course_registration.curriculum.title')" :description="t('course_registration.curriculum.text')" :icon="Layers" @close="emit('close')">
        <form id="crs-curriculum" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="offer">
            <AppField v-slot="{ id }" :label="t('course_registration.curriculum.level')" :error="errors.level_id?.[0] ?? null" class="sm:col-span-2">
                <select :id="id" v-model="form.level_id" class="field-input">
                    <option v-for="level in levels" :key="level.id" :value="level.id">{{ setup.levelText(level.id) }}</option>
                </select>
            </AppField>
            <AppField v-if="(setup.data.value?.campuses?.length ?? 0) > 1" v-slot="{ id }" :label="t('course_registration.curriculum.campus')" class="sm:col-span-2">
                <select :id="id" v-model="form.unit_id" class="field-input">
                    <option v-for="campus in setup.data.value.campuses" :key="campus.id" :value="campus.id">{{ campus.name }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('course_registration.curriculum.group')" :error="errors.group_name?.[0] ?? null">
                <input :id="id" v-model="form.group_name" class="field-input" maxlength="20" />
            </AppField>
            <AppField v-slot="{ id }" :label="t('course_registration.curriculum.capacity')" :error="errors.capacity?.[0] ?? null">
                <input :id="id" v-model="form.capacity" type="number" min="1" max="2000" class="field-input tabular" />
            </AppField>
        </form>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('course_registration.cancel') }}</AppButton>
            <AppButton variant="primary" type="submit" form="crs-curriculum" :loading="saving" :disabled="!form.level_id || !form.group_name.trim()">{{ t('course_registration.curriculum.offer') }}</AppButton>
        </template>
    </AppDialog>
</template>
