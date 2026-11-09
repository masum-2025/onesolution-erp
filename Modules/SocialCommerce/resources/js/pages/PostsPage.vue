<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Image, Megaphone, Plus } from 'lucide-vue-next';
import { AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows, StatusBadge } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { POST_STATUSES, POST_TONES, socialApi } from '../api';

/** Facebook posts, newest first, by where they stand. */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const status = ref(route.query.status ?? '');
const list = useResource(() => social.posts(status.value ? { status: status.value } : {}));
const options = useResource(() => social.options());
watch(status, () => {
    router.replace({ query: status.value ? { status: status.value } : {} });
    list.reload();
});
const rows = computed(() => list.data.value?.data ?? []);
const canCompose = computed(() => options.data.value?.data?.can_compose === true);
const noPages = computed(() => options.data.value && !(options.data.value.data.pages ?? []).length);

/** The time to show: when it went out, when it goes out, or when it was written. */
const when = (post) => post.published_at ?? post.publish_at ?? post.created_at;
const firstLine = (post) => (post.message ?? '').split('\n').find((line) => line.trim()) ?? t('social_commerce.list.photos_only');

const exported = computed(() => ({
    module: 'social_commerce',
    page: 'social_commerce.posts',
    columns: [
        { key: 'created_at', label: t('social_commerce.fields.written'), value: (row) => formatDateTime(row.created_at) },
        { key: 'page', label: t('social_commerce.fields.page'), value: (row) => row.page?.name ?? '' },
        { key: 'message', label: t('social_commerce.fields.message'), value: (row) => firstLine(row) },
        { key: 'status', label: t('social_commerce.fields.status'), value: (row) => t(`social_commerce.status.${row.status}`) },
        { key: 'publish_at', label: t('social_commerce.fields.when'), value: (row) => (when(row) ? formatDateTime(when(row)) : '') },
        { key: 'photos', label: t('social_commerce.fields.photos'), type: 'number' },
        { key: 'products', label: t('social_commerce.fields.products'), type: 'number' },
    ],
    rows: rows.value,
}));
</script>

<template>
    <PageFrame :title="t('social_commerce.list.title')" :description="t('social_commerce.list.text')" width="full" :export="exported">
        <template #actions>
            <AppButton v-if="canCompose && !noPages" variant="primary" :icon="Plus" :to="{ name: 'social-post-new' }">{{ t('social_commerce.list.new') }}</AppButton>
        </template>
        <section class="card mb-4 flex flex-wrap items-end gap-4 p-4 print:hidden">
            <label class="grid gap-1 text-[12.5px] text-muted">
                {{ t('social_commerce.fields.status') }}
                <select v-model="status" class="field-input min-w-44">
                    <option value="">{{ t('social_commerce.list.all') }}</option>
                    <option v-for="value in POST_STATUSES" :key="value" :value="value">{{ t(`social_commerce.status.${value}`) }}</option>
                </select>
            </label>
        </section>
        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="noPages" :icon="Megaphone" :title="t('social_commerce.list.no_pages')" :text="t('social_commerce.list.no_pages_text')">
                <AppButton variant="primary" :to="{ name: 'social-pages' }">{{ t('social_commerce.list.open_pages') }}</AppButton>
            </EmptyState>
            <EmptyState v-else-if="!rows.length" :icon="Megaphone" :title="t('social_commerce.list.empty')" :text="t('social_commerce.list.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="post in rows" :key="post.id">
                    <RouterLink :to="{ name: 'social-post', params: { id: post.id } }" class="flex items-center gap-4 px-5 py-3 hover:bg-surface-2">
                        <img v-if="post.thumbnail" :src="post.thumbnail" alt="" class="size-14 shrink-0 rounded-lg border border-line object-cover" loading="lazy" />
                        <span v-else class="grid size-14 shrink-0 place-items-center rounded-lg bg-surface-2"><Image class="size-5 text-muted" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium">{{ firstLine(post) }}</span>
                            <span class="block text-[12.5px] text-muted">
                                {{ post.page?.name }} · {{ formatDateTime(when(post)) }}
                                <template v-if="post.photos"> · {{ t('social_commerce.list.photo_count', { count: post.photos }) }}</template>
                                <template v-if="post.products"> · {{ t('social_commerce.list.product_count', { count: post.products }) }}</template>
                            </span>
                        </span>
                        <StatusBadge :status="post.status" :tones="POST_TONES">{{ t(`social_commerce.status.${post.status}`) }}</StatusBadge>
                    </RouterLink>
                </li>
            </ul>
        </section>
    </PageFrame>
</template>
