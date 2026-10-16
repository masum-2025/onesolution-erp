<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { BookOpen, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { portalLecturesApi } from '../api';
import LectureCard from '../components/LectureCard.vue';

/**
 * A student's lectures in the client's portal, newest first, by subject:
 * their own, or a child's when a parent opens it from the child's record.
 * Only the published lectures of the section the student is in now.
 */
const route = useRoute();
const subject = ref('');
const page = ref(1);
const view = useResource(() => portalLecturesApi.list(route.params.record || null, { subject_id: subject.value, page: page.value }));
watch(() => route.params.record, () => {
    subject.value = '';
    page.value = 1;
    view.reload();
});
watch([subject, page], () => view.reload());

const rows = computed(() => view.data.value?.data ?? []);
const meta = computed(() => view.data.value?.meta ?? {});
const names = computed(() => ({
    subjects: Object.fromEntries((meta.value.subjects ?? []).map((item) => [item.id, item])),
    teachers: meta.value.teachers ?? {},
}));
const choices = computed(() => [{ id: '', name: null }, ...(meta.value.subjects ?? [])]);
function choose(id) {
    subject.value = id;
    page.value = 1;
}
</script>

<template>
    <div>
        <PageHeader :title="t('education_lectures.portal.title')" :description="meta.student ? t('education_lectures.portal.text', { name: meta.student.name }) : ''" />

        <SkeletonRows v-if="view.loading.value && !view.data.value" :rows="5" />
        <section v-else-if="view.error.value?.status === 404" class="card">
            <EmptyState :icon="UserX" :title="t('education_lectures.portal.no_student')" :text="view.error.value.message" />
        </section>
        <ErrorState v-else-if="view.error.value" :error="view.error.value" @retry="view.reload()" />
        <template v-else>
            <div v-if="(meta.subjects ?? []).length > 1" class="-mx-1 mb-4 flex gap-2 overflow-x-auto px-1 pb-1" role="group" :aria-label="t('education_lectures.fields.subject')">
                <button
                    v-for="item in choices"
                    :key="item.id"
                    type="button"
                    class="shrink-0 rounded-full border px-3 py-1.5 text-[13px] transition"
                    :class="subject === item.id ? 'border-brand bg-brand text-brand-fg' : 'border-line bg-surface text-fg-2 hover:border-brand/40'"
                    :aria-pressed="subject === item.id"
                    @click="choose(item.id)"
                >
                    {{ item.name ? textIn(item.name) : t('education_lectures.filters.all_subjects') }}
                </button>
            </div>

            <div v-if="!rows.length" class="card">
                <EmptyState :icon="BookOpen" :title="t('education_lectures.portal.empty_title')" :text="t('education_lectures.portal.empty_text')" />
            </div>
            <div v-else class="space-y-3">
                <LectureCard v-for="lecture in rows" :key="lecture.id" :lecture="lecture" :names="names" :show-section="false" />
                <nav v-if="meta.last_page > 1" class="flex items-center justify-between pt-2" :aria-label="t('education_lectures.page.pages')">
                    <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('education_lectures.page.previous') }}</AppButton>
                    <span class="tabular text-[12.5px] text-muted">{{ t('education_lectures.page.page', { page, pages: meta.last_page }) }}</span>
                    <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++">{{ t('education_lectures.page.next') }}</AppButton>
                </nav>
            </div>
        </template>
    </div>
</template>
