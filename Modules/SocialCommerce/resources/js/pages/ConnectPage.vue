<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Check, Facebook, TriangleAlert } from 'lucide-vue-next';
import { AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { socialApi } from '../api';

/**
 * Last step of connecting: after Meta's sign-in, the pages found. Pick the
 * ones to connect; a page of another account or one you may not post on
 * says so. The sign-in is kept 10 minutes, then it starts again.
 */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const state = String(route.query.state ?? '');
const found = useResource(() => social.candidates(state));
const pages = computed(() => found.data.value?.data ?? []);
const chosen = ref([]);
const busy = ref(false);

// Everything that can be connected is ticked to begin with.
watch(pages, (list) => {
    chosen.value = list.filter((page) => !page.taken).map((page) => page.page_id);
}, { immediate: true });

async function save() {
    busy.value = true;
    try {
        const response = await social.savePages({ state, page_ids: chosen.value });
        toast.success(response.message);
        router.replace({ name: 'social-pages' });
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('social_commerce.connect.title')" :description="t('social_commerce.connect.text')" width="md">
        <template #before><AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'social-pages' }">{{ t('social_commerce.connect.back') }}</AppButton></template>

        <ol class="mb-4 flex flex-wrap gap-2 text-[12.5px]" :aria-label="t('social_commerce.connect.steps')">
            <li class="flex items-center gap-1.5 rounded-full bg-surface-2 px-3 py-1 text-muted"><Check class="size-3.5" aria-hidden="true" />{{ t('social_commerce.connect.step_1') }}</li>
            <li class="flex items-center gap-1.5 rounded-full bg-surface-2 px-3 py-1 text-muted"><Check class="size-3.5" aria-hidden="true" />{{ t('social_commerce.connect.step_2') }}</li>
            <li class="flex items-center gap-1.5 rounded-full bg-brand-soft px-3 py-1 font-medium text-brand-text">3 · {{ t('social_commerce.connect.step_3') }}</li>
        </ol>

        <section class="card">
            <SkeletonRows v-if="found.loading.value && !found.data.value" :rows="3" />
            <ErrorState v-else-if="found.error.value" compact :error="found.error.value" @retry="router.replace({ name: 'social-pages' })" />
            <EmptyState v-else-if="!pages.length" :icon="Facebook" :title="t('social_commerce.connect.none')" :text="t('social_commerce.connect.none_text')" />
            <form v-else @submit.prevent="save">
                <ul class="divide-y divide-line">
                    <li v-for="page in pages" :key="page.page_id">
                        <label class="flex items-center gap-4 px-5 py-4" :class="page.taken ? 'opacity-60' : 'cursor-pointer hover:bg-surface-2'">
                            <input v-model="chosen" type="checkbox" :value="page.page_id" :disabled="page.taken" class="size-4" />
                            <img v-if="page.picture_url" :src="page.picture_url" alt="" class="size-10 rounded-full border border-line object-cover" referrerpolicy="no-referrer" />
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ page.name }}</span>
                                <span v-if="page.taken" class="block text-[12.5px] text-bad">{{ t('social_commerce.connect.taken') }}</span>
                                <span v-else-if="!page.can_post" class="flex items-center gap-1 text-[12.5px] text-warn"><TriangleAlert class="size-3.5" aria-hidden="true" />{{ t('social_commerce.connect.cannot_post') }}</span>
                                <span v-else-if="page.connected_here" class="block text-[12.5px] text-muted">{{ t('social_commerce.connect.renew') }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <div class="flex justify-end gap-2 border-t border-line px-5 py-4">
                    <AppButton :to="{ name: 'social-pages' }">{{ t('social_commerce.connect.cancel') }}</AppButton>
                    <AppButton type="submit" variant="primary" :loading="busy" :disabled="!chosen.length">{{ t('social_commerce.connect.save', { count: chosen.length }) }}</AppButton>
                </div>
            </form>
        </section>
    </PageFrame>
</template>
