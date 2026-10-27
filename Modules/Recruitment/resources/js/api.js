import { api } from '@/lib/http';

/**
 * Calls to the Recruitment API at a unit (company, branch or department).
 * The server checks every access again (permission where the job is,
 * module on, an interviewer only for their interviews); these only shape
 * the requests.
 */
export function recruitmentApi(organizationId) {
    const base = `/api/organizations/${organizationId}/recruitment`;

    return {
        // { units, positions, employment_types, stages }
        options: () => api(`${base}/options`),
        careers: () => api(`${base}/careers`),
        setCareers: (slug) => api(`${base}/careers`, { method: 'PUT', body: { slug } }),

        openings: (query) => api(`${base}/openings`, { query }),
        opening: (id) => api(`${base}/openings/${id}`),
        createOpening: (body) => api(`${base}/openings`, { method: 'POST', body }),
        updateOpening: (id, body) => api(`${base}/openings/${id}`, { method: 'PATCH', body }),
        // step: open | close
        stepOpening: (id, step, body) => api(`${base}/openings/${id}/${step}`, { method: 'POST', body }),

        // query: { opening_id, stage, status, q }
        applications: (query) => api(`${base}/applications`, { query }),
        application: (id) => api(`${base}/applications/${id}`),
        // { opening_id, name, email, phone, cover_note, source, op_id }
        addCandidate: (body) => api(`${base}/applications`, { method: 'POST', body }),
        // { base_version, stage?, rating?, note? }
        updateApplication: (id, body) => api(`${base}/applications/${id}`, { method: 'PATCH', body }),
        // step: reject | withdraw; body: { base_version, reason, tell }
        endApplication: (id, step, body) => api(`${base}/applications/${id}/${step}`, { method: 'POST', body }),
        // { base_version, joined_on, employment_type, manager_id }
        hire: (id, body) => api(`${base}/applications/${id}/hire`, { method: 'POST', body }),
        addFile: (id, file) => {
            const body = new FormData();
            body.append('file', file);
            return api(`${base}/applications/${id}/files`, { method: 'POST', body });
        },
        interviewers: (id) => api(`${base}/applications/${id}/interviewers`),
        people: (id, q) => api(`${base}/applications/${id}/people`, { query: { q } }),
        // { starts_at: 'YYYY-MM-DDTHH:mm' (the company's time), minutes, mode, place, interviewer_ids, tell_candidate }
        schedule: (id, body) => api(`${base}/applications/${id}/interviews`, { method: 'POST', body }),

        myInterviews: () => api(`${base}/interviews/mine`),
        // { score, recommendation, note }
        feedback: (id, body) => api(`${base}/interviews/${id}/feedback`, { method: 'POST', body }),
        cancelInterview: (id, body) => api(`${base}/interviews/${id}/cancel`, { method: 'POST', body }),

        // { salary_minor, start_on, respond_by }
        writeOffer: (applicationId, body) => api(`${base}/applications/${applicationId}/offers`, { method: 'POST', body }),
        offer: (id) => api(`${base}/offers/${id}`),
        updateOffer: (id, body) => api(`${base}/offers/${id}`, { method: 'PATCH', body }),
        // step: send | approve | return | withdraw; body: { base_version, note }
        stepOffer: (id, step, body) => api(`${base}/offers/${id}/${step}`, { method: 'POST', body }),
        // { body: { en, bn }, own, version, placeholders, sample, can_manage }
        letter: () => api(`${base}/letter`),
        saveLetter: (body) => api(`${base}/letter`, { method: 'PUT', body }),
    };
}
