<script setup>
import { computed } from 'vue';
import { BookOpen } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { accountingApi } from '../api';

/**
 * Shows its content only when the company's books are set up; otherwise
 * offers the setup (or says who can do it). At a group, branch or
 * department the server's message says to open the company instead.
 * The slot receives the setup status (currency, suggested template...).
 */
const status = useResource(() => accountingApi(currentOrganization().id).setup());
const setup = computed(() => status.data.value?.data ?? null);

defineExpose({ reload: () => status.reload() });
</script>

<template>
    <section v-if="status.loading.value && !setup" class="card"><SkeletonRows :rows="6" /></section>
    <section v-else-if="status.error.value" class="card">
        <ErrorState compact :error="status.error.value" @retry="status.reload()" />
    </section>
    <section v-else-if="setup && !setup.set_up" class="card">
        <EmptyState :icon="BookOpen" :title="t('accounting.not_set_up.title')" :text="can('accounting.manage') ? t('accounting.not_set_up.text') : t('accounting.not_set_up.ask')">
            <AppButton v-if="can('accounting.manage')" variant="primary" :to="{ name: 'accounting-setup' }">{{ t('accounting.not_set_up.action') }}</AppButton>
        </EmptyState>
    </section>
    <slot v-else-if="setup" :setup="setup" />
</template>
