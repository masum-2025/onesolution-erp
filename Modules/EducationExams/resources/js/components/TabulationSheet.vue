<script setup>
import { computed } from 'vue';
import { brand } from '@/lib/brand';
import { formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { marksShown, pointShown } from '../labels';

/**
 * A section's tabulation sheet on A4 landscape: one row per student (by
 * roll), every subject's marks and letter, the total, GPA, letter and
 * position. Subjects in the order they appear on the cards.
 */
const props = defineProps({ data: { type: Object, required: true } });
const subjects = computed(() => {
    const seen = new Map();
    for (const card of props.data.students) {
        for (const subject of card.subjects) {
            if (!seen.has(subject.subject_id)) seen.set(subject.subject_id, subject.subject);
        }
    }
    return [...seen.entries()].map(([id, subject]) => ({ id, ...subject }));
});
const of = (card, id) => card.subjects.find((subject) => subject.subject_id === id);
const sections = computed(() => [...new Set(props.data.students.map((card) => card.section).filter(Boolean))].join(', '));
</script>

<template>
    <article class="mx-auto w-full bg-surface p-4 text-[11px] text-fg print:p-0">
        <header class="mb-2 flex items-center gap-3 border-b-2 border-brand pb-2">
            <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-10 w-auto" />
            <div class="min-w-0 flex-1">
                <h2 class="text-[15px] font-bold leading-tight">{{ textIn(data.institution.name) || brand.name }}</h2>
                <p class="text-muted">{{ t('education_exams.cards.tabulation') }} · {{ textIn(data.source.name) }} · {{ textIn(data.source.session) }}</p>
            </div>
            <p class="text-end text-muted">{{ textIn(data.students[0]?.level) }}<template v-if="sections"> · {{ sections }}</template></p>
        </header>
        <div class="overflow-x-auto">
            <table class="w-full border-collapse">
                <thead>
                    <tr class="bg-subtle text-muted">
                        <th scope="col" class="border border-line px-1.5 py-1 text-center">{{ t('education_exams.cards.roll') }}</th>
                        <th scope="col" class="border border-line px-1.5 py-1 text-start">{{ t('education_exams.cards.name') }}</th>
                        <th v-for="subject in subjects" :key="subject.id" scope="col" class="border border-line px-1.5 py-1 text-center" :title="textIn(subject.name)">{{ subject.code }}</th>
                        <th scope="col" class="border border-line px-1.5 py-1 text-center">{{ t('education_exams.entry.total') }}</th>
                        <th scope="col" class="border border-line px-1.5 py-1 text-center">{{ t('education_exams.cards.gpa') }}</th>
                        <th v-if="data.show_rank" scope="col" class="border border-line px-1.5 py-1 text-center">{{ t('education_exams.cards.position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="card in data.students" :key="card.result_id" class="break-inside-avoid">
                        <td class="tabular border border-line px-1.5 py-1 text-center">{{ card.roll ?? '—' }}</td>
                        <td class="border border-line px-1.5 py-1">{{ card.student?.name }}</td>
                        <td v-for="subject in subjects" :key="subject.id" class="tabular border border-line px-1.5 py-1 text-center" :class="of(card, subject.id) && !of(card, subject.id).passed ? 'text-bad' : ''">
                            <template v-if="of(card, subject.id)">
                                {{ of(card, subject.id).absent ? t('education_exams.cards.absent_short') : marksShown(of(card, subject.id).marks_centi) }}
                                <span class="block text-[10px] font-semibold">{{ of(card, subject.id).letter }}</span>
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="tabular border border-line px-1.5 py-1 text-center">{{ marksShown(card.marks_centi) }}</td>
                        <td class="tabular border border-line px-1.5 py-1 text-center font-semibold" :class="card.passed ? '' : 'text-bad'">{{ pointShown(card.gpa_centi) }} {{ card.letter }}</td>
                        <td v-if="data.show_rank" class="tabular border border-line px-1.5 py-1 text-center">{{ card.rank ? formatNumber(card.rank) : '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <footer class="mt-10 grid gap-6 break-inside-avoid" :style="{ gridTemplateColumns: `repeat(${Math.max(1, data.signatures.length)}, minmax(0, 1fr))` }">
            <div v-for="who in data.signatures.filter((item) => item !== 'guardian')" :key="who" class="border-t border-fg/60 pt-1 text-center text-muted">{{ t(`education_exams.cards.signs.${who}`) }}</div>
        </footer>
    </article>
</template>
