<script setup>
import { computed, ref } from 'vue';
import { Blocks, Lock, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Partner console: a module on / off / "client decides" for every client,
 * optionally locked. Each change asks for a reason (audited).
 */
const modules = useResource(() => api('/api/partner/modules'));
const list = computed(() => modules.data.value?.data ?? []);
const canEdit = computed(() => modules.data.value?.can_edit === true);
const names = computed(() => Object.fromEntries(list.value.map((module) => [module.key, module.name])));
const query = ref('');
const busy = ref(null);

const shown = computed(() => {
    const needle = query.value.trim().toLocaleLowerCase();
    return list.value
        .filter((module) => !module.is_core)
        .filter((module) => !needle || module.name.toLocaleLowerCase().includes(needle))
        .sort((a, b) => Number(b.offered) - Number(a.offered) || a.name.localeCompare(b.name));
});

const states = computed(() => ['inherit', 'enabled', 'disabled'].map((value) => ({ value, label: t(`partner.modules.state.${value}`) })));

async function change(module, state, lock) {
    const answer = await confirmAction({
        title: t('partner.modules.confirm_title', { name: module.name }),
        message: t('partner.modules.confirm_text'),
        reason: 'required',
        danger: state === 'disabled',
        confirmLabel: t('core.actions.save'),
    });
    if (!answer) return;

    busy.value = module.key;
    try {
        const response = await api(`/api/partner/modules/${module.key}`, { method: 'PUT', body: { state, lock, reason: answer.reason } });
        const also = response.data.also_enabled ?? [];
        toast.success(`${t('partner.modules.saved', { name: module.name })} ${also.length ? t('partner.modules.also_on', { list: also.map((key) => names.value[key] ?? key).join(', ') }) : ''}`.trim());
        modules.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('partner.modules.title')" :description="t('partner.modules.text')" />

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="relative w-full sm:max-w-xs">
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="query" type="search" class="field-input h-9 min-h-9 ps-9" :placeholder="t('modules.search')" :aria-label="t('modules.search')" />
            </div>
            <p v-if="modules.data.value && !canEdit" class="flex items-center gap-1.5 text-[12.5px] text-muted">
                <Lock class="size-3.5" aria-hidden="true" />{{ t('partner.modules.read_only') }}
            </p>
        </div>

        <section class="card">
            <SkeletonRows v-if="modules.loading.value && !modules.data.value" :rows="6" avatar />
            <ErrorState v-else-if="modules.error.value" compact :error="modules.error.value" @retry="modules.reload()" />
            <EmptyState v-else-if="!shown.length" :icon="Blocks" :title="t('modules.empty_title')" :text="t('modules.empty_text')" compact />

            <ul v-else class="divide-y divide-line">
                <li v-for="module in shown" :key="module.key" class="flex flex-col gap-3 px-4 py-4 sm:flex-row sm:items-center sm:px-5" :class="module.offered ? '' : 'opacity-60'">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[13.5px] font-medium text-fg">
                            {{ module.name }}
                            <AppBadge v-if="!module.offered" tone="outline">{{ t('partner.modules.not_offered') }}</AppBadge>
                            <AppBadge v-if="module.locked" tone="brand" :icon="Lock">{{ t('partner.modules.locked') }}</AppBadge>
                        </p>
                        <p class="mt-0.5 text-[12.5px] text-muted">{{ module.description }}</p>
                        <p v-if="module.requires.length" class="mt-0.5 text-[12px] text-faint">{{ t('partner.modules.requires', { list: module.requires.map((key) => names[key] ?? key).join(', ') }) }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <AppSegmented
                            size="sm"
                            :model-value="module.state"
                            :options="states"
                            :label="module.name"
                            :class="!canEdit || !module.offered || busy === module.key ? 'pointer-events-none opacity-60' : ''"
                            @update:model-value="(state) => change(module, state, module.locked && state !== 'inherit')"
                        />
                        <AppSwitch
                            :model-value="module.locked"
                            :label="t('partner.modules.lock')"
                            show-label
                            :disabled="!canEdit || module.state === 'inherit' || busy === module.key"
                            @update:model-value="(lock) => change(module, module.state, lock)"
                        />
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
