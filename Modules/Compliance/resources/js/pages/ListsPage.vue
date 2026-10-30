<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ArrowLeft, Globe, ListPlus, PenLine, Plus, ShieldCheck } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { useResource } from '@/lib/useResource';
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { complianceApi } from '../api';

/**
 * The register's lists: audit services, kinds of audit, providers. Kept by a
 * group for all its companies (a company adds its own); items kept above are
 * shown with who keeps them. Switched off, never removed.
 */
const org = currentOrganization();
const calls = complianceApi(org.id);
const kind = ref('services');
const kinds = computed(() => ['services', 'types', 'providers'].map((value) => ({ value, label: t(`compliance.lists.${value}`) })));
const showAll = ref(false);
const list = useResource(() => calls.list(kind.value, showAll.value));
const items = computed(() => list.data.value?.data ?? []);
watch([kind, showAll], () => list.reload());
// Lists are kept by a group or a company (a branch uses theirs).
const keeps = computed(() => ['group', 'company'].includes(org.organization_type) && can('compliance.manage'));

const editing = ref(null); // null | 'new' | item
const form = reactive({ code: '', name: {}, validity_months: '', email: '', phone: '', provider: '', connection_id: '', is_active: true });
// The client's business connections a provider may be linked to (CMP-2): renewal requests then go over it.
const connections = computed(() => list.data.value?.meta?.connections ?? []);
const errors = ref({});
const saving = ref(false);
function edit(item = null) {
    Object.assign(form, {
        code: item?.code ?? '', name: { ...(item?.names ?? {}) }, validity_months: item?.validity_months ?? '',
        provider: item && kind.value === 'providers' ? item.name : '', email: item?.email ?? '', phone: item?.phone ?? '', connection_id: item?.connection_id ?? '', is_active: item?.is_active ?? true,
    });
    errors.value = {};
    editing.value = item ?? 'new';
}
async function save() {
    saving.value = true;
    errors.value = {};
    const body = kind.value === 'providers'
        ? { name: form.provider.trim(), email: form.email.trim() || null, phone: form.phone.trim() || null, connection_id: form.connection_id || null, is_active: form.is_active }
        : { code: form.code.trim(), name: form.name, is_active: form.is_active, ...(kind.value === 'services' ? { validity_months: form.validity_months === '' ? null : Number(form.validity_months) } : {}) };
    try {
        if (editing.value === 'new') await calls.addItem(kind.value, body);
        else await calls.updateItem(kind.value, editing.value.id, { ...body, base_version: editing.value.version });
        toast.success(t('compliance.lists.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function addDefaults() {
    try {
        const { data } = await calls.addDefaults(kind.value);
        toast.success(t('compliance.lists.defaults_added', { count: data.added }));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('compliance.lists.title')" :description="t('compliance.lists.text')">
            <template #actions>
                <AppButton variant="ghost" :icon="ArrowLeft" :to="{ name: 'compliance' }">{{ t('compliance.register.title') }}</AppButton>
                <AppButton v-if="keeps && kind !== 'providers'" :icon="ListPlus" @click="addDefaults">{{ t('compliance.lists.defaults') }}</AppButton>
                <AppButton v-if="keeps" variant="primary" :icon="Plus" @click="edit()">{{ t(`compliance.lists.add_${kind}`) }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <AppSegmented v-model="kind" size="sm" :label="t('compliance.lists.title')" :options="kinds" />
            <AppSwitch v-model="showAll" :label="t('compliance.lists.show_off')" show-label />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!items.length" :icon="ShieldCheck" compact :title="t(`compliance.lists.empty_${kind}`)" :text="t(`compliance.lists.empty_${kind}_text`)">
                <AppButton v-if="keeps && kind !== 'providers'" variant="primary" :icon="ListPlus" @click="addDefaults">{{ t('compliance.lists.defaults') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="item in items" :key="item.id" class="flex items-center gap-3 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="flex items-center gap-1.5 truncate text-[13.5px] font-medium">{{ item.name }}<Globe v-if="item.on_platform" class="size-3.5 text-brand" :aria-label="t('compliance.lists.on_platform')" /></span>
                        <span class="block truncate text-[12px] text-muted">
                            <span v-if="item.code" class="font-mono" dir="ltr">{{ item.code }}</span>
                            <template v-if="item.validity_months"> · {{ t('compliance.lists.months', { count: item.validity_months }) }}</template>
                            <template v-if="item.email"> · {{ item.email }}</template>
                            <template v-if="item.kept_by"> · {{ t('compliance.lists.kept_by', { name: item.kept_by }) }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="!item.is_active" tone="outline">{{ t('compliance.lists.off') }}</AppBadge>
                    <AppButton v-if="keeps && item.own" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('compliance.lists.edit', { name: item.name })" @click="edit(item)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t(`compliance.lists.add_${kind}`) : t('compliance.lists.edit', { name: editing?.name })" @close="editing = null">
            <form id="compliance-item" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <template v-if="kind === 'providers'">
                    <AppField v-slot="{ id }" :label="t('compliance.lists.provider_name')" :error="fieldError('name')" class="sm:col-span-2">
                        <input :id="id" v-model="form.provider" class="field-input" maxlength="150" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('compliance.lists.email')" :error="fieldError('email')" optional>
                        <input :id="id" v-model="form.email" type="email" class="field-input" dir="ltr" maxlength="190" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('compliance.lists.phone')" :error="fieldError('phone')" optional>
                        <input :id="id" v-model="form.phone" type="tel" class="field-input" dir="ltr" maxlength="30" />
                    </AppField>
                    <AppField v-slot="{ id }" :label="t('compliance.lists.connection')" :hint="t(connections.length ? 'compliance.lists.connection_hint' : 'compliance.lists.connection_none')" :error="fieldError('connection_id')" optional class="sm:col-span-2">
                        <select :id="id" v-model="form.connection_id" class="field-input" :disabled="!connections.length">
                            <option value="">{{ t('compliance.lists.not_connected') }}</option>
                            <option v-for="connection in connections" :key="connection.id" :value="connection.id">{{ connection.name }}</option>
                        </select>
                    </AppField>
                </template>
                <template v-else>
                    <AppField v-slot="{ id }" :label="t('compliance.lists.code')" :hint="t('compliance.lists.code_hint')" :error="fieldError('code')">
                        <input :id="id" v-model="form.code" class="field-input font-mono" dir="ltr" maxlength="30" />
                    </AppField>
                    <AppField v-if="kind === 'services'" v-slot="{ id }" :label="t('compliance.lists.validity')" :hint="t('compliance.lists.validity_hint')" :error="fieldError('validity_months')" optional>
                        <input :id="id" v-model="form.validity_months" type="number" min="1" max="120" class="field-input" />
                    </AppField>
                    <div class="sm:col-span-2">
                        <TranslatedFields v-model="form.name" :label="t('compliance.lists.name')" :errors="errors" error-prefix="name" required :maxlength="120" />
                    </div>
                </template>
                <div v-if="editing !== 'new'" class="sm:col-span-2">
                    <AppSwitch v-model="form.is_active" :label="t('compliance.lists.in_use')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('compliance.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="compliance-item" :loading="saving">{{ t('compliance.common.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
