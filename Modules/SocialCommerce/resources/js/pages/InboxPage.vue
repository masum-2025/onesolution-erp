<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Bot, Check, Hand, Inbox, MessageCircle, MessagesSquare, RotateCcw, Send } from 'lucide-vue-next';
import { AppButton, EmptyState, ErrorState, newOpId, PageFrame, SkeletonRows, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { socialApi, THREAD_TONES } from '../api';

/**
 * Comments and Messenger chats of the company's pages: the bot's answers
 * (marked as such) and the ones waiting for a person. Pick a conversation,
 * answer it, take it or finish it.
 */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const status = ref(route.query.status ?? 'waiting');
const list = useResource(() => social.threads(status.value ? { status: status.value } : {}));
const threads = computed(() => list.data.value?.data ?? []);
const waiting = computed(() => list.data.value?.meta?.waiting ?? 0);
const chosenId = ref(route.query.thread ?? null);
const chosen = useResource(() => (chosenId.value ? social.thread(chosenId.value) : Promise.resolve({ data: null })));
const thread = computed(() => chosen.data.value?.data ?? null);
const busy = ref(false);
const draft = reactive({ text: '', opId: newOpId() });

watch(status, () => {
    router.replace({ query: { ...route.query, status: status.value || undefined } });
    list.reload();
});
function open(id) {
    chosenId.value = id;
    router.replace({ query: { ...route.query, thread: id } });
    chosen.reload();
}

async function run(call, after) {
    busy.value = true;
    try {
        const response = await call();
        chosen.data.value = { data: response.data };
        if (response.message) toast.success(response.message);
        after?.();
        list.reload();
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) chosen.reload();
    } finally {
        busy.value = false;
    }
}
const send = () => run(() => social.reply(thread.value.id, { text: draft.text.trim(), op_id: draft.opId }), () => Object.assign(draft, { text: '', opId: newOpId() }));
const mark = (step) => run(() => social.mark(thread.value.id, step, { base_version: thread.value.version }));

const STATUSES = ['waiting', 'open', 'done', ''];
const senderLabel = (message) => (message.sender === 'bot' ? t('social_commerce.inbox.bot') : message.sender === 'person' ? message.by ?? t('social_commerce.inbox.team') : thread.value?.customer ?? t('social_commerce.inbox.customer'));
</script>

<template>
    <PageFrame :title="t('social_commerce.inbox.title')" :description="t('social_commerce.inbox.text')" width="full">
        <div class="grid gap-4 lg:grid-cols-[360px_minmax(0,1fr)]">
            <section class="card min-w-0">
                <div class="flex flex-wrap gap-1 border-b border-line p-2" role="tablist">
                    <button v-for="value in STATUSES" :key="value || 'all'" type="button" role="tab" :aria-selected="status === value"
                        class="rounded-lg px-3 py-1.5 text-[13px]" :class="status === value ? 'bg-brand-soft font-medium text-brand-text' : 'text-muted hover:bg-surface-2'" @click="status = value">
                        {{ value ? t(`social_commerce.inbox.status.${value}`) : t('social_commerce.inbox.all') }}
                        <span v-if="value === 'waiting' && waiting" class="ms-1 rounded-full bg-warn px-1.5 text-[11px] text-white">{{ waiting }}</span>
                    </button>
                </div>
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!threads.length" :icon="Inbox" :title="t('social_commerce.inbox.empty')" :text="t('social_commerce.inbox.empty_text')" compact />
                <ul v-else class="max-h-[70vh] divide-y divide-line overflow-auto">
                    <li v-for="item in threads" :key="item.id">
                        <button type="button" class="flex w-full gap-3 px-4 py-3 text-start hover:bg-surface-2" :class="item.id === chosenId ? 'bg-surface-2' : ''" @click="open(item.id)">
                            <component :is="item.kind === 'comment' ? MessageCircle : MessagesSquare" class="mt-0.5 size-4 shrink-0 text-muted" aria-hidden="true" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2">
                                    <span class="truncate text-[13.5px] font-medium">{{ item.customer ?? t('social_commerce.inbox.customer') }}</span>
                                    <StatusBadge :status="item.status" :tones="THREAD_TONES">{{ t(`social_commerce.inbox.status.${item.status}`) }}</StatusBadge>
                                </span>
                                <span class="block truncate text-[12.5px] text-muted">{{ item.last?.text }}</span>
                                <span class="block text-[11.5px] text-faint">{{ item.page }} · {{ formatDateTime(item.last_in_at) }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>

            <section class="card flex min-h-[60vh] min-w-0 flex-col">
                <EmptyState v-if="!chosenId" :icon="MessagesSquare" :title="t('social_commerce.inbox.pick')" :text="t('social_commerce.inbox.pick_text')" />
                <SkeletonRows v-else-if="chosen.loading.value && !thread" :rows="6" />
                <ErrorState v-else-if="chosen.error.value" :error="chosen.error.value" @retry="chosen.reload()" />
                <template v-else-if="thread">
                    <header class="flex flex-wrap items-center gap-3 border-b border-line px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block font-medium">{{ thread.customer ?? t('social_commerce.inbox.customer') }}</span>
                            <span class="block text-[12.5px] text-muted">{{ t(`social_commerce.inbox.kind.${thread.kind}`) }} · {{ thread.page?.name }}<template v-if="thread.assigned_to"> · {{ t('social_commerce.inbox.with', { name: thread.assigned_to }) }}</template></span>
                        </span>
                        <AppButton v-if="!thread.mine && thread.status !== 'done'" size="sm" :icon="Hand" :disabled="busy" @click="mark('claim')">{{ t('social_commerce.inbox.claim') }}</AppButton>
                        <AppButton v-if="thread.status !== 'done'" size="sm" :icon="Check" :disabled="busy" @click="mark('done')">{{ t('social_commerce.inbox.done') }}</AppButton>
                        <AppButton v-else size="sm" :icon="RotateCcw" :disabled="busy" @click="mark('reopen')">{{ t('social_commerce.inbox.reopen') }}</AppButton>
                    </header>
                    <ol class="flex-1 space-y-3 overflow-auto px-5 py-4" :aria-label="t('social_commerce.inbox.messages')">
                        <li v-for="message in thread.messages" :key="message.id" class="flex" :class="message.direction === 'out' ? 'justify-end' : 'justify-start'">
                            <div class="max-w-[80%] rounded-2xl px-4 py-2 text-[13.5px]"
                                :class="message.direction === 'in' ? 'bg-surface-2' : message.sender === 'bot' ? 'border border-brand/30 bg-brand-soft' : 'bg-brand text-brand-fg'">
                                <p class="mb-0.5 flex items-center gap-1 text-[11.5px] font-medium opacity-80">
                                    <Bot v-if="message.sender === 'bot'" class="size-3.5" aria-hidden="true" />{{ senderLabel(message) }}
                                    <span v-if="message.direction === 'out'">· {{ t(`social_commerce.inbox.channel.${message.channel}`) }}</span>
                                </p>
                                <p class="whitespace-pre-line">{{ message.text }}</p>
                                <p v-if="message.products.length" class="mt-1 text-[11.5px] opacity-80">{{ message.products.join(', ') }}</p>
                                <p v-if="message.status === 'failed'" class="mt-1 text-[12px] font-medium text-bad">{{ t('social_commerce.inbox.failed') }} {{ message.error }}</p>
                                <p class="mt-0.5 text-end text-[11px] opacity-60">{{ formatDateTime(message.at) }}</p>
                            </div>
                        </li>
                    </ol>
                    <form class="flex items-end gap-2 border-t border-line p-3" @submit.prevent="send">
                        <label class="sr-only" :for="`reply-${thread.id}`">{{ t('social_commerce.inbox.reply') }}</label>
                        <textarea :id="`reply-${thread.id}`" v-model="draft.text" class="field-input min-h-11 flex-1" rows="2" maxlength="2000" :placeholder="thread.kind === 'comment' ? t('social_commerce.inbox.reply_comment') : t('social_commerce.inbox.reply_message')" :disabled="!thread.page?.usable" />
                        <AppButton type="submit" variant="primary" :icon="Send" :loading="busy" :disabled="!draft.text.trim() || !thread.page?.usable">{{ t('social_commerce.inbox.send') }}</AppButton>
                    </form>
                </template>
            </section>
        </div>
    </PageFrame>
</template>
