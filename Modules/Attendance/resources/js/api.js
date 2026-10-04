import { api } from '@/lib/http';

/**
 * Calls to the Attendance API at a unit (company, branch or department). The
 * server checks every access again (permission at the employee's unit,
 * module on); these only shape the requests.
 */
export function attendanceApi(organizationId) {
    const base = `/api/organizations/${organizationId}/attendance`;

    return {
        me: () => api(`${base}/me`),
        // { op_id, latitude_micro?, longitude_micro?, accuracy_m? }
        punch: (body) => api(`${base}/me/punch`, { method: 'POST', body }),
        myDays: (month) => api(`${base}/me/days`, { query: { month } }),
        myCorrections: () => api(`${base}/me/corrections`),
        askMine: (body) => api(`${base}/me/corrections`, { method: 'POST', body }),

        days: (query) => api(`${base}/days`, { query }),
        punches: (query) => api(`${base}/punches`, { query }),
        writePunch: (body) => api(`${base}/punches`, { method: 'POST', body }),
        voidPunch: (id, reason) => api(`${base}/punches/${id}/void`, { method: 'POST', body: { reason } }),

        corrections: (status) => api(`${base}/corrections`, { query: { status } }),
        askFor: (body) => api(`${base}/corrections`, { method: 'POST', body }),
        decide: (id, step, body) => api(`${base}/corrections/${id}/${step}`, { method: 'POST', body }),

        shifts: () => api(`${base}/shifts`),
        createShift: (body) => api(`${base}/shifts`, { method: 'POST', body }),
        updateShift: (id, body) => api(`${base}/shifts/${id}`, { method: 'PATCH', body }),
        holidays: (year) => api(`${base}/holidays`, { query: { year } }),
        addHoliday: (body) => api(`${base}/holidays`, { method: 'POST', body }),
        removeHoliday: (id) => api(`${base}/holidays/${id}`, { method: 'DELETE' }),
        rosters: (employeeId) => api(`${base}/rosters`, { query: { employee_id: employeeId } }),
        assign: (body) => api(`${base}/rosters`, { method: 'POST', body }),

        locations: () => api(`${base}/locations`),
        createLocation: (body) => api(`${base}/locations`, { method: 'POST', body }),
        updateLocation: (id, body) => api(`${base}/locations/${id}`, { method: 'PATCH', body }),
        deviceFormat: () => api(`${base}/device-format`),
        importDevice: (body) => api(`${base}/device-import`, { method: 'POST', body }),
    };
}

/** A portal member's own days (B2B2C). */
export const portalAttendanceApi = {
    days: (month) => api('/api/portal/attendance/days', { query: { month } }),
};
