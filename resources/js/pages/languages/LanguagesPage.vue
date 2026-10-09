<script setup>
import { computed } from 'vue';
import { Languages, Lock } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import WordingEditor from '@/components/WordingEditor.vue';
import { api } from '@/lib/http';
import { loadMe, session } from '@/lib/session';
import { useResource } from '@/lib/useResource';
import { t } from '@/lib/i18n';

/**
 * A group's or company's own wording (LANG-1, multi_language). Wording lives
 * at a group or a company: from a branch, the company above is worded.
 */
const target = computed(() => {
    const path = session.me?.context?.path ?? [];
    const here = path[path.length - 1];
    if (here && ['group', 'company', 'personal'].includes(here.type)) return here;
    return [...path].reverse().find((node) => ['company', 'personal'].includes(node.type)) ?? here ?? null;
});

const state = useResource(() => api(`/api/organizations/${target.value.id}/languages`).then((response) => response.data));
const data = computed(() => state.data.value);

// The person's own screens follow the new wording at once.
const refresh = () => loadMe();
</script>

<template>
    <div>
        <PageHeader :title="t('languages.title')" :description="t('languages.description')">
            <template v-if="data" #eyebrow>
                <p class="mb-1 text-[12.5px] font-medium text-muted">{{ data.organization.name }}</p>
            </template>
        </PageHeader>

        <SkeletonRows v-if="state.loading.value && !data" :rows="6" />
        <ErrorState v-else-if="state.error.value" :error="state.error.value" @retry="state.reload()" />
        <template v-else-if="data">
            <EmptyState v-if="!data.can_hold_wording" :icon="Languages" :title="t('languages.title')" :text="t('languages.wrong_level')" />
            <EmptyState v-else-if="!data.own_wording" :icon="Lock" :title="t('languages.title')" :text="t('languages.own_wording_off')" />
            <WordingEditor v-else :base="`/api/organizations/${data.organization.id}/translations`" :languages="data.languages" can-edit @changed="refresh" />
        </template>
    </div>
</template>
