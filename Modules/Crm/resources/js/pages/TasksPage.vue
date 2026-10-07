<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { CheckCircle2, Circle, ListChecks, Phone } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatNumber } from '@/lib/format';
import { can, currentOrganization, session } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { crmApi } from '../api';
import { phoneText } from '../lib';
import { useCrmSetup } from '../setup';

/**
 * My follow-ups: late, today, coming, done (the last 30 days). Tick one off
 * as done; call straight from the list. A manager can look at someone else's.
 */
const crm = crmApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useCrmSetup();
const when = ref(['overdue', 'today', 'upcoming', 'done'].includes(route.query.when) ? route.query.when : 'today');
const person = ref(route.query.person ?? '');
const list = useResource(() => crm.activities({ when: when.value, ...(person.value ? { assigned_to: person.value } : {}) }));
watch([when, person], () => {
    router.replace({ query: { when: when.value, ...(person.value ? { person: person.value } : {}) } });
    list.reload();
});
const tasks = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
const busy = ref(null);
async function toggle(task) {
    busy.value = task.id;
    try {
        await crm.updateActivity(task.id, { base_version: task.version, done: !task.done_at });
        toast.success(t(task.done_at ? 'crm.tasks.reopened' : 'crm.tasks.marked'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = null;
    }
}
const label = (value) => {
    const count = value === 'overdue' ? meta.value.overdue : value === 'today' ? meta.value.today : null;
    return count ? `${t(`crm.tasks.${value}`)} (${formatNumber(count)})` : t(`crm.tasks.${value}`);
};
</script>

<template>
    <div>
        <PageHeader :title="t('crm.tasks.title')" :description="t('crm.tasks.text')">
            <template #actions>
                <select v-if="can('crm.manage')" v-model="person" class="field-input w-auto" :aria-label="t('crm.tasks.person')">
                    <option value="">{{ t('crm.tasks.mine') }}</option>
                    <option v-for="row in (setup.data.value?.people ?? []).filter((row) => row.id !== session.me?.user?.id)" :key="row.id" :value="row.id">{{ row.name }}</option>
                </select>
            </template>
        </PageHeader>
        <AppSegmented v-model="when" class="mb-4" :label="t('crm.tasks.title')" :options="['overdue', 'today', 'upcoming', 'done'].map((value) => ({ value, label: label(value) }))" />

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!tasks.length" :icon="ListChecks" :title="t(`crm.tasks.empty_${when}`)" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="task in tasks" :key="task.id" class="flex items-start gap-3 px-5 py-3" :class="busy === task.id ? 'opacity-50' : ''">
                    <button type="button" class="mt-0.5 shrink-0 text-muted hover:text-ok" :aria-label="t(task.done_at ? 'crm.tasks.undo' : 'crm.tasks.done')" :disabled="!can('crm.edit')" @click="toggle(task)">
                        <CheckCircle2 v-if="task.done_at" class="size-5 text-ok" aria-hidden="true" /><Circle v-else class="size-5" aria-hidden="true" />
                    </button>
                    <div class="min-w-0 flex-1 text-[13.5px]">
                        <p class="font-medium" :class="task.done_at ? 'text-muted line-through' : ''">{{ task.subject }}</p>
                        <p class="mt-0.5 text-[12.5px] text-muted">
                            <RouterLink :to="{ name: 'crm-contact', params: { id: task.contact_id } }" class="text-brand-text hover:underline">{{ task.contact_name }}</RouterLink>
                            · {{ t(`crm.activity_kinds.${task.kind}`) }}
                        </p>
                        <p v-if="task.body" class="mt-1 whitespace-pre-line text-[12.5px] text-fg-2">{{ task.body }}</p>
                    </div>
                    <span class="flex shrink-0 flex-col items-end gap-1 text-[12px]">
                        <AppBadge :tone="when === 'overdue' ? 'bad' : when === 'today' ? 'warn' : 'neutral'">{{ formatDateTime(task.done_at ?? task.due_at) }}</AppBadge>
                        <a v-if="task.contact_phone && !task.done_at" :href="`tel:${task.contact_phone}`" class="inline-flex items-center gap-1 text-brand-text hover:underline" dir="ltr">
                            <Phone class="size-3.5" aria-hidden="true" />{{ phoneText(task.contact_phone) }}
                        </a>
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
