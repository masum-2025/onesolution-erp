<script setup>
import { computed, ref } from 'vue';
import { Check, ShieldCheck, ShieldX, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import OrgTypeIcon from '@/components/OrgTypeIcon.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { organizationRules } from '@/lib/rulesApi';
import { confirmAction } from '@/lib/dialogs';
import { emit as emitEvent } from '@/lib/events';
import { can, currentOrganization, session } from '@/lib/session';
import { formatDateTime, formatRelative } from '@/lib/format';
import { formatBounds, formatRuleValue } from '@/lib/ruleValues';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

const context = currentOrganization();
const orgId = ref(context.id);
const adapter = computed(() => organizationRules(orgId.value));

const approvals = useResource(() => (can('rules.approve') ? api(`/api/organizations/${orgId.value}/rule-approvals`).then((response) => response.data) : Promise.resolve([])));

// Values are shown as the server stored them; rule details give readable labels.
const rules = useResource(() =>
    adapter.value
        .list()
        .then((groups) => Object.fromEntries(groups.flatMap((group) => group.categories.flatMap((category) => category.rules)).map((rule) => [rule.key, rule])))
        .catch(() => ({})),
);

function display(item) {
    const rule = rules.data.value?.[item.rule];
    if (!rule) return JSON.stringify(item.value);
    return item.mode === 'constrain' ? formatBounds(rule, item.value) : formatRuleValue(rule, item.value);
}

const busy = ref(null);
const mine = (item) => item.requested_by?.id === session.me?.user?.id;

async function review(item, approve) {
    const answer = await confirmAction({
        title: approve ? t('rules.approvals.approve_title') : t('rules.approvals.reject_title'),
        message: t('rules.approvals.review_text', { rule: item.label, value: display(item) }),
        reason: approve ? 'optional' : 'required',
        danger: !approve,
        confirmLabel: approve ? t('rules.approvals.approve') : t('rules.approvals.reject'),
    });
    if (!answer) return;

    busy.value = item.id;
    try {
        await (approve ? adapter.value.approve : adapter.value.reject)(item.id, answer.reason ? { reason: answer.reason } : {});
        toast.success(approve ? t('rules.approvals.approved') : t('rules.approvals.rejected'));
        approvals.reload();
        emitEvent('approvals-changed');
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}

function reload() {
    approvals.reload();
    rules.reload();
}
</script>

<template>
    <div>
        <PageHeader :title="t('rules.approvals.title')" :description="t('rules.approvals.text')">
            <template #actions>
                <OrgPicker v-model="orgId" compact :label="t('core.org_picker.scope')" @update:model-value="reload" />
            </template>
        </PageHeader>

        <div v-if="approvals.loading.value && !approvals.data.value" class="card overflow-hidden"><SkeletonRows :rows="3" avatar /></div>
        <ErrorState v-else-if="approvals.error.value" :error="approvals.error.value" @retry="reload" />
        <div v-else-if="!approvals.data.value?.length" class="card">
            <EmptyState :icon="ShieldCheck" :title="t('rules.approvals.empty_title')" :text="t('rules.approvals.empty_text')" />
        </div>

        <ul v-else class="space-y-3">
            <li v-for="item in approvals.data.value" :key="item.id" class="card p-4 sm:p-5">
                <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                    <OrgTypeIcon :type="item.scope.level" size="lg" />
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <RouterLink
                                :to="{ path: '/rules', query: { rule: item.rule, ...(item.scope.id !== context.id ? { org: item.scope.id } : {}) } }"
                                class="text-[14.5px] font-semibold text-fg hover:text-brand-text"
                            >
                                {{ item.label }}
                            </RouterLink>
                            <AppBadge tone="neutral">{{ t(`rules.modes.${item.mode}`) }}</AppBadge>
                            <AppBadge v-if="item.country_code" tone="outline">{{ item.country_code }}</AppBadge>
                        </div>
                        <p class="mt-1 text-[12.5px] text-muted">
                            {{ item.scope.name ?? t(`core.levels.${item.scope.level}`) }} ·
                            {{ item.requested_by?.name ? t('rules.approvals.requested_by', { name: item.requested_by.name }) : t('rules.history.by_system') }}
                        </p>

                        <div class="mt-3 inline-flex max-w-full items-center gap-2 rounded-lg bg-subtle px-3 py-1.5 text-[13.5px]">
                            <span class="text-muted">{{ t('rules.approvals.new_value') }}</span>
                            <span class="tabular truncate font-semibold text-fg">{{ display(item) }}</span>
                        </div>
                        <p v-if="item.effective_from && new Date(item.effective_from) > new Date()" class="mt-2 text-[12.5px] text-muted">
                            {{ t('rules.approvals.starts', { date: formatDateTime(item.effective_from) }) }}
                        </p>
                        <p v-if="item.reason" class="mt-3 border-s-2 border-line ps-3 text-[13px] leading-relaxed text-fg-2">{{ item.reason }}</p>
                    </div>

                    <div class="flex shrink-0 flex-col items-stretch gap-2 sm:items-end">
                        <span class="text-[12px] text-faint" :title="formatDateTime(item.created_at)">{{ formatRelative(item.created_at) }}</span>
                        <p v-if="mine(item)" class="max-w-52 text-[12.5px] text-muted sm:text-end">{{ t('rules.approvals.own_request') }}</p>
                        <div v-else class="flex gap-2">
                            <AppButton variant="danger-soft" :icon="X" :disabled="busy !== null" @click="review(item, false)">{{ t('rules.approvals.reject') }}</AppButton>
                            <AppButton variant="primary" :icon="Check" :loading="busy === item.id" :disabled="busy !== null && busy !== item.id" @click="review(item, true)">
                                {{ t('rules.approvals.approve') }}
                            </AppButton>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <p v-if="!can('rules.approve')" class="mt-6 flex items-center justify-center gap-2 text-[12.5px] text-muted">
            <ShieldX class="size-4" aria-hidden="true" />
            {{ t('rules.approvals.no_access') }}
        </p>
    </div>
</template>
