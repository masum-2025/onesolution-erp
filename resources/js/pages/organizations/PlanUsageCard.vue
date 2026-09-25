<script setup>
import { computed, ref } from 'vue';
import { ArrowUpRight, CircleAlert, Gauge, PackageCheck } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { usageShare, usageTone } from '@/lib/packaging';
import { formatDate, formatNumber } from '@/lib/format';
import { can } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The subscription this unit belongs to: plan, limits in use (whole tree),
 * and the sector package the company started with.
 */
const props = defineProps({
    organization: { type: Object, required: true },
});

const usage = useResource(() => api(`/api/organizations/${props.organization.id}/usage`).then((response) => response.data));
const data = computed(() => usage.data.value);
const applying = ref(false);

const TONES = { ok: 'bg-brand', warn: 'bg-warn', full: 'bg-bad' };

// The company's sector has a package that was never applied (e.g. the sector changed).
const canApply = computed(
    () =>
        props.organization.type === 'company' &&
        props.organization.sector_key &&
        can('organizations.manage') &&
        data.value &&
        data.value.package?.package?.key !== props.organization.sector_key,
);

function limitText(limit) {
    if (limit.used === null) return t('packaging.usage.not_measured');
    if (limit.max === null) return t('packaging.usage.used_unlimited', { used: formatNumber(limit.used) });
    return t('packaging.usage.used_of', { used: formatNumber(limit.used), max: formatNumber(limit.max) });
}

async function applyPackage() {
    applying.value = true;
    try {
        const response = await api(`/api/organizations/${props.organization.id}/sector-package`, { method: 'POST' });
        toast.success(response.message);
        usage.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        applying.value = false;
    }
}
</script>

<template>
    <section class="card">
        <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-4">
            <h2 class="flex items-center gap-2 text-[14.5px] font-semibold text-fg">
                <Gauge class="size-4 text-brand-text" aria-hidden="true" />{{ t('packaging.usage.title') }}
            </h2>
            <AppBadge v-if="data" tone="brand">{{ data.plan.name }}</AppBadge>
        </header>

        <div v-if="usage.loading.value && !data" class="space-y-4 px-5 py-5" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 3" :key="n" class="space-y-2"><div class="skeleton h-3.5 w-1/3" /><div class="skeleton h-2 w-full" /></div>
        </div>
        <ErrorState v-else-if="usage.error.value" compact :error="usage.error.value" @retry="usage.reload()" />

        <div v-else-if="data" class="space-y-5 px-5 py-5">
            <p v-if="data.subscription.id !== organization.id" class="text-[12.5px] text-muted">
                {{ t('packaging.usage.shared', { name: data.subscription.name }) }}
            </p>

            <ul class="space-y-4">
                <li v-for="limit in data.limits" :key="limit.limit">
                    <div class="mb-1.5 flex items-baseline justify-between gap-3 text-[13px]">
                        <span class="font-medium text-fg">{{ t(`packaging.limits.${limit.limit}`) }}</span>
                        <span class="tabular text-muted">{{ limitText(limit) }}</span>
                    </div>
                    <div
                        v-if="usageShare(limit) !== null"
                        class="h-2 overflow-hidden rounded-full bg-subtle"
                        role="meter"
                        :aria-label="t(`packaging.limits.${limit.limit}`)"
                        aria-valuemin="0"
                        :aria-valuemax="limit.max"
                        :aria-valuenow="limit.used"
                    >
                        <div class="h-full rounded-full transition-[width] duration-500" :class="TONES[usageTone(limit)]" :style="{ width: `${Math.max(2, usageShare(limit) * 100)}%` }" />
                    </div>
                    <p v-if="usageTone(limit) !== 'ok' && limit.upgrade.length" class="mt-1.5 flex items-center gap-1.5 text-[12px]" :class="usageTone(limit) === 'full' ? 'text-bad' : 'text-warn'">
                        <CircleAlert class="size-3.5 shrink-0" aria-hidden="true" />
                        {{
                            t(usageTone(limit) === 'full' ? 'packaging.usage.full' : 'packaging.usage.nearly_full', {
                                plan: limit.upgrade[0].name,
                                max: limit.upgrade[0].max === null ? t('packaging.unlimited') : formatNumber(limit.upgrade[0].max),
                            })
                        }}
                    </p>
                </li>
            </ul>

            <div v-if="data.modules_not_included.length" class="rounded-xl bg-subtle p-3.5">
                <p class="flex items-center gap-1.5 text-[12.5px] font-medium text-fg-2">
                    <ArrowUpRight class="size-3.5" aria-hidden="true" />{{ t('packaging.usage.higher_plans') }}
                </p>
                <p class="mt-1 text-[12.5px] leading-relaxed text-muted">{{ data.modules_not_included.map((module) => module.name).join(', ') }}</p>
            </div>

            <div v-if="data.package" class="flex items-start gap-2.5 text-[12.5px] text-muted">
                <PackageCheck class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />
                <span>
                    {{ t('packaging.usage.package', { name: data.package.package.name, date: formatDate(data.package.applied_at) }) }}
                    <span class="block">
                        {{ t('packaging.usage.package_counts', { modules: formatNumber(data.package.modules_enabled.length), roles: formatNumber(data.package.roles_created.length), rules: formatNumber(data.package.rules_set.length) }) }}
                    </span>
                </span>
            </div>

            <AppButton v-if="canApply" size="sm" :icon="PackageCheck" :loading="applying" @click="applyPackage">{{ t('packaging.usage.apply') }}</AppButton>

            <p class="text-[12px] text-faint">{{ t('packaging.usage.who_changes') }}</p>
        </div>
    </section>
</template>
