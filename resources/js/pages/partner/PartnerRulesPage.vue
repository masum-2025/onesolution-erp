<script setup>
import { PageFrame } from '@/ui';
import RulesBrowser from '../rules/RulesBrowser.vue';
import { partnerRules } from '@/lib/rulesApi';
import { session } from '@/lib/session';
import { t } from '@/lib/i18n';

const adapter = partnerRules();
</script>

<template>
    <PageFrame :title="t('partner.rules.title')" :description="t('partner.rules.text')" width="full">
        <RulesBrowser :adapter="adapter" :scope-name="session.me?.context?.name ?? ''" />
    </PageFrame>
</template>
