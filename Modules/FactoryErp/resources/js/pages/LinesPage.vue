<script setup>
import { computed, reactive, ref } from 'vue';
import { Pencil, Plus, Rows3 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { formatNumber } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { factoryApi } from '../api';
import { useFactorySetup } from '../setup';

/**
 * The factory's sewing lines: code, name and machines; a line no longer
 * used is switched off (its past output keeps it). Set up with
 * factory_erp.manage; others only read.
 */
const api = factoryApi(currentOrganization().id);
const setup = useFactorySetup();
const list = useResource(() => api.lines());
const lines = computed(() => list.data.value?.data ?? []);
const canManage = computed(() => setup.can('manage'));

const editing = ref(null);
const form = reactive({ code: '', name: '', machines: '', is_active: true });
const errors = ref({});
const busy = ref(false);
function open(line) {
    editing.value = line ?? {};
    Object.assign(form, { code: line?.code ?? '', name: line?.name ?? '', machines: line ? String(line.machines) : '', is_active: line?.is_active ?? true });
    errors.value = {};
}
const ready = computed(() => form.code.trim() && form.name.trim() && /^\d{0,3}$/.test(form.machines.trim()));
async function save() {
    busy.value = true;
    errors.value = {};
    const body = { code: form.code.trim(), name: form.name.trim(), machines: Number(form.machines.trim() || 0) };
    try {
        if (editing.value?.id) await api.updateLine(editing.value.id, { ...body, is_active: form.is_active, base_version: editing.value.version });
        else await api.createLine(body);
        toast.success(t('factory_erp.lines.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('factory_erp.conflict'));
            list.reload();
            editing.value = null;
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="mx-auto max-w-3xl">
        <PageHeader :title="t('factory_erp.lines.title')" :description="t('factory_erp.lines.text')">
            <template v-if="canManage" #actions>
                <AppButton variant="primary" :icon="Plus" @click="open(null)">{{ t('factory_erp.lines.new') }}</AppButton>
            </template>
        </PageHeader>
        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="4" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!lines.length" class="card"><EmptyState :icon="Rows3" :title="t('factory_erp.lines.empty_title')" :text="t('factory_erp.lines.empty_text')" /></section>
        <ul v-else class="card divide-y divide-line">
            <li v-for="line in lines" :key="line.id" class="flex items-center gap-3 px-5 py-3">
                <span class="grid h-10 min-w-12 place-items-center rounded-xl bg-subtle px-2 font-mono text-[13px] font-bold text-fg" dir="ltr">{{ line.code }}</span>
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[14px] font-medium text-fg">{{ line.name }}</p>
                    <p class="text-[12px] text-muted">{{ t('factory_erp.lines.machines_count', { count: formatNumber(line.machines) }) }}</p>
                </div>
                <AppBadge :tone="line.is_active ? 'ok' : 'neutral'" dot>{{ line.is_active ? t('factory_erp.active') : t('factory_erp.off') }}</AppBadge>
                <AppButton v-if="canManage" size="sm" variant="ghost" :icon="Pencil" :aria-label="t('factory_erp.lines.edit_of', { code: line.code })" @click="open(line)" />
            </li>
        </ul>

        <AppDialog :open="editing !== null" :icon="Rows3" :title="editing?.id ? t('factory_erp.lines.edit') : t('factory_erp.lines.new')" :description="t('factory_erp.lines.dialog_text')" @close="editing = null">
            <form class="grid gap-4 sm:grid-cols-2" @submit.prevent="ready && save()">
                <AppField v-slot="{ id }" :label="t('factory_erp.lines.code')" :error="errors.code?.[0] ?? null">
                    <input :id="id" v-model="form.code" class="field-input font-mono uppercase" dir="ltr" maxlength="20" placeholder="L-01" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('factory_erp.lines.machines')" :error="errors.machines?.[0] ?? null" optional>
                    <input :id="id" v-model="form.machines" inputmode="numeric" class="field-input tabular" maxlength="3" placeholder="32" />
                </AppField>
                <AppField v-slot="{ id }" class="sm:col-span-2" :label="t('factory_erp.lines.name')" :error="errors.name?.[0] ?? null">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="100" :placeholder="t('factory_erp.lines.name_hint')" />
                </AppField>
                <div v-if="editing?.id" class="sm:col-span-2">
                    <AppSwitch v-model="form.is_active" :label="t('factory_erp.lines.in_use')" show-label />
                    <p class="mt-1 text-[12px] text-muted">{{ t('factory_erp.lines.in_use_hint') }}</p>
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('factory_erp.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="busy" :disabled="!ready" @click="save">{{ t('factory_erp.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
