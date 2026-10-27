<script setup>
import { computed, reactive, ref } from 'vue';
import { Archive, ArchiveRestore, ArrowLeft, Layers, Network, PenLine, Plus } from 'lucide-vue-next';
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
import { confirmAction } from '@/lib/dialogs';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { hrmApi } from '../api';

/**
 * Departments per branch (or the company itself), with how many people work
 * in each. A department is a unit of the organization: its head can be given
 * rights over it alone, and attendance, pay and reports follow it. Closing
 * one keeps its records; it needs nobody working there first.
 */
const org = currentOrganization();
const hrm = hrmApi(org.id);

const list = useResource(() => hrm.departments());
const showClosed = ref(false);
const places = computed(() => (list.data.value?.data ?? []).map((place) => ({
    ...place,
    shown: place.departments.filter((department) => showClosed.value || department.status === 'active'),
})));
const managed = computed(() => places.value.filter((place) => place.can_manage && place.status === 'active'));
const anyDepartment = computed(() => places.value.some((place) => place.departments.length));
const anyClosed = computed(() => places.value.some((place) => place.departments.some((department) => department.status !== 'active')));

// One dialog: a new department at a branch, a new one at every branch, or a new name.
const editing = ref(null); // null | { mode: 'new', placeId } | { mode: 'all' } | { mode: 'rename', department }
const saving = ref(false);
const errors = ref({});
const form = reactive({ name_en: '', name_bn: '', place_id: '' });

function open(mode, target = null) {
    Object.assign(form, {
        name_en: mode === 'rename' ? target.names.en ?? '' : '',
        name_bn: mode === 'rename' ? target.names.bn ?? '' : '',
        place_id: mode === 'new' ? target?.id ?? managed.value[0]?.id ?? '' : '',
    });
    errors.value = {};
    editing.value = { mode, department: mode === 'rename' ? target : null };
}

const name = () => ({ en: form.name_en.trim(), ...(form.name_bn.trim() ? { bn: form.name_bn.trim() } : {}) });
const sameName = (department) => (department.names.en ?? '').trim().toLowerCase() === form.name_en.trim().toLowerCase();

async function save() {
    saving.value = true;
    errors.value = {};
    try {
        const { mode, department } = editing.value;
        if (mode === 'rename') {
            await hrm.updateDepartment(department.id, { name: name() });
            toast.success(t('hrm.departments.saved'));
        } else if (mode === 'new') {
            await hrm.createDepartment(form.place_id, name());
            toast.success(t('hrm.departments.added'));
        } else {
            // Every open branch that has no department of this name yet.
            const missing = managed.value.filter((place) => !place.departments.some(sameName));
            for (const place of missing) await hrm.createDepartment(place.id, name());
            toast.success(t('hrm.departments.added_all', { count: missing.length }));
        }
        editing.value = null;
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
        list.reload();
    }
}

async function setOpen(department, opened) {
    const ok = await confirmAction(opened
        ? { title: t('hrm.departments.reopen_title', { name: department.name }), message: t('hrm.departments.reopen_text'), confirmLabel: t('hrm.departments.reopen') }
        : { title: t('hrm.departments.close_title', { name: department.name }), message: t('hrm.departments.close_text'), confirmLabel: t('hrm.departments.close'), danger: true });
    if (!ok) return;
    try {
        await hrm.updateDepartment(department.id, { status: opened ? 'active' : 'archived' });
        toast.success(opened ? t('hrm.departments.reopened') : t('hrm.departments.closed'));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

const fieldError = (key) => errors.value[key]?.[0] ?? null;
const missingCount = computed(() => managed.value.filter((place) => !place.departments.some(sameName)).length);
</script>

<template>
    <div>
        <PageHeader :title="t('hrm.departments.title')" :description="t('hrm.departments.text')">
            <template #actions>
                <AppButton variant="ghost" :to="{ name: 'hrm' }" :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton>
                <AppButton v-if="managed.length > 1" :icon="Layers" @click="open('all')">{{ t('hrm.departments.add_all') }}</AppButton>
                <AppButton v-if="managed.length" variant="primary" :icon="Plus" @click="open('new')">{{ t('hrm.departments.add') }}</AppButton>
            </template>
        </PageHeader>

        <div v-if="anyClosed" class="mb-4 flex justify-end">
            <AppSwitch v-model="showClosed" :label="t('hrm.departments.show_closed')" show-label />
        </div>

        <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
        <ErrorState v-else-if="list.error.value" :error="list.error.value" @retry="list.reload()" />
        <section v-else-if="!anyDepartment" class="card">
            <EmptyState :icon="Network" :title="t('hrm.departments.empty_title')" :text="t('hrm.departments.empty_text')">
                <AppButton v-if="managed.length" variant="primary" :icon="Plus" @click="open('new')">{{ t('hrm.departments.add') }}</AppButton>
            </EmptyState>
        </section>
        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <section v-for="place in places" :key="place.id" class="card" :aria-label="place.name">
                <header class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div class="min-w-0">
                        <h2 class="truncate text-[15px] font-semibold text-fg">{{ place.name }}</h2>
                        <p class="text-[12.5px] text-muted">
                            {{ t(`hrm.departments.type_${place.type}`) }}
                            · <RouterLink class="hover:text-fg hover:underline" :to="{ name: 'hrm', query: { unit_id: place.id } }">{{ t('hrm.departments.people_here', { count: place.employees }) }}</RouterLink>
                        </p>
                    </div>
                    <AppButton v-if="place.can_manage && place.status === 'active'" size="sm" variant="ghost" :icon="Plus" @click="open('new', place)">{{ t('hrm.departments.add_here') }}</AppButton>
                </header>
                <p v-if="!place.shown.length" class="px-5 py-4 text-[13px] text-muted">{{ t('hrm.departments.none_here') }}</p>
                <ul v-else class="divide-y divide-line">
                    <li v-for="department in place.shown" :key="department.id" class="flex items-center gap-3 px-5 py-3">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[13.5px] font-medium text-fg">{{ department.name }}</span>
                            <RouterLink class="text-[12px] text-muted hover:text-fg hover:underline" :to="{ name: 'hrm', query: { unit_id: department.id } }">{{ t('hrm.departments.people', { count: department.employees }) }}</RouterLink>
                        </span>
                        <AppBadge v-if="department.status !== 'active'" tone="outline">{{ t('hrm.departments.closed_badge') }}</AppBadge>
                        <template v-if="place.can_manage">
                            <AppButton size="icon-sm" variant="ghost" :icon="PenLine" :aria-label="t('hrm.departments.rename', { name: department.name })" @click="open('rename', department)" />
                            <AppButton v-if="department.status === 'active'" size="icon-sm" variant="ghost" :icon="Archive" :disabled="department.employees > 0"
                                :title="department.employees > 0 ? t('hrm.departments.close_blocked') : t('hrm.departments.close')"
                                :aria-label="department.employees > 0 ? t('hrm.departments.close_blocked') : t('hrm.departments.close')" @click="setOpen(department, false)" />
                            <AppButton v-else size="icon-sm" variant="ghost" :icon="ArchiveRestore" :aria-label="t('hrm.departments.reopen')" @click="setOpen(department, true)" />
                        </template>
                    </li>
                </ul>
            </section>
        </div>

        <AppDialog :open="editing !== null"
            :title="editing?.mode === 'rename' ? t('hrm.departments.rename', { name: editing.department.name }) : editing?.mode === 'all' ? t('hrm.departments.add_all') : t('hrm.departments.add')"
            @close="editing = null">
            <form id="hrm-department" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
                <AppField v-if="editing?.mode === 'new'" v-slot="{ id }" :label="t('hrm.departments.place')" :error="fieldError('parent_id')" class="sm:col-span-2">
                    <select :id="id" v-model="form.place_id" class="field-input">
                        <option v-for="place in managed" :key="place.id" :value="place.id">{{ place.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('hrm.departments.name_en')" :hint="t('hrm.departments.name_hint')" :error="fieldError('name.en') ?? fieldError('name')">
                    <input :id="id" v-model="form.name_en" class="field-input" lang="en" maxlength="150" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('hrm.departments.name_bn')" :error="fieldError('name.bn')" optional>
                    <input :id="id" v-model="form.name_bn" class="field-input" lang="bn" maxlength="150" />
                </AppField>
                <p v-if="editing?.mode === 'all'" class="text-[13px] text-muted sm:col-span-2" role="status">
                    {{ form.name_en.trim() ? t('hrm.departments.all_text', { count: missingCount }) : t('hrm.departments.all_hint') }}
                </p>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('hrm.profile.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="hrm-department" :loading="saving"
                    :disabled="!form.name_en.trim() || (editing?.mode === 'new' && !form.place_id) || (editing?.mode === 'all' && !missingCount)">{{ t('hrm.departments.save') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
