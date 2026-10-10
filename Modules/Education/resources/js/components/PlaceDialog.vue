<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRightLeft } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import CapacityMeter from './CapacityMeter.vue';

/**
 * Put a student in another section of the same class and session (or in
 * none, to place later). Full sections cannot be chosen; the server checks
 * the seats again.
 */
const props = defineProps({
    open: Boolean,
    name: { type: String, default: '' },
    enrollment: { type: Object, default: null },
    education: { type: Object, required: true },
});
const emit = defineEmits(['close', 'done', 'conflict']);

const loading = ref(false);
const loadError = ref(null);
const sections = ref([]);
const chosen = ref(null);
const saving = ref(false);

async function load() {
    loading.value = true;
    loadError.value = null;
    try {
        const { data } = await props.education.overview({ session_id: props.enrollment.session_id });
        sections.value = data.sections.filter((section) => section.level_id === props.enrollment.level_id && section.is_active);
    } catch (error) {
        loadError.value = error;
    } finally {
        loading.value = false;
    }
}

watch(
    () => props.open,
    (open) => {
        if (!open || !props.enrollment) return;
        chosen.value = props.enrollment.section_id;
        load();
    },
);

const others = computed(() => sections.value.filter((section) => section.id !== props.enrollment?.section_id));
const full = (section) => section.id !== props.enrollment?.section_id && section.taken >= section.capacity;

async function submit() {
    saving.value = true;
    try {
        const { data } = await props.education.place(props.enrollment.id, { base_version: props.enrollment.version, section_id: chosen.value });
        toast.success(t('education.place.done'));
        emit('done', data);
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('education.conflict'));
            emit('conflict');
            return;
        }
        toast.error(error.errors?.section_id?.[0] ?? error.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" :title="t('education.place.title', { name })" :description="t('education.place.text')" :icon="ArrowRightLeft" @close="emit('close')">
        <SkeletonRows v-if="loading" :rows="3" />
        <ErrorState v-else-if="loadError" compact :error="loadError" @retry="load" />
        <fieldset v-else class="space-y-2">
            <legend class="sr-only">{{ t('education.fields.section') }}</legend>
            <p v-if="!others.length" class="rounded-xl bg-subtle px-4 py-3 text-[13px] text-muted">{{ t('education.place.no_sections') }}</p>
            <label
                v-for="section in sections"
                :key="section.id"
                class="flex items-center gap-3 rounded-xl border border-line-strong px-3 py-2.5 has-[:checked]:border-brand has-[:checked]:bg-brand-soft"
                :class="full(section) ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'"
            >
                <input v-model="chosen" type="radio" :value="section.id" class="accent-[var(--brand)]" :disabled="full(section)" />
                <span class="w-24 shrink-0 truncate text-[13.5px] font-medium">{{ section.name }}</span>
                <CapacityMeter :taken="section.taken" :capacity="section.capacity" class="flex-1" />
            </label>
            <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-line-strong px-3 py-2.5 text-[13.5px] has-[:checked]:border-brand has-[:checked]:bg-brand-soft">
                <input v-model="chosen" type="radio" :value="null" class="accent-[var(--brand)]" />
                {{ t('education.place.none') }}
            </label>
        </fieldset>
        <template #footer>
            <AppButton variant="ghost" @click="emit('close')">{{ t('education.cancel') }}</AppButton>
            <AppButton variant="primary" :loading="saving" :disabled="loading || chosen === (enrollment?.section_id ?? null)" @click="submit">{{ t('education.save') }}</AppButton>
        </template>
    </AppDialog>
</template>
