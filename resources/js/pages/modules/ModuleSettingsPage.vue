<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowRight, Blocks, LayoutDashboard, SlidersHorizontal } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import RulesBrowser from '@/pages/rules/RulesBrowser.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { organizationRules } from '@/lib/rulesApi';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * A module's settings page (every module has one): its own setup screens,
 * then its rules with where each value comes from and who may change it
 * (the same editor as the rules screen, limited to this module).
 */
const route = useRoute();
const moduleKey = computed(() => route.params.module);
const org = currentOrganization();

const settings = useResource(() => api(`/api/modules/${moduleKey.value}/settings`).then((response) => response.data));
const data = computed(() => settings.data.value);
const adapter = computed(() => organizationRules(org.id, { module: moduleKey.value }));
</script>

<template>
    <div>
        <PageHeader :title="data ? t('dashboard.settings_title', { module: data.module.name }) : t('dashboard.settings')" :description="t('dashboard.settings_text')">
            <template #actions>
                <AppButton :to="`/m/${moduleKey}`" :icon="LayoutDashboard">{{ t('dashboard.title') }}</AppButton>
                <AppButton v-if="data?.can_manage_modules" to="/modules" :icon="Blocks">{{ t('dashboard.on_off') }}</AppButton>
            </template>
        </PageHeader>

        <ErrorState v-if="settings.error.value" :error="settings.error.value" @retry="settings.reload()" />
        <div v-else-if="!data" class="skeleton h-24 rounded-2xl" role="status" :aria-label="t('core.states.loading')" />

        <template v-else>
            <section v-if="data.pages.length" class="mb-8" aria-labelledby="module-setup">
                <h2 id="module-setup" class="mb-3 text-[14.5px] font-semibold text-fg">{{ t('dashboard.setup') }}</h2>
                <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <li v-for="page in data.pages" :key="page.key">
                        <RouterLink :to="page.route" class="card group flex items-center gap-3.5 p-4 transition hover:border-line-strong hover:shadow-pop">
                            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text">
                                <SlidersHorizontal class="size-[18px]" aria-hidden="true" />
                            </span>
                            <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-fg">{{ page.label }}</span>
                            <ArrowRight class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180" aria-hidden="true" />
                        </RouterLink>
                    </li>
                </ul>
            </section>

            <section aria-labelledby="module-rules">
                <div class="mb-3 flex items-baseline justify-between gap-3">
                    <h2 id="module-rules" class="text-[14.5px] font-semibold text-fg">{{ t('dashboard.rules') }}</h2>
                    <span class="tabular text-[12.5px] text-muted">{{ t('dashboard.rule_count', { count: formatNumber(data.rule_count) }) }}</span>
                </div>
                <p v-if="!data.rule_count" class="card p-5 text-[13px] text-muted">{{ t('dashboard.no_rules') }}</p>
                <RulesBrowser v-else :adapter="adapter" :scope-name="org.name" single />
            </section>
        </template>
    </div>
</template>
