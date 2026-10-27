import { api } from '@/lib/http';

/**
 * Calls to the Appraisals API at a unit (company, branch or department).
 * The server checks every access again (the employee, their reviewer,
 * people viewing or managing appraisals there); these only shape the
 * requests.
 */
export function appraisalsApi(organizationId) {
    const base = `/api/organizations/${organizationId}/appraisals`;

    return {
        // { units, scale, bands, goal_share_percent, goals_total_100, self_review }
        options: () => api(`${base}/options`),

        competencies: () => api(`${base}/competencies`),
        createCompetency: (body) => api(`${base}/competencies`, { method: 'POST', body }),
        updateCompetency: (id, body) => api(`${base}/competencies/${id}`, { method: 'PATCH', body }),
        addDefaults: () => api(`${base}/competencies/defaults`, { method: 'POST' }),

        cycles: (query) => api(`${base}/cycles`, { query }),
        cycle: (id) => api(`${base}/cycles/${id}`),
        createCycle: (body) => api(`${base}/cycles`, { method: 'POST', body }),
        updateCycle: (id, body) => api(`${base}/cycles/${id}`, { method: 'PATCH', body }),
        // step: open | close
        stepCycle: (id, step, body) => api(`${base}/cycles/${id}/${step}`, { method: 'POST', body }),

        mine: () => api(`${base}/me`),
        team: () => api(`${base}/team`),
        review: (id) => api(`${base}/reviews/${id}`),
        // { base_version, goals: [{ id?, title, description, weight }] }
        saveGoals: (id, body) => api(`${base}/reviews/${id}/goals`, { method: 'PUT', body }),
        // { base_version, ratings: [{ id, rating, comment }], summary }
        saveRatings: (id, body) => api(`${base}/reviews/${id}/ratings`, { method: 'PUT', body }),
        // step: submit_goals | approve_goals | submit_self | submit_manager | acknowledge | send_back; body: { base_version, note }
        stepReview: (id, step, body) => api(`${base}/reviews/${id}/${step}`, { method: 'POST', body }),
    };
}
