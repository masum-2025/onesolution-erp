<script setup>
import { computed, onMounted } from 'vue';
import { ArrowRight, History, RotateCcw } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatBounds, formatRuleValue, sameValue } from '@/lib/ruleValues';
import { formatDateTime, formatRelative } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * Append-only history of this level's values, newest first, with a
 * before/after view and "restore this version".
 */
const props = defineProps({
    rule: { type: Object, required: true },
    adapter: { type: Object, required: true },
    canRollback: Boolean,
});

const emit = defineEmits(['rollback']);

const history = useResource(() => props.adapter.history(props.rule.key), { immediate: false });
onMounted(() => history.reload());

const TONES = { created: 'brand', approved: 'ok', activated: 'ok', rejected: 'bad', closed: 'neutral' };

const display = (mode, value) => (mode === 'constrain' ? formatBounds(props.rule, value) : formatRuleValue(props.rule, value));

// For each new version, the version before it in the same slot (value vs limits).
const entries = computed(() => {
    const list = history.data.value ?? [];
    return list.map((entry, index) => {
        let previous = null;
        if (entry.action === 'created') {
            const slot = entry.snapshot?.mode === 'constrain' ? 'constrain' : 'value';
            previous = list.slice(index + 1).find((older) => older.action === 'created' && (older.snapshot?.mode === 'constrain' ? 'constrain' : 'value') === slot) ?? null;
        }
        const changed = previous && !sameValue(previous.snapshot?.value, entry.snapshot?.value);
        return { ...entry, previous: changed ? previous : null };
    });
});

const currentVersions = computed(() => new Set([props.rule.own.value?.version, props.rule.own.constraint?.version].filter(Boolean)));

defineExpose({ reload: () => history.reload() });
</script>

<template>
    <div class="px-5 py-5 sm:px-6">
        <SkeletonRows v-if="history.loading.value && !history.data.value" :rows="4" />
        <ErrorState v-else-if="history.error.value" compact :error="history.error.value" @retry="history.reload()" />
        <EmptyState v-else-if="!entries.length" compact :icon="History" :title="t('rules.history.empty_title')" :text="t('rules.history.empty_text')" />

        <ol v-else class="relative">
            <li v-for="(entry, index) in entries" :key="entry.id" class="relative flex gap-3.5 pb-5 last:pb-0">
                <span v-if="index < entries.length - 1" class="absolute start-[7px] top-5 bottom-0 w-px bg-line" aria-hidden="true" />
                <span
                    class="relative z-10 mt-1.5 size-[15px] shrink-0 rounded-full border-[3px] border-raised"
                    :class="{ 'bg-brand': entry.action === 'created', 'bg-ok': ['approved', 'activated'].includes(entry.action), 'bg-bad': entry.action === 'rejected', 'bg-line-strong': entry.action === 'closed' }"
                    aria-hidden="true"
                />
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <AppBadge :tone="TONES[entry.action] ?? 'neutral'">{{ t(`rules.history.actions.${entry.action}`) }}</AppBadge>
                        <span class="text-[12.5px] text-muted">{{ t(`rules.modes.${entry.snapshot?.mode ?? 'set'}`) }}</span>
                        <span v-if="entry.snapshot?.version" class="tabular text-[12px] text-faint">v{{ entry.snapshot.version }}</span>
                        <span class="ms-auto text-[12px] text-faint" :title="formatDateTime(entry.created_at)">{{ formatRelative(entry.created_at) }}</span>
                    </div>

                    <div v-if="entry.action === 'created'" class="mt-2 flex flex-wrap items-center gap-2 text-[13px]">
                        <template v-if="entry.previous">
                            <span class="rounded-md bg-bad-soft px-2 py-0.5 text-bad line-through decoration-bad/40">{{ display(entry.previous.snapshot.mode, entry.previous.snapshot.value) }}</span>
                            <ArrowRight class="size-3.5 text-faint rtl:rotate-180" aria-hidden="true" />
                        </template>
                        <span class="rounded-md bg-ok-soft px-2 py-0.5 font-medium text-ok">{{ display(entry.snapshot?.mode, entry.snapshot?.value) }}</span>
                        <span v-if="entry.snapshot?.country_code" class="text-[12px] text-muted">· {{ entry.snapshot.country_code }}</span>
                    </div>

                    <p v-if="entry.reason" class="mt-2 border-s-2 border-line ps-3 text-[13px] leading-relaxed text-fg-2">{{ entry.reason }}</p>
                    <div class="mt-1.5 flex items-center justify-between gap-2">
                        <p class="text-[12px] text-muted">{{ entry.actor_name ? t('rules.history.by', { name: entry.actor_name }) : t('rules.history.by_system') }}</p>
                        <AppButton
                            v-if="canRollback && entry.action === 'created' && entry.snapshot?.version && !currentVersions.has(entry.snapshot.version)"
                            variant="ghost"
                            size="sm"
                            :icon="RotateCcw"
                            @click="emit('rollback', entry)"
                        >
                            {{ t('rules.history.restore') }}
                        </AppButton>
                    </div>
                </div>
            </li>
        </ol>
    </div>
</template>
