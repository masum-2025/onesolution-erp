<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ShieldCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import OrgPicker from '@/components/OrgPicker.vue';
import RulesBrowser from './RulesBrowser.vue';
import { organizationRules } from '@/lib/rulesApi';
import { visibleOrganizations } from '@/lib/organizations';
import { can, currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';

const route = useRoute();
const router = useRouter();
const context = currentOrganization();

const orgId = ref(typeof route.query.org === 'string' ? route.query.org : context.id);
const orgName = ref(context.name);
const adapter = computed(() => organizationRules(orgId.value));

watch(
    orgId,
    async (id) => {
        if (route.query.org !== id && !(id === context.id && !route.query.org)) {
            router.replace({ query: { ...route.query, org: id === context.id ? undefined : id } });
        }
        orgName.value = (await visibleOrganizations().catch(() => [])).find((org) => org.id === id)?.display_name ?? orgName.value;
    },
    { immediate: true },
);
</script>

<template>
    <div>
        <PageHeader :title="t('rules.title')" :description="t('rules.text')">
            <template #actions>
                <OrgPicker v-model="orgId" compact :label="t('core.org_picker.scope')" />
                <AppButton v-if="can('rules.approve')" to="/approvals" :icon="ShieldCheck">{{ t('core.nav.approvals') }}</AppButton>
            </template>
        </PageHeader>

        <RulesBrowser :adapter="adapter" :scope-name="orgName" />
    </div>
</template>
