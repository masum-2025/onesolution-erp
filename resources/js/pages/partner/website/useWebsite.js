import { computed } from 'vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { textIn } from '@/lib/texts';

/**
 * The partner's company website (MKT-1 API), as every website screen needs
 * it: the site with its pages and sections (null before it is started),
 * the choices the forms offer, and what this person may do (write pages,
 * change settings, publish). The partner always comes from the session.
 */
export function useWebsite() {
    const resource = useResource(() => api('/api/partner/website').then((response) => response.data));
    const site = computed(() => resource.data.value?.site ?? null);
    const choices = computed(() => resource.data.value?.choices ?? {});
    const can = computed(() => resource.data.value?.can ?? {});

    return {
        resource,
        site,
        choices,
        can,
        // The language every text must have (the site's main language).
        mainLocale: computed(() => site.value?.default_locale ?? 'en'),
        pick: (texts) => textIn(texts ?? {}) || '',
        // Answers carry the whole site again: show it without a second request.
        apply: (data) => {
            if (data?.site !== undefined) resource.data.value = data;
        },
    };
}

/** The website's pictures (MKT-3a): listed with the limits, uploaded one file at a time. */
export const websiteMedia = {
    list: () => api('/api/partner/website/media'),
    upload: (file) => {
        const form = new FormData();
        form.append('file', file);
        return api('/api/partner/website/media', { method: 'POST', body: form });
    },
    update: (id, body) => api(`/api/partner/website/media/${id}`, { method: 'PATCH', body }),
    remove: (id) => api(`/api/partner/website/media/${id}`, { method: 'DELETE' }),
    // A picture's small copy for the console (the API serves it to the signed-in partner only).
    thumb: (id) => `/api/partner/website/media/${id}/small`,
};

/** Opens a signed preview of the drafts in a new tab (opened at the click, so no popup blocker). */
export async function openPreview(path = null) {
    const tab = window.open('', '_blank');
    try {
        const { url } = (await api('/api/partner/website/preview-link', { method: 'POST' })).data;
        const target = path ? `${url}&page=${encodeURIComponent(path)}` : url;
        if (tab) {
            tab.opener = null;
            tab.location.href = target;
        } else {
            window.location.href = target;
        }
    } catch (error) {
        tab?.close();
        throw error;
    }
}

/** A "someone else changed it" answer: the screen reloads instead of overwriting. */
export function changedMeanwhile(error) {
    return error?.status === 409 || error?.code === 'changed_meanwhile';
}
