<script setup>
import { brand } from '@/lib/brand';
import { formatNumber } from '@/lib/format';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { dateRange, marksShown, partName, pointShown } from '../labels';

/**
 * One student's report card (marksheet) as it prints on A4: the
 * institution's name and logo, the exam, the student, every subject (with
 * its parts), the total, GPA, letter and positions (rule show_rank), days
 * attended when kept, the grading bands, and the signature lines the rule
 * asks for. Not yet published: marked as a draft across it.
 */
defineProps({
    card: { type: Object, required: true }, // one of data.students
    data: { type: Object, required: true }, // the report cards response (source, institution, scheme, signatures)
});
const bandRange = (bands, index) => {
    const upper = index === 0 ? 10000 : bands[index - 1].min_percent_bp - 1;
    return `${marksShown(bands[index].min_percent_bp)}–${marksShown(Math.max(upper, bands[index].min_percent_bp))}`;
};
</script>

<template>
    <article class="report-card relative mx-auto flex min-h-[273mm] w-full max-w-[190mm] flex-col bg-surface p-6 text-[12.5px] text-fg shadow-sm print:min-h-0 print:p-0 print:shadow-none sm:p-8">
        <p v-if="data.source.status !== 'published'" class="pointer-events-none absolute inset-0 grid place-items-center -rotate-[24deg] text-[64px] font-black tracking-[0.3em] text-warn/15" aria-hidden="true">
            {{ t('education_exams.cards.draft') }}
        </p>

        <header class="flex items-center gap-4 border-b-2 border-brand pb-3">
            <img v-if="brand.logo_url" :src="brand.logo_url" alt="" class="h-14 w-auto" />
            <div class="min-w-0 flex-1">
                <h2 class="text-[19px] font-bold leading-tight">{{ textIn(data.institution.name) || brand.name }}</h2>
                <p v-if="data.institution.unit" class="text-muted">{{ textIn(data.institution.unit) }}</p>
            </div>
            <div class="text-end">
                <p class="text-[15px] font-semibold uppercase tracking-wide text-brand-text">{{ t('education_exams.cards.title') }}</p>
                <p class="font-medium">{{ textIn(data.source.name) }}</p>
                <p class="text-muted">{{ textIn(data.source.session) }}<template v-if="data.source.starts_on"> · {{ dateRange(data.source.starts_on, data.source.ends_on) }}</template></p>
            </div>
        </header>

        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 sm:grid-cols-3">
            <div class="col-span-2 sm:col-span-1"><dt class="inline text-muted">{{ t('education_exams.cards.name') }}:</dt> <dd class="inline font-semibold">{{ card.student?.name }}</dd><span v-if="card.student?.name_local" class="text-muted"> ({{ card.student.name_local }})</span></div>
            <div><dt class="inline text-muted">{{ t('education_exams.cards.id') }}:</dt> <dd class="inline font-mono" dir="ltr">{{ card.student?.code }}</dd></div>
            <div v-if="card.roll"><dt class="inline text-muted">{{ t('education_exams.cards.roll') }}:</dt> <dd class="inline font-semibold">{{ card.roll }}</dd></div>
            <div><dt class="inline text-muted">{{ t('education_exams.cards.class') }}:</dt> <dd class="inline">{{ textIn(card.level) }}</dd></div>
            <div v-if="card.section"><dt class="inline text-muted">{{ t('education_exams.cards.section') }}:</dt> <dd class="inline">{{ card.section }}</dd></div>
        </dl>

        <table class="mt-4 w-full border-collapse">
            <thead>
                <tr class="bg-subtle text-[11.5px] text-muted">
                    <th scope="col" class="border border-line px-2 py-1.5 text-start font-semibold">{{ t('education_exams.paper.subject') }}</th>
                    <th scope="col" class="w-16 border border-line px-2 py-1.5 text-center font-semibold">{{ t('education_exams.paper.full') }}</th>
                    <th scope="col" class="w-20 border border-line px-2 py-1.5 text-center font-semibold">{{ t('education_exams.cards.obtained') }}</th>
                    <th scope="col" class="w-16 border border-line px-2 py-1.5 text-center font-semibold">{{ t('education_exams.grading.letter') }}</th>
                    <th scope="col" class="w-16 border border-line px-2 py-1.5 text-center font-semibold">{{ t('education_exams.grading.point') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="subject in card.subjects" :key="subject.subject_id">
                    <td class="border border-line px-2 py-1.5">
                        {{ textIn(subject.subject?.name) }} <span class="text-[11px] text-muted">({{ subject.subject?.code }})</span>
                        <span v-if="subject.kind === 'optional'" class="text-[11px] text-muted"> · {{ t('education_exams.paper_kinds.optional') }}</span>
                        <span v-if="subject.parts.length" class="block text-[11px] text-muted">
                            <template v-for="(part, index) in subject.parts" :key="part.code"><template v-if="index"> · </template>{{ partName(part) }} {{ part.absent ? t('education_exams.cards.absent_short') : marksShown(part.marks_centi) }}/{{ marksShown(part.full_marks_centi) }}</template>
                        </span>
                    </td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ marksShown(subject.full_marks_centi) }}</td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ subject.absent ? t('education_exams.entry.absent') : marksShown(subject.marks_centi) }}</td>
                    <td class="border border-line px-2 py-1.5 text-center font-semibold" :class="subject.passed ? '' : 'text-bad'">{{ subject.letter ?? '—' }}</td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ pointShown(subject.point_centi) }}</td>
                </tr>
            </tbody>
            <tfoot>
                <tr class="font-semibold">
                    <td class="border border-line px-2 py-1.5 text-end">{{ t('education_exams.entry.total') }}</td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ marksShown(card.full_marks_centi) }}</td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ marksShown(card.marks_centi) }}</td>
                    <td class="border border-line px-2 py-1.5 text-center" :class="card.passed ? '' : 'text-bad'">{{ card.letter ?? '—' }}</td>
                    <td class="tabular border border-line px-2 py-1.5 text-center">{{ pointShown(card.gpa_centi) }}</td>
                </tr>
            </tfoot>
        </table>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-lg border border-line px-3 py-2">
                <p class="text-[11px] text-muted">{{ t('education_exams.cards.gpa') }}</p>
                <p class="text-[20px] font-bold leading-tight">{{ pointShown(card.gpa_centi) }} <span class="text-[14px]" :class="card.passed ? 'text-fg' : 'text-bad'">{{ card.letter }}</span></p>
            </div>
            <div class="rounded-lg border border-line px-3 py-2">
                <p class="text-[11px] text-muted">{{ t('education_exams.cards.result') }}</p>
                <p class="text-[16px] font-bold leading-tight" :class="card.passed ? 'text-ok' : 'text-bad'">{{ t(card.passed ? 'education_exams.cards.passed' : 'education_exams.cards.failed') }}</p>
                <p v-if="card.failed_subjects" class="text-[11px] text-bad">{{ t('education_exams.results.failed_subjects', { count: formatNumber(card.failed_subjects) }) }}</p>
            </div>
            <div v-if="data.show_rank && card.rank" class="rounded-lg border border-line px-3 py-2">
                <p class="text-[11px] text-muted">{{ t('education_exams.cards.position') }}</p>
                <p class="text-[16px] font-bold leading-tight">{{ formatNumber(card.rank) }}</p>
                <p v-if="card.section_rank" class="text-[11px] text-muted">{{ t('education_exams.results.section_rank', { rank: formatNumber(card.section_rank) }) }}</p>
            </div>
            <div v-if="card.attendance" class="rounded-lg border border-line px-3 py-2">
                <p class="text-[11px] text-muted">{{ t('education_exams.cards.attendance') }}</p>
                <p class="text-[16px] font-bold leading-tight">{{ formatNumber(card.attendance.present) }}<span class="text-[12px] font-normal text-muted">/{{ formatNumber(card.attendance.days) }}</span></p>
            </div>
        </div>

        <section v-if="data.scheme?.bands?.length" class="mt-4">
            <h3 class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-muted">{{ t('education_exams.cards.grading') }} · {{ textIn(data.scheme.name) }}</h3>
            <div class="flex flex-wrap gap-x-3 gap-y-0.5 text-[11px] text-muted">
                <span v-for="(band, index) in data.scheme.bands" :key="band.min_percent_bp"><span class="tabular">{{ bandRange(data.scheme.bands, index) }}%</span> = <strong class="text-fg">{{ band.letter }}</strong> ({{ pointShown(band.point_centi) }})</span>
            </div>
        </section>

        <footer class="mt-auto grid gap-6 pt-14" :style="{ gridTemplateColumns: `repeat(${Math.max(1, data.signatures.length)}, minmax(0, 1fr))` }">
            <div v-for="who in data.signatures" :key="who" class="border-t border-fg/60 pt-1 text-center text-[11.5px] text-muted">{{ t(`education_exams.cards.signs.${who}`) }}</div>
        </footer>
    </article>
</template>
