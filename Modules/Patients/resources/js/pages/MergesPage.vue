<script setup>
import { computed, ref, watch } from 'vue';
import { Check, GitMerge, X } from 'lucide-vue-next';
import { AppButton, AppField, AppSegmented, EmptyState, PageFrame } from '@/ui';
import AppBadge from '@/components/AppBadge.vue';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { patientsApi } from '../api';

/**
 * Requests to fold a duplicate patient into the record that stays: decided by
 * someone other than the person who asked. Approved, the duplicate's history
 * moves to the record that stays.
 */
const pt = patientsApi(currentOrganization().id);
const status = ref('pending');
const list = useResource(() => pt.merges({ status: status.value }));
watch(status, () => list.reload());
const merges = computed(() => list.data.value?.data ?? []);
const choices = computed(() => ['pending', 'approved', 'rejected'].map((value) => ({ value, label: t(`patients.merge.status.${value}`) })));
const tones = { pending: 'warn', approved: 'ok', rejected: 'neutral' };
const side = (row) => `${row.name ?? '—'} (${row.mrn ?? '—'})`;

const deciding = ref(null);
const note = ref('');
async function decide(approve) {
    try {
        await pt.decideMerge(deciding.value.id, { approve, ...(note.value.trim() ? { note: note.value.trim() } : {}) });
        toast.success(t(approve ? 'patients.merge.approved' : 'patients.merge.rejected'));
        deciding.value = null;
        note.value = '';
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('patients.merge.title')" :description="t('patients.merge.text')" width="lg" :resource="list" guide="patients.guide.merges">
        <template #top>
            <div class="mb-4 print:hidden"><AppSegmented v-model="status" :options="choices" :label="t('patients.merge.filter')" /></div>
        </template>
        <section class="card">
            <EmptyState v-if="!merges.length" :icon="GitMerge" :title="t('patients.merge.empty')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="row in merges" :key="row.id" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block text-[14px]"><span dir="auto">{{ side(row.source) }}</span> → <strong dir="auto">{{ side(row.target) }}</strong></span>
                        <span class="block text-[12.5px] text-muted">{{ row.requested_by_name }} · {{ formatDateTime(row.created_at) }} · {{ row.reason }}</span>
                        <span v-if="row.decision_note" class="block text-[12.5px] text-muted">{{ row.decided_by_name }}: {{ row.decision_note }}</span>
                    </span>
                    <AppBadge :tone="tones[row.status]" dot>{{ t(`patients.merge.status.${row.status}`) }}</AppBadge>
                    <AppButton v-if="row.status === 'pending' && !row.mine" size="sm" variant="primary" @click="deciding = row">{{ t('patients.merge.decide') }}</AppButton>
                    <span v-else-if="row.status === 'pending'" class="text-[12px] text-muted">{{ t('patients.merge.waiting') }}</span>
                </li>
            </ul>
        </section>

        <AppDialog :open="!!deciding" :title="t('patients.merge.decide')" :description="deciding ? t('patients.merge.decide_text', { from: side(deciding.source), into: side(deciding.target) }) : ''" :icon="GitMerge" @close="deciding = null">
            <AppField v-slot="{ id }" :label="t('patients.merge.note')" :hint="t('patients.merge.note_hint')">
                <input :id="id" v-model="note" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" :icon="X" :disabled="!note.trim()" @click="decide(false)">{{ t('patients.merge.reject') }}</AppButton>
                <AppButton variant="primary" :icon="Check" @click="decide(true)">{{ t('patients.merge.approve') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
