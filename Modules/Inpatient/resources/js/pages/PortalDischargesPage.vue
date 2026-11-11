<script setup>
import { computed } from 'vue';
import { DoorOpen } from 'lucide-vue-next';
import { PageFrame } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';
import { portalDischarges } from '../api';

/**
 * A patient's own discharge papers in the client's portal (B2B2C): the stay,
 * the diagnosis, treatment, advice and the follow-up date. Only signed papers
 * in force.
 */
const view = useResource(() => portalDischarges());
const rows = computed(() => view.data.value?.data?.discharges ?? []);
const sections = ['diagnosis', 'treatment', 'advice'];
</script>

<template>
    <PageFrame :title="t('inpatient.portal.title')" :description="view.data.value?.data?.organization ?? ''" width="md" :resource="view"
               :empty="{ when: !rows.length, icon: DoorOpen, title: t('inpatient.portal.empty'), text: t('inpatient.portal.empty_text') }">
        <ul class="grid gap-4">
            <li v-for="item in rows" :key="item.id" class="card p-4 text-[13.5px]">
                <p class="text-[15px] font-semibold">{{ formatDate(item.admitted_at) }} – {{ formatDate(item.discharged_at) }} · <span dir="ltr">{{ item.number }}</span></p>
                <p v-if="item.doctor" class="text-[12.5px] text-muted">{{ item.doctor }}</p>
                <template v-for="key in sections" :key="key">
                    <p v-if="item[key]" class="mt-2 whitespace-pre-line"><span class="font-medium">{{ t(`inpatient.discharge.${key}`) }}:</span> {{ item[key] }}</p>
                </template>
                <p v-if="item.follow_up_on" class="mt-2 font-medium">{{ t('inpatient.discharge.follow_up_on') }}: {{ formatDate(item.follow_up_on) }}</p>
            </li>
        </ul>
    </PageFrame>
</template>
