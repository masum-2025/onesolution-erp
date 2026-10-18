import { api } from '@/lib/http';

/**
 * Calls to the students' attendance API at a unit. The server checks every
 * access again (permission at the unit, the module on, the section being
 * the person's, the day); these only shape the requests.
 */
export function attendanceApi(organizationId) {
    const base = `/api/organizations/${organizationId}/student-attendance`;

    return {
        base,
        setup: () => api(`${base}/setup`),
        day: (date) => api(`${base}/sheets`, { query: { date } }),
        sheet: (section, date, periodId) => api(`${base}/sheets/${section}/${date}`, { query: periodId ? { period_id: periodId } : {} }),
        save: (section, date, body) => api(`${base}/sheets/${section}/${date}`, { method: 'PUT', body }),
        student: (id, month) => api(`${base}/students/${id}`, { query: { month } }),
        register: (sectionId, month) => api(`${base}/register`, { query: { section_id: sectionId, month } }),
        registerCsvUrl: (sectionId, month) => `${base}/register?${new URLSearchParams({ section_id: sectionId, month, format: 'csv' })}`,
        periods: () => api(`${base}/periods`),
        addPeriod: (body) => api(`${base}/periods`, { method: 'POST', body }),
        changePeriod: (id, body) => api(`${base}/periods/${id}`, { method: 'PATCH', body }),
        removePeriod: (id, version) => api(`${base}/periods/${id}`, { method: 'DELETE', query: { base_version: version } }),
        holidays: (from, to) => api(`${base}/holidays`, { query: { from, to } }),
        addHoliday: (body) => api(`${base}/holidays`, { method: 'POST', body }),
        removeHoliday: (id) => api(`${base}/holidays/${id}`, { method: 'DELETE' }),
    };
}
