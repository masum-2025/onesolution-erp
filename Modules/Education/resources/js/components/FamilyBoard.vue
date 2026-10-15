<script setup>
import { computed, ref, watch } from 'vue';
import { ArrowRight, CheckCheck } from 'lucide-vue-next';
import DashboardWidget from '@/components/dashboard/DashboardWidget.vue';
import { api } from '@/lib/http';
import { attentionItems, attentionLoaded, refreshAttention } from '@/lib/attention';
import { moduleIconName } from '@/lib/menu';
import { formatNumber } from '@/lib/format';
import { loadNamespaces, t } from '@/lib/i18n';
import { familyWork } from '../lib';

/**
 * The education family on the home: the work waiting for this person in
 * Education and the modules made on it (the same items as the bell), and
 * those modules' dashboard widgets (fees today, coming exams, marks not
 * in, registrations to approve). Which modules: the server says (the ones
 * on here that require Education); each widget loads and fails on its own.
 */
const props = defineProps({ modules: { type: Array, required: true } });

loadNamespaces(['dashboard']);
refreshAttention();
const work = computed(() => familyWork(attentionItems.value, props.modules));

// Education's own numbers are the tiles above; the other modules' widgets come here.
const widgets = ref([]);
const icons = ref({});
const loading = ref(true);
watch(() => props.modules.join(), async () => {
    loading.value = true;
    const others = props.modules.filter((module) => module !== 'education');
    const found = await Promise.all(others.map(async (module) => {
        try {
            const [{ data, module: about }, icon] = await Promise.all([api(`/api/modules/${module}/dashboard`), moduleIconName(module)]);
            icons.value = { ...icons.value, [module]: icon };
            return data.map((widget) => ({ ...widget, module, module_name: about?.name ?? '' }));
        } catch {
            return [];
        }
    }));
    widgets.value = found.flat();
    loading.value = false;
}, { immediate: true });

const SPAN = { 1: '', 2: 'md:col-span-2', 3: 'md:col-span-2 xl:col-span-3' };
</script>

<template>
    <div class="mb-6 space-y-6">
        <!-- Work waiting for this person. -->
        <section class="card" aria-labelledby="education-work">
            <header class="border-b border-line px-5 py-3.5">
                <h2 id="education-work" class="text-[14.5px] font-semibold text-fg">{{ t('dashboard.needs_action') }}</h2>
                <p class="mt-0.5 text-[12.5px] text-muted">{{ t('education.home.work_text') }}</p>
            </header>
            <div v-if="!attentionLoaded" class="space-y-3 p-5" role="status" :aria-label="t('core.states.loading')">
                <div v-for="n in 2" :key="n" class="skeleton h-10" />
            </div>
            <p v-else-if="!work.length" class="flex items-center gap-3 px-5 py-4 text-[13.5px] text-muted">
                <span class="grid size-9 place-items-center rounded-xl bg-ok-soft text-ok" aria-hidden="true"><CheckCheck class="size-[18px]" /></span>
                {{ t('dashboard.all_clear') }}
            </p>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in work" :key="item.key">
                    <RouterLink :to="item.path" class="group flex items-center gap-3.5 px-5 py-3 transition-colors hover:bg-subtle">
                        <span
                            class="grid size-9 shrink-0 place-items-center rounded-xl"
                            :class="{ warn: 'bg-warn-soft text-warn', bad: 'bg-bad-soft text-bad' }[item.tone] ?? 'bg-brand-soft text-brand-text'"
                            aria-hidden="true"
                        >
                            <component :is="item.icon" class="size-[18px]" />
                        </span>
                        <span class="min-w-0 flex-1 truncate text-[13.5px] font-medium text-fg">{{ item.label }}</span>
                        <span class="tabular rounded-full bg-subtle px-2.5 py-0.5 text-[13px] font-semibold text-fg">{{ formatNumber(item.count) }}</span>
                        <ArrowRight class="size-4 shrink-0 text-faint transition group-hover:translate-x-0.5 group-hover:text-fg-2 rtl:rotate-180" aria-hidden="true" />
                    </RouterLink>
                </li>
            </ul>
        </section>

        <!-- The family's widgets. -->
        <div v-if="loading" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 3" :key="n" class="card skeleton min-h-[11rem]" />
        </div>
        <div v-else-if="widgets.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            <DashboardWidget v-for="widget in widgets" :key="`${widget.module}.${widget.key}`" :widget="widget" :icon="icons[widget.module]" :class="SPAN[widget.size]" show-module />
        </div>
    </div>
</template>
