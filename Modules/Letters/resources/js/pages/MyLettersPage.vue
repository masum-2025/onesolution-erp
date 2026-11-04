<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { Mail } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { lettersApi, portalLettersApi } from '../api';

/**
 * The letters given to me (shared with me, not revoked), newest first; in
 * the client's portal grouped by the employee records linked to me.
 */
const route = useRoute();
const portal = route.meta.letter === 'portal';
const list = useResource(() => (portal ? portalLettersApi.letters() : lettersApi(currentOrganization().id).mine()));
const groups = computed(() => {
    const data = list.data.value?.data ?? [];
    return portal ? data : [{ employee: null, letters: data }];
});
const count = computed(() => groups.value.reduce((sum, group) => sum + group.letters.length, 0));
const open = (letter) => ({ name: portal ? 'letters-portal-letter' : 'letters-my-letter', params: { id: letter.id } });
</script>

<template>
    <PageFrame
        :title="t('letters.mine.title')"
        :description="t('letters.mine.text')"
        width="lg"
        :resource="list"
        :empty="{ when: count === 0, icon: Mail, title: t('letters.mine.empty'), text: t('letters.mine.empty_text') }"
    >
        <section v-for="(group, index) in groups" :key="group.employee?.id ?? index" class="card mb-4">
            <h2 v-if="group.employee" class="border-b border-line px-5 py-3 text-[14px] font-semibold">{{ group.employee.name }}</h2>
            <ul class="divide-y divide-line">
                <li v-for="letter in group.letters" :key="letter.id">
                    <RouterLink :to="open(letter)" class="flex items-center gap-3 px-5 py-3.5 hover:bg-surface-2">
                        <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text"><Mail class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block text-[14.5px] font-semibold">{{ letter.title }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ letter.number }}</span>
                        </span>
                        <span class="text-[12.5px] text-muted">{{ formatDate(letter.issued_at, { dateStyle: 'medium' }) }}</span>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
