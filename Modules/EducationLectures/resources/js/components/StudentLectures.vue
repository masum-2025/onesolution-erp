<script setup>
import { computed, watch } from 'vue';
import { BookOpen } from 'lucide-vue-next';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { lecturesApi } from '../api';
import LectureCard from './LectureCard.vue';

/**
 * The published lectures of the section a student is in now, newest first:
 * the lectures tab of the student's page in Education.
 */
const props = defineProps({ studentId: { type: String, required: true } });
const api = lecturesApi(currentOrganization().id);
const list = useResource(() => api.list({ student_id: props.studentId, status: 'published', per_page: 20 }), { immediate: false });
watch(() => props.studentId, () => list.reload(), { immediate: true });
const rows = computed(() => list.data.value?.data ?? []);
</script>

<template>
    <div>
        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="3" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <div v-else-if="!rows.length" class="card">
            <EmptyState :icon="BookOpen" compact :title="t('education_lectures.student_tab.empty')" />
        </div>
        <div v-else class="space-y-3">
            <LectureCard v-for="lecture in rows" :key="lecture.id" :lecture="lecture" :names="list.data.value.meta" :show-section="false" />
        </div>
    </div>
</template>
