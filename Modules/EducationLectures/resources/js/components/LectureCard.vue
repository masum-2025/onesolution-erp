<script setup>
import { computed, ref } from 'vue';
import { ExternalLink, FileText, Image, PlayCircle, Presentation } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { formatDate, formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { sizeText, statusTone } from '../lib';

/**
 * One lecture: subject, class and section, the day and teacher, its text
 * (long text folds), the video link and the files (each opens through a
 * link that lasts minutes). Staff screens add their buttons in the slot.
 */
const props = defineProps({
    lecture: { type: Object, required: true },
    // Names from the answer's meta: subjects, sections, teachers.
    names: { type: Object, default: () => ({}) },
    showStatus: Boolean,
    showSection: { type: Boolean, default: true },
});

const LONG = 280;
const open = ref(false);
const long = computed(() => (props.lecture.body ?? '').length > LONG);
const body = computed(() => (long.value && !open.value ? `${props.lecture.body.slice(0, LONG).trimEnd()}…` : props.lecture.body ?? ''));
const subject = computed(() => props.names.subjects?.[props.lecture.subject_id]);
const section = computed(() => props.names.sections?.[props.lecture.section_id]);
const teacher = computed(() => (props.lecture.teacher_id ? props.names.teachers?.[props.lecture.teacher_id] : null));
const day = (date) => formatDate(`${date}T00:00:00Z`, { dateStyle: 'medium', timeZone: 'UTC' });
const icon = (mime) => (mime.startsWith('image/') ? Image : mime.includes('presentation') ? Presentation : FileText);
const videoHost = computed(() => {
    try {
        return new URL(props.lecture.video_url).hostname.replace(/^(www|m|player)\./, '');
    } catch {
        return '';
    }
});
</script>

<template>
    <article class="card p-4 sm:p-5">
        <header class="flex flex-wrap items-start gap-x-3 gap-y-1">
            <div class="min-w-0 flex-1">
                <p class="text-[12px] font-medium text-brand-text">
                    {{ subject ? textIn(subject.name) : '—' }}
                    <template v-if="showSection && section"> · {{ textIn(section.level) }} {{ section.name }}</template>
                </p>
                <h3 class="text-[15.5px] font-semibold leading-snug text-fg">{{ lecture.title }}</h3>
                <p class="mt-0.5 text-[12.5px] text-muted">
                    {{ day(lecture.lesson_on) }}<template v-if="teacher"> · {{ teacher }}</template>
                </p>
            </div>
            <AppBadge v-if="showStatus" :tone="statusTone(lecture.status)" dot>{{ t(`education_lectures.statuses.${lecture.status}`) }}</AppBadge>
        </header>

        <p v-if="lecture.status === 'withdrawn' && lecture.withdrawn_reason" class="mt-3 rounded-lg bg-bad-soft px-3 py-2 text-[12.5px] text-bad">
            {{ t('education_lectures.card.withdrawn', { reason: lecture.withdrawn_reason }) }}
        </p>

        <div v-if="lecture.body" class="mt-3 whitespace-pre-line break-words text-[13.5px] leading-relaxed text-fg-2">{{ body }}</div>
        <button v-if="long" type="button" class="mt-1 text-[12.5px] font-medium text-brand-text hover:underline" :aria-expanded="open" @click="open = !open">
            {{ open ? t('education_lectures.card.less') : t('education_lectures.card.more') }}
        </button>

        <div v-if="lecture.video_url || lecture.files.length" class="mt-3 flex flex-wrap gap-2">
            <a
                v-if="lecture.video_url"
                :href="lecture.video_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[12.5px] font-medium text-fg hover:border-brand/40"
            >
                <PlayCircle class="size-4 shrink-0 text-bad" aria-hidden="true" />
                <span class="truncate">{{ t('education_lectures.card.video', { site: videoHost }) }}</span>
                <ExternalLink class="size-3.5 shrink-0 text-faint" aria-hidden="true" />
            </a>
            <a
                v-for="file in lecture.files"
                :key="file.id"
                :href="file.url"
                class="inline-flex max-w-full items-center gap-1.5 rounded-lg border border-line bg-surface px-2.5 py-1.5 text-[12.5px] text-fg hover:border-brand/40"
                :aria-label="t('education_lectures.card.download', { name: file.name })"
            >
                <component :is="icon(file.mime)" class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                <span class="truncate">{{ file.name }}</span>
                <span class="tabular shrink-0 text-faint">{{ sizeText(file.size_bytes, formatNumber) }}</span>
            </a>
        </div>

        <footer v-if="$slots.default" class="mt-4 flex flex-wrap gap-2 border-t border-line pt-3">
            <slot />
        </footer>
    </article>
</template>
