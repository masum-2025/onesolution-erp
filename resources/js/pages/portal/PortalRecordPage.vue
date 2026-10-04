<script setup>
import { computed } from 'vue';
import { useRoute } from 'vue-router';
import { ArrowLeft, CreditCard } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import PageHeader from '@/components/PageHeader.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';

/**
 * One record a portal member may see, with only the fields the client
 * shows. Anything not theirs comes back as not found.
 */
const route = useRoute();
const org = currentOrganization();
const record = useResource(() => api(`/api/portal/records/${route.params.id}`).then((response) => response.data));
const data = computed(() => record.data.value);
</script>

<template>
    <div class="mx-auto max-w-2xl">
        <AppButton class="mb-4" variant="ghost" size="sm" :icon="ArrowLeft" to="/portal">{{ t('portal.record.back') }}</AppButton>

        <SkeletonRows v-if="record.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="record.error.value" :error="record.error.value" @retry="record.reload()" />

        <template v-else-if="data">
            <PageHeader :title="data.name" :description="`${data.kind} · ${t(`portal.record.relation.${data.relation}`)}`">
                <!-- A module with its own screen for the record (e.g. a customer's invoices). -->
                <template v-if="data.page" #actions>
                    <AppButton variant="primary" :to="data.page">{{ t('portal.record.open') }}</AppButton>
                </template>
            </PageHeader>

            <section class="card">
                <p v-if="!data.fields.length" class="px-5 py-6 text-[13.5px] text-muted">{{ t('portal.record.empty') }}</p>
                <dl v-else class="divide-y divide-line">
                    <div v-for="field in data.fields" :key="field.key" class="grid gap-1 px-5 py-3.5 sm:grid-cols-3 sm:gap-4">
                        <dt class="text-[13px] text-muted">{{ field.label }}</dt>
                        <dd class="text-[14px] text-fg sm:col-span-2">{{ field.value ?? '—' }}</dd>
                    </div>
                </dl>
            </section>

            <p v-if="data.online_payment" class="mt-4 flex items-center gap-2 text-[12.5px] text-muted">
                <CreditCard class="size-4" aria-hidden="true" />{{ t('portal.record.payment_soon', { org: org?.name ?? '' }) }}
            </p>
        </template>
    </div>
</template>
