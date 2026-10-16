/** An average in hundredths of a star as people read it: "4.2" (one decimal). */
export function starsText(centi, format = (value) => String(value)) {
    if (centi === null || centi === undefined) return '—';
    return format((Math.round(centi / 10) / 10).toFixed(1));
}

/** Round status -> badge tone. */
export function roundTone(status) {
    return { draft: 'neutral', open: 'ok', closed: 'outline' }[status] ?? 'neutral';
}

/** Average -> tone: good from 4, worrying under 3 (never colour alone: the number is always shown). */
export function scoreTone(centi) {
    if (centi === null || centi === undefined) return 'neutral';
    return centi >= 400 ? 'ok' : centi < 300 ? 'bad' : 'warn';
}

/** Whether every question has stars (1-5). */
export function allAnswered(questions, answers) {
    return questions.length > 0 && questions.every((question) => Number.isInteger(answers[question.id]) && answers[question.id] >= 1 && answers[question.id] <= 5);
}
