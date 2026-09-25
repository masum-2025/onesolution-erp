<script setup>
import PageHeader from '@/components/PageHeader.vue';
import RulesBrowser from '../rules/RulesBrowser.vue';
import { partnerRules } from '@/lib/rulesApi';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';

const adapter = partnerRules();
</script>

<template>
    <div>
        <PageHeader :title="t('partner.rules.title')" :description="t('partner.rules.text')" />
        <RulesBrowser :adapter="adapter" :scope-name="session.me?.context?.name ?? ''" />
    </div>
</template>
