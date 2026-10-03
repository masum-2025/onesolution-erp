<script setup>
import { computed } from 'vue';
import { Inbox, RotateCw } from 'lucide-vue-next';
import BarsWidget from './BarsWidget.vue';
import ListWidget from './ListWidget.vue';
import StatWidget from './StatWidget.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { moduleIcon } from '@/lib/icons';
import { t } from '@/lib/i18n';

/**
 * One dashboard card. It loads its own data, so a slow or failing widget
 * never holds up the others; loading, empty and error states are its own.
 */
const props = defineProps({
    widget: { type: Object, required: true },
    // The module's menu icon name, drawn as the card's emblem.
    icon: { type: String, default: null },
    // On the overview: also name the module.
    showModule: Boolean,
});

const resource = useResource(() => api(`/api/modules/${props.widget.module}/dashboard/${props.widget.key}`).then((response) => response.data));
const data = computed(() => resource.data.value);
const empty = computed(() => data.value && props.widget.type !== 'stat' && !data.value.items?.length);

const VIEWS = { stat: StatWidget, bars: BarsWidget, list: ListWidget };

defineExpose({ reload: () => resource.reload() });
</script>

<template>
    <section class="card widget relative flex min-h-[11rem] flex-col overflow-hidden p-5" :aria-labelledby="`widget-${widget.module}-${widget.key}`">
        <!-- Emblem in the corner: the module's icon on a soft glow of the highlight color. -->
        <span class="widget-glow pointer-events-none absolute -end-10 -top-10 size-32 rounded-full" aria-hidden="true" />
        <span class="absolute end-4 top-4 grid size-10 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true">
            <component :is="moduleIcon(icon)" class="size-5" />
        </span>

        <header class="relative mb-4 pe-12">
            <p v-if="showModule" class="text-[11px] font-semibold tracking-[0.08em] text-muted uppercase">{{ widget.module_name }}</p>
            <h3 :id="`widget-${widget.module}-${widget.key}`" class="text-[14px] font-semibold text-fg">{{ widget.label }}</h3>
        </header>

        <div class="relative flex-1">
            <div v-if="resource.loading.value && !data" class="space-y-3" role="status" :aria-label="t('core.states.loading')">
                <div class="skeleton h-8 w-20" />
                <div class="skeleton h-3 w-3/4" />
                <div class="skeleton h-3 w-1/2" />
            </div>
            <div v-else-if="resource.error.value" class="flex h-full flex-col items-start justify-center gap-2" role="alert">
                <p class="text-[13px] text-muted">{{ t('dashboard.widget_error') }}</p>
                <button type="button" class="inline-flex items-center gap-1.5 text-[13px] font-medium text-brand-text hover:underline" @click="resource.reload()">
                    <RotateCw class="size-3.5" aria-hidden="true" />{{ t('core.actions.retry') }}
                </button>
            </div>
            <div v-else-if="empty" class="flex h-full items-center gap-2.5 text-[13px] text-muted">
                <Inbox class="size-4 shrink-0" aria-hidden="true" />{{ t('dashboard.widget_empty') }}
            </div>
            <component :is="VIEWS[widget.type]" v-else-if="data" :label="widget.label" :data="data" />
        </div>
    </section>
</template>
