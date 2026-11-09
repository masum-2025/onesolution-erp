<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Facebook, Link2Off, RefreshCw, Unplug } from 'lucide-vue-next';
import { AppButton, confirmAction, EmptyState, ErrorState, PageFrame, SkeletonRows, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { PAGE_TONES, socialApi } from '../api';

/**
 * The company's Facebook pages: connect in one click (Meta's sign-in, then
 * pick the pages), see which can take posts, connect again when Facebook
 * stops accepting a sign-in, disconnect.
 */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const list = useResource(() => social.pages());
const data = computed(() => list.data.value?.data ?? null);
const pages = computed(() => data.value?.pages ?? []);
const busy = ref(false);

// Coming back from Meta's sign-in without pages (cancelled, expired, refused).
onMounted(() => {
    const outcome = route.query.connect;
    if (outcome) {
        toast.error(t(`social_commerce.pages.outcome.${outcome}`, { reason: route.query.reason ?? '' }));
        router.replace({ query: {} });
    }
});

async function connect() {
    busy.value = true;
    try {
        const { data: started } = await social.connect();
        window.location.assign(started.url);
    } catch (error) {
        toast.error(error.message);
        busy.value = false;
    }
}

async function disconnect(page) {
    const ok = await confirmAction({
        title: t('social_commerce.pages.disconnect_title', { page: page.name }),
        message: t('social_commerce.pages.disconnect_text'),
        danger: true,
    });
    if (!ok) return;
    try {
        const response = await social.disconnect(page.id, { base_version: page.version });
        toast.success(response.message);
        list.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) list.reload();
    }
}
</script>

<template>
    <PageFrame :title="t('social_commerce.pages.title')" :description="t('social_commerce.pages.text')" width="lg">
        <template #actions>
            <AppButton v-if="data?.can_connect && data?.app" variant="primary" :icon="Facebook" :loading="busy" @click="connect">{{ t('social_commerce.pages.connect') }}</AppButton>
        </template>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !data" :rows="3" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!data.app" :icon="Unplug" :title="t('social_commerce.pages.no_app')" :text="t('social_commerce.pages.no_app_text')" />
            <EmptyState v-else-if="!pages.length" :icon="Facebook" :title="t('social_commerce.pages.empty')" :text="t('social_commerce.pages.empty_text', { app: data.app.name })">
                <AppButton v-if="data.can_connect" variant="primary" :icon="Facebook" :loading="busy" @click="connect">{{ t('social_commerce.pages.connect') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="page in pages" :key="page.id" class="flex flex-wrap items-center gap-4 px-5 py-4">
                    <img v-if="page.picture_url" :src="page.picture_url" alt="" class="size-11 rounded-full border border-line object-cover" referrerpolicy="no-referrer" />
                    <span v-else class="grid size-11 place-items-center rounded-full bg-surface-2"><Facebook class="size-5 text-muted" aria-hidden="true" /></span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ page.name }}</span>
                        <span class="block text-[12.5px] text-muted">
                            {{ t('social_commerce.pages.connected_by', { name: page.connected_by ?? '—', when: formatDateTime(page.connected_at) }) }}
                        </span>
                        <span v-if="page.status === 'connected' && !page.can_post" class="mt-1 block text-[12.5px] text-warn">{{ t('social_commerce.pages.cannot_post') }}</span>
                        <span v-if="page.status === 'needs_reconnect'" class="mt-1 block text-[12.5px] text-warn">{{ t('social_commerce.pages.needs_reconnect') }}</span>
                    </span>
                    <StatusBadge :status="page.status" :tones="PAGE_TONES">{{ t(`social_commerce.pages.status.${page.status}`) }}</StatusBadge>
                    <span v-if="data.can_connect" class="flex gap-2">
                        <AppButton v-if="page.status === 'needs_reconnect'" size="sm" variant="primary" :icon="RefreshCw" :loading="busy" @click="connect">{{ t('social_commerce.pages.reconnect') }}</AppButton>
                        <AppButton size="sm" variant="ghost" :icon="Link2Off" @click="disconnect(page)">{{ t('social_commerce.pages.disconnect') }}</AppButton>
                    </span>
                </li>
            </ul>
        </section>
        <p v-if="data?.app" class="mt-3 text-[12.5px] text-muted">{{ t('social_commerce.pages.through_app', { app: data.app.name }) }}</p>
    </PageFrame>
</template>
