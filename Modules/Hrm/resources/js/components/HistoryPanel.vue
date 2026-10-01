<script setup>
import { computed } from 'vue';
import { History } from 'lucide-vue-next';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/** The employment history, newest first (append-only on the server). */
const props = defineProps({
    employeeId: { type: String, required: true },
    hrm: { type: Object, required: true },
    // Unit and position names to show instead of ids.
    names: { type: Object, default: () => ({}) },
});

const history = useResource(() => props.hrm.history(props.employeeId));
const events = computed(() => history.data.value?.data ?? []);

defineExpose({ reload: () => history.reload() });

/** "Unit: Head office → Branch 1" lines of a step. */
function changes(event) {
    const keys = [...new Set([...Object.keys(event.from ?? {}), ...Object.keys(event.to ?? {})])];
    return keys
        .filter((key) => ['unit_id', 'position_id', 'status'].includes(key))
        .map((key) => {
            const show = (value) => (key === 'status' ? t(`hrm.statuses.${value}`) : (props.names[value] ?? '–'));
            const label = t(`hrm.history.${key.replace('_id', '')}`);
            return `${label}: ${event.from?.[key] ? show(event.from[key]) : '–'} → ${event.to?.[key] ? show(event.to[key]) : '–'}`;
        });
}
</script>

<template>
    <section class="card">
        <SkeletonRows v-if="history.loading.value && !history.data.value" :rows="4" />
        <ErrorState v-else-if="history.error.value" compact :error="history.error.value" @retry="history.reload()" />
        <EmptyState v-else-if="!events.length" :icon="History" :title="t('hrm.history.empty')" compact />
        <ol v-else class="relative space-y-0 px-5 py-4">
            <li v-for="event in events" :key="event.id" class="relative border-s border-line ps-5 pb-5 last:pb-0">
                <span class="absolute -start-[5px] top-1.5 size-2.5 rounded-full bg-brand" aria-hidden="true" />
                <p class="text-[13.5px] font-medium text-fg">{{ event.label }}</p>
                <p class="text-[12px] text-muted">{{ formatDate(event.effective_on) }}</p>
                <ul v-if="changes(event).length" class="mt-1 text-[12.5px] text-fg-2">
                    <li v-for="line in changes(event)" :key="line">{{ line }}</li>
                </ul>
                <p v-if="event.reason" class="mt-1 text-[12.5px] text-muted italic">“{{ event.reason }}”</p>
            </li>
        </ol>
    </section>
</template>
