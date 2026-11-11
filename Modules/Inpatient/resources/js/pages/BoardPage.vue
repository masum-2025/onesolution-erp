<script setup>
import { computed, onBeforeUnmount } from 'vue';
import { useRouter } from 'vue-router';
import { Ban, BedDouble, Check, RefreshCw, Sparkles, UserRound } from 'lucide-vue-next';
import { AppButton, PageFrame, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { bedLooks, ipdApi } from '../api';

/**
 * The bed board: every bed by ward, its state in colour, word and icon (free,
 * occupied, cleaning, blocked). An occupied bed opens its admission; a free
 * bed starts an admission into it; a bed being cleaned is marked clean in one
 * tap. Fresh every minute.
 */
const ipd = ipdApi(currentOrganization().id);
const router = useRouter();
const board = useResource(() => ipd.board());
const timer = setInterval(() => board.reload(), 60000);
onBeforeUnmount(() => clearInterval(timer));
const wards = computed(() => board.data.value?.data ?? []);
const can = computed(() => board.data.value?.meta?.can ?? {});
const icons = { check: Check, user: UserRound, sparkles: Sparkles, ban: Ban };
const counts = computed(() => wards.value.flatMap((ward) => ward.beds).reduce((sum, bed) => ({ ...sum, [bed.status]: (sum[bed.status] ?? 0) + 1 }), {}));

async function open(bed) {
    if (bed.admission) {
        router.push({ name: 'inpatient-admission', params: { id: bed.admission.id } });
    } else if (bed.status === 'free' && can.value.admit) {
        router.push({ name: 'inpatient-admit', query: { bed: bed.id } });
    } else if (bed.status === 'cleaning' && can.value.admit) {
        try {
            await ipd.bedStatus(bed.id, { status: 'free', base_version: bed.version });
            toast.success(t('inpatient.board.cleaned', { code: bed.code }));
        } catch (error) {
            toast.error(error.message);
        }
        board.reload();
    }
}
</script>

<template>
    <PageFrame :title="t('inpatient.board.title')" :description="t('inpatient.board.text')" width="full" :resource="board" guide="inpatient.guide.board"
               :empty="{ when: !wards.length, icon: BedDouble, title: t('inpatient.board.empty'), text: can.manage ? t('inpatient.board.empty_text') : '' }">
        <template #actions>
            <AppButton variant="ghost" :icon="RefreshCw" :loading="board.loading.value" @click="board.reload()">{{ t('inpatient.common.refresh') }}</AppButton>
        </template>
        <template #top>
            <ul class="mb-4 flex flex-wrap gap-2 text-[12.5px]" :aria-label="t('inpatient.board.legend')">
                <li v-for="(look, status) in bedLooks" :key="status" class="flex items-center gap-1.5 rounded-full px-3 py-1 ring-1" :class="look.ring">
                    <component :is="icons[look.icon]" class="size-3.5" aria-hidden="true" />{{ t(`inpatient.bed_status.${status}`) }} · {{ counts[status] ?? 0 }}
                </li>
            </ul>
        </template>
        <div class="grid gap-4">
            <section v-for="ward in wards" :key="ward.id" class="card p-4">
                <h2 class="mb-3 text-[14px] font-semibold">{{ ward.name }} <span class="text-[12px] font-normal text-muted">· {{ t(`inpatient.kind.${ward.kind}`) }}</span></h2>
                <p v-if="!ward.beds.length" class="text-[12.5px] text-muted">{{ t('inpatient.board.no_beds') }}</p>
                <ul class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                    <li v-for="bed in ward.beds" :key="bed.id">
                        <button type="button" class="flex h-full w-full flex-col items-start gap-1 rounded-lg p-3 text-start ring-1 transition hover:shadow-sm" :class="bedLooks[bed.status].ring"
                                :aria-label="t('inpatient.board.bed_label', { code: bed.code, status: t(`inpatient.bed_status.${bed.status}`) })" @click="open(bed)">
                            <span class="flex w-full items-center justify-between text-[14px] font-semibold">
                                <span dir="ltr">{{ bed.code }}</span>
                                <component :is="icons[bedLooks[bed.status].icon]" class="size-4" aria-hidden="true" />
                            </span>
                            <span class="text-[12px]">{{ t(`inpatient.bed_status.${bed.status}`) }}</span>
                            <template v-if="bed.admission">
                                <span class="text-[12.5px] font-medium">{{ bed.admission.patient }}</span>
                                <span class="text-[11.5px] text-muted"><span dir="ltr">{{ bed.admission.number }}</span> · {{ formatDateTime(bed.admission.admitted_at) }}</span>
                            </template>
                        </button>
                    </li>
                </ul>
            </section>
        </div>
    </PageFrame>
</template>
