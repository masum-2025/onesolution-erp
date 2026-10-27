<script setup>
import { computed } from 'vue';
import { Star, UserX } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import ReviewRows from '../components/ReviewRows.vue';
import { appraisalsApi } from '../api';

/** One's own appraisals (the login HR linked to the employee): each cycle's review, its step and, once there, the result. */
const org = currentOrganization();
const calls = appraisalsApi(org.id);
const list = useResource(() => calls.mine());
const items = computed(() => list.data.value?.data ?? []);
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('appraisals.me.title')" :description="t('appraisals.me.text')" />
        <section v-if="list.error.value?.code === 'not_linked'" class="card">
            <EmptyState :icon="UserX" :title="t('appraisals.me.not_linked')" :text="t('appraisals.me.not_linked_text')" />
        </section>
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <SkeletonRows v-else-if="!list.data.value" :rows="3" />
        <section v-else class="card">
            <EmptyState v-if="!items.length" :icon="Star" :title="t('appraisals.me.empty')" :text="t('appraisals.me.empty_text')" compact />
            <ReviewRows v-else :items="items" by="cycle" />
        </section>
    </div>
</template>
