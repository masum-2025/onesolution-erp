<script setup>
import { computed, reactive, ref } from 'vue';
import { ArrowLeft, Briefcase, PenLine, Plus } from 'lucide-vue-next';
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
import { can, currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';

/**
 * Positions of this organization (and the units above it), named in both
 * languages. Positions people hold are marked "not in use", never removed.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);

const showAll = ref(false);
const list = useResource(() => hrm.positions(showAll.value));
const positions = computed(() => list.data.value?.data ?? []);

const editing = ref(null); // null | 'new' | position
const saving = ref(false);
const errors = ref({});
const form = reactive({ title_en: '', title_bn: '', code: '', grade: '', is_active: true });

function edit(position = null) {
    Object.assign(form, {
        title_en: position?.titles.en ?? '',
        title_bn: position?.titles.bn ?? '',
        code: position?.code ?? '',
        grade: position?.grade ?? '',
        is_active: position?.is_active ?? true,
    });
    errors.value = {};
    editing.value = position ?? 'new';
}

async function save() {
    const body = {
        title: { en: form.title_en.trim(), ...(form.title_bn.trim() ? { bn: form.title_bn.trim() } : {}) },
        code: form.code.trim() || null,
        grade: form.grade.trim() || null,
        is_active: form.is_active,
    };

    saving.value = true;
    errors.value = {};
    try {
        if (editing.value === 'new') await hrm.createPosition(body);
        else await hrm.updatePosition(editing.value.id, { ...body, base_version: editing.value.version });
        toast.success(t('hrm.positions.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        if (error.code === 'version_conflict') {
            toast.error(t('hrm.profile.conflict'));
            editing.value = null;
            list.reload();
            return;
        }
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

function toggleAll(value) {
    showAll.value = value;
    list.reload();
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.positions.title')" :description="t('hrm.positions.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
                <AppButton v-if="can('hrm.manage')" variant="primary" :icon="Plus" @click="edit()">{{ t('hrm.positions.add') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 flex justify-end">
            <AppSwitch :model-value="showAll" :label="t('hrm.positions.show_all')" show-label @update:model-value="toggleAll" />
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!positions.length" :icon="Briefcase" :title="t('hrm.positions.empty_title')" :text="t('hrm.positions.empty_text')" compact />
            <ul v-else class="divide-y divide-line">
                <li v-for="position in positions" :key="position.id" class="flex items-center gap-3 px-5 py-3">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium text-fg">{{ position.title }}</span>
                        <span class="block text-[12px] text-muted">
                            <span v-if="position.code" class="font-mono" dir="ltr">{{ position.code }}</span>
                            <template v-if="position.code && position.grade"> · </template>
                            <template v-if="position.grade">{{ t('hrm.positions.grade') }} {{ position.grade }}</template>
                        </span>
                    </span>
                    <AppBadge v-if="!position.is_active" tone="outline">{{ t('hrm.positions.inactive') }}</AppBadge>
                    <AppButton v-if="can('hrm.manage') && position.organization_id === org.id" size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('hrm.positions.edit')" @click="edit(position)" />
                </li>
            </ul>
        </section>

        <AppDialog :open="editing !== null" :title="editing === 'new' ? t('hrm.positions.add') : t('hrm.positions.edit')" @close="editing = null">
            <form id="hrm-position" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-slot="{ id }" :label="t('hrm.positions.title_en')" :error="fieldError('title.en') ?? fieldError('title')">
                    <input :id="id" v-model="form.title_en" class="field-input" lang="en" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('hrm.positions.title_bn')" :error="fieldError('title.bn')" optional>
                    <input :id="id" v-model="form.title_bn" class="field-input" lang="bn" maxlength="100" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('hrm.positions.code')" :error="fieldError('code')" optional>
                    <input :id="id" v-model="form.code" class="field-input" dir="ltr" maxlength="30" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('hrm.positions.grade')" :error="fieldError('grade')" optional>
                    <input :id="id" v-model="form.grade" class="field-input" maxlength="30" />
                </AppField>
                <div class="sm:col-span-2">
                    <AppSwitch v-model="form.is_active" :label="t('hrm.positions.active')" show-label />
                </div>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('hrm.profile.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="hrm-position" :loading="saving" :disabled="!form.title_en.trim()">{{ t('hrm.positions.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
