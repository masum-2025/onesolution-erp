import { api } from '@/lib/http';

/**
 * Calls to the exams API at a unit. The server checks every access again
 * (permission at the unit, the module on, records of the unit and below,
 * who may enter a sheet); these only shape the requests. Marks travel as
 * hundredths (marks_centi), percentages as basis points.
 */
export function examsApi(organizationId) {
    const base = `/api/organizations/${organizationId}/exams`;

    return {
        setup: () => api(`${base}/setup`),

        createScheme: (body) => api(`${base}/schemes`, { method: 'POST', body }),
        updateScheme: (id, body) => api(`${base}/schemes/${id}`, { method: 'PATCH', body }),

        exams: (query) => api(base, { query }),
        exam: (id) => api(`${base}/${id}`),
        createExam: (body) => api(base, { method: 'POST', body }),
        updateExam: (id, body) => api(`${base}/${id}`, { method: 'PATCH', body }),
        setStatus: (id, status, version) => api(`${base}/${id}/status`, { method: 'POST', body: { status, base_version: version } }),

        createPaper: (examId, body) => api(`${base}/${examId}/papers`, { method: 'POST', body }),
        papersFromCurriculum: (examId, body) => api(`${base}/${examId}/papers/from-curriculum`, { method: 'POST', body }),
        updatePaper: (examId, id, body) => api(`${base}/${examId}/papers/${id}`, { method: 'PATCH', body }),
        deletePaper: (examId, id) => api(`${base}/${examId}/papers/${id}`, { method: 'DELETE' }),

        sheets: (examId, query) => api(`${base}/${examId}/sheets`, { query }),
        mySheets: () => api(`${base}/sheets`),
        sheet: (id) => api(`${base}/sheets/${id}`),
        saveMarks: (id, marks) => api(`${base}/sheets/${id}/marks`, { method: 'PUT', body: { marks } }),
        submitSheet: (id, version) => api(`${base}/sheets/${id}/submit`, { method: 'POST', body: { base_version: version } }),
        approveSheet: (id, version) => api(`${base}/sheets/${id}/approve`, { method: 'POST', body: { base_version: version } }),
        sendBackSheet: (id, note, version) => api(`${base}/sheets/${id}/send-back`, { method: 'POST', body: { note, base_version: version } }),
        assignSheet: (id, teacherId, version) => api(`${base}/sheets/${id}/assign`, { method: 'POST', body: { teacher_id: teacherId, base_version: version } }),

        results: (examId) => api(`${base}/${examId}/results`),
        computeResults: (examId) => api(`${base}/${examId}/results/compute`, { method: 'POST' }),
        publish: (examId, version) => api(`${base}/${examId}/publish`, { method: 'POST', body: { base_version: version } }),

        combinations: (query) => api(`${base}/combinations`, { query }),
        createCombination: (body) => api(`${base}/combinations`, { method: 'POST', body }),
        combinationResults: (id) => api(`${base}/combinations/${id}/results`),
        publishCombination: (id, version) => api(`${base}/combinations/${id}/publish`, { method: 'POST', body: { base_version: version } }),

        corrections: (query) => api(`${base}/corrections`, { query }),
        requestCorrection: (sheetId, body) => api(`${base}/sheets/${sheetId}/corrections`, { method: 'POST', body }),
        decideCorrection: (id, approve, body) => api(`${base}/corrections/${id}/${approve ? 'approve' : 'reject'}`, { method: 'POST', body }),

        findStudents: (q) => api(`${base}/students`, { query: { q } }),
        transcript: (studentId) => api(`${base}/students/${studentId}/results`),

        reportCards: (examId, query) => api(`${base}/${examId}/report-cards`, { query }),
        combinationReportCards: (id, query) => api(`${base}/combinations/${id}/report-cards`, { query }),
        sendResults: (examId, body = {}) => api(`${base}/${examId}/send-results`, { method: 'POST', body }),
        sendCombinationResults: (id, body = {}) => api(`${base}/combinations/${id}/send-results`, { method: 'POST', body }),
    };
}

/**
 * A family's results in the client's portal (B2B2C): the student is the
 * portal record the member is linked to; the server checks the link again.
 */
export function portalResultsApi(record) {
    return {
        show: () => api('/api/portal/results', { query: { record } }),
        reportCard: (resultId) => api(`/api/portal/results/${resultId}/report-card`, { query: { record } }),
    };
}
