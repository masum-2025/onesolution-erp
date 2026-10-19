import { computed } from 'vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { websiteApi } from './api';

/**
 * The website of the company being worked in, as every website screen
 * needs it: the site (null before it is started), what the screens may
 * offer (templates, limits with their source, languages) and what this
 * person may do (manage, publish).
 */
export function useSite() {
    const org = currentOrganization();
    const web = websiteApi(org.id);
    const resource = useResource(() => web.show());
    const site = computed(() => resource.data.value?.data ?? null);
    const meta = computed(() => resource.data.value?.meta ?? {});

    return {
        web,
        resource,
        site,
        meta,
        canManage: computed(() => !!meta.value.can_manage),
        canPublish: computed(() => !!meta.value.can_publish),
        pick: (texts) => textIn(texts ?? {}) || '',
    };
}
