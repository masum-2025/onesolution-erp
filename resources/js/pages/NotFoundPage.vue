<script setup>
import { Compass } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import EmptyState from '@/components/EmptyState.vue';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';
</script>

<template>
    <div class="card mt-6">
        <EmptyState :icon="Compass" :title="t('core.not_found.title')" :text="t('core.not_found.text')">
            <AppButton variant="primary" :to="session.me?.context?.type === 'partner' ? '/partner/organizations' : '/'">{{ t('core.not_found.home') }}</AppButton>
        </EmptyState>
    </div>
</template>
