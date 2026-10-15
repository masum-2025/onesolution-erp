<script setup>
import { reactive, watch } from 'vue';
import { ChevronRight } from 'lucide-vue-next';

/**
 * One card per module tab that has a summary (what is owed, the latest
 * result, this session's courses); a click opens that tab. Each card loads
 * on its own: one that fails or may not be seen just stays away.
 */
const props = defineProps({
    tabs: { type: Array, required: true },
    studentId: { type: String, required: true },
});
const emit = defineEmits(['open']);

// key => { state: 'loading' | 'ready' | 'hidden', value, hint, tone }
const cards = reactive({});

function load() {
    for (const tab of props.tabs.filter((item) => item.summary)) {
        cards[tab.key] = { state: 'loading' };
        tab.summary(props.studentId)
            .then((result) => {
                cards[tab.key] = result ? { state: 'ready', ...result } : { state: 'hidden' };
            })
            .catch(() => {
                cards[tab.key] = { state: 'hidden' };
            });
    }
}
watch(() => [props.studentId, props.tabs.map((tab) => tab.key).join()], load, { immediate: true });

const TONES = { ok: 'text-ok', warn: 'text-warn', bad: 'text-bad' };
</script>

<template>
    <div v-if="tabs.some((tab) => tab.summary && cards[tab.key]?.state !== 'hidden')" class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-3">
        <template v-for="tab in tabs" :key="tab.key">
            <div v-if="cards[tab.key]?.state === 'loading'" class="card h-[74px] animate-pulse bg-subtle/60" aria-hidden="true" />
            <button
                v-else-if="cards[tab.key]?.state === 'ready'"
                type="button"
                class="card group flex min-w-0 items-center gap-2 p-3 text-start sm:gap-3 sm:p-4 transition hover:border-brand/40 hover:shadow-sm"
                @click="emit('open', tab.key)"
            >
                <span class="min-w-0 flex-1">
                    <span class="block text-[12px] text-muted">{{ tab.label() }}</span>
                    <span class="tabular block truncate text-[17px] font-semibold leading-tight sm:text-[19px]" :class="TONES[cards[tab.key].tone] ?? 'text-fg'">{{ cards[tab.key].value }}</span>
                    <span class="block truncate text-[12px] text-muted">{{ cards[tab.key].hint }}</span>
                </span>
                <ChevronRight class="size-4 shrink-0 text-faint transition group-hover:text-fg rtl:rotate-180" aria-hidden="true" />
            </button>
        </template>
    </div>
</template>
