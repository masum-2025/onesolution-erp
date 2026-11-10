<script setup>
import { computed, ref } from 'vue';
import { AlertTriangle, ExternalLink, Lightbulb, PlayCircle, Settings2 } from 'lucide-vue-next';
import AppDrawer from '@/components/AppDrawer.vue';
import { guideContent, quietGuides, safeVideo } from '@/lib/guide';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

// The guide's text, as text: translations are never rendered as HTML.
const props = defineProps({
    open: Boolean,
    guide: { type: String, required: true },
    settings: { type: [Object, String], default: null },
    video: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const content = computed(() => guideContent(props.guide));
const videoUrl = computed(() => safeVideo(props.video));
const quiet = ref(false);

function stopReminders() {
    quietGuides();
    quiet.value = true;
}
</script>

<template>
    <AppDrawer :open="open && !!content" sheet :title="content?.title ?? ''" width="sm:max-w-[440px]" @close="emit('close')">
        <div v-if="content" class="space-y-6 px-5 py-4 text-[14px] leading-relaxed text-fg" data-guide-drawer>
            <ol class="space-y-3">
                <li v-for="(step, index) in content.steps" :key="index" class="flex gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full bg-brand-soft text-[12px] font-semibold text-brand-text" aria-hidden="true">{{ formatNumber(index + 1) }}</span>
                    <span class="pt-0.5">{{ step }}</span>
                </li>
            </ol>

            <section v-if="content.tips.length" class="rounded-xl bg-subtle p-3.5">
                <h3 class="mb-1.5 flex items-center gap-1.5 text-[13px] font-semibold"><Lightbulb class="size-4 text-brand-text" aria-hidden="true" />{{ t('core.guide.tips') }}</h3>
                <ul class="list-disc space-y-1 ps-5 text-[13.5px]">
                    <li v-for="(tip, index) in content.tips" :key="index">{{ tip }}</li>
                </ul>
            </section>

            <section v-if="content.mistakes.length" class="rounded-xl border border-line p-3.5">
                <h3 class="mb-1.5 flex items-center gap-1.5 text-[13px] font-semibold"><AlertTriangle class="size-4 text-warn" aria-hidden="true" />{{ t('core.guide.mistakes') }}</h3>
                <ul class="list-disc space-y-1 ps-5 text-[13.5px]">
                    <li v-for="(mistake, index) in content.mistakes" :key="index">{{ mistake }}</li>
                </ul>
            </section>

            <section v-if="content.settings || settings" class="space-y-2">
                <h3 class="flex items-center gap-1.5 text-[13px] font-semibold"><Settings2 class="size-4 text-muted" aria-hidden="true" />{{ t('core.guide.settings') }}</h3>
                <p v-if="content.settings" class="text-[13.5px] text-muted">{{ content.settings }}</p>
                <RouterLink v-if="settings" :to="settings" class="inline-flex items-center gap-1 text-[13.5px] font-medium text-brand-text hover:underline" data-guide-settings @click="emit('close')">
                    {{ t('core.guide.open_settings') }}
                </RouterLink>
            </section>

            <a v-if="videoUrl" :href="videoUrl" target="_blank" rel="noopener noreferrer" class="inline-flex items-center gap-1.5 text-[13.5px] font-medium text-brand-text hover:underline" data-guide-video>
                <PlayCircle class="size-4" aria-hidden="true" />{{ t('core.guide.video') }}<ExternalLink class="size-3.5" aria-hidden="true" />
            </a>
        </div>
        <template #footer>
            <div class="flex items-center justify-between gap-3 border-t border-line px-5 py-3 text-[13px]">
                <span v-if="quiet" class="text-muted" role="status">{{ t('core.guide.quiet_done') }}</span>
                <button v-else type="button" class="text-muted hover:text-fg hover:underline" data-guide-quiet @click="stopReminders">{{ t('core.guide.quiet') }}</button>
                <button type="button" class="rounded-lg bg-brand px-3 py-1.5 font-medium text-brand-fg hover:bg-brand-hover" @click="emit('close')">{{ t('core.guide.got_it') }}</button>
            </div>
        </template>
    </AppDrawer>
</template>
