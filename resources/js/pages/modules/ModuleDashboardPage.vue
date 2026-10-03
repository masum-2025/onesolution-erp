<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Settings, Sparkles } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import DashboardWidget from '@/components/dashboard/DashboardWidget.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { moduleIconName } from '@/lib/menu';
import { t } from '@/lib/i18n';

/**
 * A module's dashboard (every module has one): its widgets, each loading on
 * its own. A module without widgets yet says so and points to its settings.
 */
const route = useRoute();
const moduleKey = computed(() => route.params.module);
const icon = ref(null);

const dashboard = useResource(() => api(`/api/modules/${moduleKey.value}/dashboard`));
const widgets = computed(() => dashboard.data.value?.data ?? []);
const module = computed(() => dashboard.data.value?.module);

watch(moduleKey, async (key) => {
    dashboard.reload();
    icon.value = await moduleIconName(key);
}, { immediate: false });
moduleIconName(moduleKey.value).then((name) => (icon.value = name));

// Stat cards fill a row; wider widgets (bars, lists) take two or three columns.
const SPAN = { 1: '', 2: 'md:col-span-2', 3: 'md:col-span-2 xl:col-span-3' };
</script>

<template>
    <div>
        <PageHeader :title="module?.name ?? t('dashboard.title')" :description="module?.description ?? ''">
            <template #eyebrow>
                <p class="mb-1 text-[12px] font-semibold tracking-[0.08em] text-brand-text uppercase">{{ t('dashboard.title') }}</p>
            </template>
            <template #actions>
                <AppButton :to="`/m/${moduleKey}/settings`" :icon="Settings">{{ t('dashboard.settings') }}</AppButton>
            </template>
        </PageHeader>

        <div v-if="dashboard.loading.value && !dashboard.data.value" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 3" :key="n" class="skeleton h-44 rounded-2xl" />
        </div>
        <ErrorState v-else-if="dashboard.error.value" :error="dashboard.error.value" @retry="dashboard.reload()" />

        <div v-else-if="widgets.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <DashboardWidget v-for="widget in widgets" :key="widget.key" :widget="widget" :icon="icon" :class="SPAN[widget.size]" />
        </div>

        <div v-else-if="dashboard.data.value" class="card">
            <EmptyState :icon="Sparkles" :title="t('dashboard.coming_title')" :text="t('dashboard.coming_text', { module: module?.name })">
                <AppButton :to="`/m/${moduleKey}/settings`" :icon="Settings">{{ t('dashboard.settings') }}</AppButton>
            </EmptyState>
        </div>
    </div>
</template>
