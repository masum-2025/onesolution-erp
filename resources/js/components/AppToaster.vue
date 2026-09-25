<script setup>
import { CircleCheck, CircleX, Info, X } from 'lucide-vue-next';
import { dismiss, toasts } from '@/lib/toast';
import { t } from '@/lib/i18n';

const ICONS = { success: CircleCheck, error: CircleX, info: Info };
const TINTS = { success: 'text-ok', error: 'text-bad', info: 'text-brand-text' };

async function run(toast) {
    dismiss(toast.id);
    await toast.action.run();
}
</script>

<template>
    <div
        class="pointer-events-none fixed inset-x-0 bottom-0 z-[60] flex flex-col items-center gap-2 p-4 pb-[max(1rem,env(safe-area-inset-bottom))] sm:inset-x-auto sm:end-0 sm:items-end"
        aria-live="polite"
        aria-atomic="false"
    >
        <TransitionGroup
            enter-active-class="transition duration-250 ease-[var(--ease-soft)]"
            enter-from-class="opacity-0 translate-y-2 scale-[0.98]"
            leave-active-class="transition duration-150"
            leave-to-class="opacity-0"
            move-class="transition duration-200"
        >
            <div
                v-for="item in toasts"
                :key="item.id"
                class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-xl border border-line bg-raised py-3 ps-3.5 pe-2 shadow-pop"
                :role="item.kind === 'error' ? 'alert' : 'status'"
            >
                <component :is="ICONS[item.kind]" class="mt-px size-[18px] shrink-0" :class="TINTS[item.kind]" aria-hidden="true" />
                <p class="min-w-0 flex-1 pt-px text-[13.5px] leading-snug text-fg">{{ item.message }}</p>
                <button
                    v-if="item.action"
                    type="button"
                    class="shrink-0 rounded-md px-2 py-0.5 text-[13px] font-semibold text-brand-text hover:bg-brand-soft"
                    @click="run(item)"
                >
                    {{ item.action.label }}
                </button>
                <button
                    type="button"
                    class="grid size-6 shrink-0 place-items-center rounded-md text-faint hover:bg-subtle hover:text-fg"
                    :aria-label="t('core.actions.dismiss')"
                    @click="dismiss(item.id)"
                >
                    <X class="size-3.5" aria-hidden="true" />
                </button>
            </div>
        </TransitionGroup>
    </div>
</template>
