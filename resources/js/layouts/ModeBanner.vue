<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { CreditCard, Download, Eye, LifeBuoy, LogOut } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { enterContext, session } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Says clearly when this workspace is limited: support access (read-only,
 * with the time left), a bill overdue past its grace period (read-only until
 * paid), or a suspended provider (read-only grace period, then
 * export only). The server enforces all of it; this only explains.
 */
const router = useRouter();
const context = computed(() => session.me?.context ?? null);
const mode = computed(() => context.value?.mode ?? 'normal');
const reason = computed(() => context.value?.mode_reason ?? null);

const now = ref(Date.now());
let timer;
onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 15000)));
onBeforeUnmount(() => clearInterval(timer));

const until = computed(() => (context.value?.mode_until ? new Date(context.value.mode_until).getTime() : null));
const minutesLeft = computed(() => (until.value === null ? null : Math.max(0, Math.ceil((until.value - now.value) / 60000))));
const daysLeft = computed(() => (until.value === null ? null : Math.max(0, Math.ceil((until.value - now.value) / 86400000))));

const leaving = ref(false);

async function leave() {
    leaving.value = true;
    try {
        await enterContext({ partner_id: context.value.partner.id });
        await router.push('/partner/support');
    } catch (error) {
        toast.error(error.message);
    } finally {
        leaving.value = false;
    }
}
</script>

<template>
    <div
        v-if="mode !== 'normal'"
        class="border-b px-4 py-2.5 sm:px-6 lg:px-8"
        :class="reason === 'support' ? 'border-brand/25 bg-brand-soft' : 'border-warn/25 bg-warn-soft'"
        role="status"
    >
        <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-x-3 gap-y-2 text-[13px] text-fg-2">
            <template v-if="reason === 'support'">
                <LifeBuoy class="size-4 shrink-0 text-brand-text" aria-hidden="true" />
                <span class="flex-1">
                    {{ t('core.mode.support', { org: context.name }) }}
                    <strong v-if="minutesLeft !== null" class="font-semibold">{{ t('core.mode.minutes_left', { count: minutesLeft, minutes: formatNumber(minutesLeft) }) }}</strong>
                </span>
                <AppButton size="sm" :icon="LogOut" :loading="leaving" @click="leave">{{ t('core.mode.leave') }}</AppButton>
            </template>
            <template v-else-if="reason === 'payment_overdue'">
                <CreditCard class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <span class="flex-1">{{ t('core.mode.payment_overdue') }}</span>
                <AppButton size="sm" variant="primary" to="/billing">{{ t('core.mode.pay_now') }}</AppButton>
            </template>
            <template v-else-if="mode === 'read_only'">
                <Eye class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <span class="flex-1">
                    {{ t('core.mode.suspended_read_only') }}
                    <strong v-if="daysLeft !== null" class="font-semibold">{{ t('core.mode.days_left', { count: daysLeft, days: formatNumber(daysLeft) }) }}</strong>
                </span>
                <AppButton size="sm" :icon="Download" to="/export">{{ t('core.mode.export') }}</AppButton>
            </template>
            <template v-else>
                <Download class="size-4 shrink-0 text-warn" aria-hidden="true" />
                <span class="flex-1">{{ t('core.mode.export_only') }}</span>
            </template>
        </div>
    </div>
</template>
