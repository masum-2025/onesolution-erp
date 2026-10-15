<script setup>
import { computed, ref } from 'vue';
import { ArrowRight, ChevronDown, CircleCheck, Circle, CircleDashed, Rocket } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * What the institution still has to set up, step by step and module by
 * module, each with what is missing in words and a button to where it is
 * done. Done steps are ticked; optional ones help but do not count. Hidden
 * once everything needed is done; it can be folded away meanwhile.
 */
const props = defineProps({ list: { type: Object, required: true } });
const open = ref(true);
const percent = computed(() => (props.list.total ? Math.round((props.list.done / props.list.total) * 100) : 100));
const next = computed(() => props.list.groups.flatMap((group) => group.steps).find((step) => !step.done && !step.optional) ?? null);
</script>

<template>
    <section class="card mb-6 overflow-hidden" aria-labelledby="setup-checklist">
        <header class="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4">
            <span class="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><Rocket class="size-5" /></span>
            <div class="min-w-0 flex-1 basis-48">
                <h2 id="setup-checklist" class="text-[15px] font-semibold text-fg">{{ t('education.checklist.title') }}</h2>
                <p class="text-[12.5px] text-muted">{{ t('education.checklist.text', { done: formatNumber(list.done), total: formatNumber(list.total) }) }}</p>
            </div>
            <!-- On a phone the buttons take their own line under the text. -->
            <div class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <AppButton v-if="next" size="sm" variant="primary" :to="next.route" :icon-end="ArrowRight" class="max-w-full">{{ t('education.checklist.next', { step: next.label }) }}</AppButton>
                <AppButton size="sm" variant="ghost" :icon="ChevronDown" :aria-expanded="open" :class="open ? '[&_svg]:rotate-180' : ''" @click="open = !open">{{ t(open ? 'education.checklist.fold' : 'education.checklist.unfold') }}</AppButton>
            </div>
        </header>
        <div class="h-1.5 bg-subtle" role="progressbar" :aria-valuenow="percent" aria-valuemin="0" aria-valuemax="100" :aria-label="t('education.checklist.title')">
            <div class="h-full bg-brand transition-[width]" :style="{ inlineSize: `${percent}%` }" />
        </div>
        <div v-if="open" class="grid gap-x-8 gap-y-5 px-5 py-4 md:grid-cols-2">
            <div v-for="group in list.groups" :key="group.module">
                <h3 class="mb-2 text-[12px] font-semibold uppercase tracking-wide text-muted">{{ group.label }}</h3>
                <ol class="space-y-1">
                    <li v-for="step in group.steps" :key="step.key" class="flex items-start gap-3 rounded-lg px-2 py-2" :class="step.done ? '' : 'bg-subtle/50'">
                        <CircleCheck v-if="step.done" class="mt-0.5 size-[18px] shrink-0 text-ok" aria-hidden="true" />
                        <CircleDashed v-else-if="step.optional" class="mt-0.5 size-[18px] shrink-0 text-faint" aria-hidden="true" />
                        <Circle v-else class="mt-0.5 size-[18px] shrink-0 text-warn" aria-hidden="true" />
                        <span class="min-w-0 flex-1">
                            <span class="block text-[13.5px]" :class="step.done ? 'text-muted line-through decoration-faint' : 'font-medium text-fg'">
                                {{ step.label }}
                                <span v-if="step.optional && !step.done" class="ms-1 text-[11.5px] font-normal text-faint">{{ t('education.checklist.optional') }}</span>
                            </span>
                            <span class="sr-only">{{ t(step.done ? 'education.checklist.done' : 'education.checklist.todo') }}</span>
                            <span v-if="!step.done && step.detail" class="mt-0.5 block text-[12.5px] text-muted">{{ step.detail }}</span>
                        </span>
                        <AppButton v-if="!step.done" size="sm" variant="ghost" :to="step.route" :icon-end="ArrowRight" class="shrink-0">{{ t('education.checklist.do') }}</AppButton>
                    </li>
                </ol>
            </div>
        </div>
    </section>
</template>
