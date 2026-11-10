<script setup>
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronRight, Search, UserPlus, Users } from 'lucide-vue-next';
import { AppButton, EmptyState, PageFrame } from '@/ui';
import AppDialog from '@/components/AppDialog.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { patientsApi } from '../api';
import PatientForm from '../components/PatientForm.vue';

/**
 * Reception's first screen: type a patient number, mobile or name; open the
 * patient, or register a new one. Before a new record is made, patients that
 * look the same are shown to check first.
 */
const pt = patientsApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const setup = useResource(() => pt.setup());
const info = computed(() => setup.data.value?.data ?? {});
const can = computed(() => info.value.can ?? {});

const term = ref(String(route.query.q ?? ''));
const page = ref(1);
const list = useResource(() => pt.search({ q: term.value.trim(), page: page.value }));
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
let timer = null;
watch(term, () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        page.value = 1;
        router.replace({ query: term.value.trim() ? { q: term.value.trim() } : {} });
        list.reload();
    }, 300);
});
watch(page, () => list.reload());
const exported = computed(() => ({
    module: 'patients', page: 'patients.find', rows: rows.value,
    columns: [
        { key: 'mrn', label: t('patients.list.mrn') },
        { key: 'name', label: t('patients.list.name') },
        { key: 'age', label: t('patients.list.age'), type: 'number' },
        { key: 'sex', label: t('patients.list.sex'), value: (row) => t(`patients.sex.${row.sex}`) },
        { key: 'mobile', label: t('patients.list.mobile') },
    ],
}));
const open = (row) => router.push({ name: 'patients-patient', params: { id: row.id } });

// Registering: one op id per form, kept while the person confirms a look-alike.
const adding = ref(route.query.new === '1');
const form = ref(null);
const errors = ref({});
const saving = ref(false);
const lookalikes = ref([]);
let pending = null;
let opId = crypto.randomUUID();
function startNew() {
    errors.value = {};
    lookalikes.value = [];
    opId = crypto.randomUUID();
    adding.value = true;
}
function close() {
    adding.value = false;
    lookalikes.value = [];
    if (route.query.new) router.replace({ query: { ...route.query, new: undefined } });
}
async function register(body, confirmNew = false) {
    pending = body;
    saving.value = true;
    errors.value = {};
    try {
        const made = (await pt.register({ ...body, op_id: opId, ...(confirmNew ? { confirm_new: true } : {}) })).data;
        toast.success(t('patients.find.registered', { mrn: made.mrn }));
        adding.value = false;
        router.push({ name: 'patients-patient', params: { id: made.id } });
    } catch (error) {
        if (error.code === 'possible_duplicate') {
            lookalikes.value = error.data?.matches ?? [];
        } else {
            errors.value = error.errors ?? {};
            toast.error(error.message);
        }
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <PageFrame :title="t('patients.find.title')" :description="t('patients.find.text')" width="lg" :export="exported" guide="patients.guide.find">
        <template v-if="can.register" #actions>
            <AppButton variant="primary" :icon="UserPlus" @click="startNew">{{ t('patients.find.new') }}</AppButton>
        </template>

        <div class="relative mb-4 print:hidden">
            <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
            <input v-model="term" type="search" class="field-input ps-9 text-[15px]" :placeholder="t('patients.find.search')" :aria-label="t('patients.find.search')" autofocus />
        </div>
        <p v-if="info.only_own" class="mb-3 text-[12.5px] text-muted">{{ t('patients.find.only_own') }}</p>

        <section class="card">
            <div v-if="list.loading.value && !rows.length" class="p-5 text-[13px] text-muted">{{ t('patients.common.loading') }}</div>
            <EmptyState v-else-if="list.error.value" :icon="Users" :title="list.error.value.message" compact />
            <EmptyState v-else-if="!rows.length" :icon="Users" :title="term.trim() ? t('patients.find.none', { term: term.trim() }) : t('patients.find.empty')" :text="can.register ? t('patients.find.none_text') : ''" compact>
                <AppButton v-if="can.register" variant="primary" :icon="UserPlus" @click="startNew">{{ t('patients.find.new') }}</AppButton>
            </EmptyState>
            <ul v-else class="divide-y divide-line">
                <li v-for="row in rows" :key="row.id">
                    <button type="button" class="flex w-full items-center gap-3 px-5 py-3 text-start hover:bg-subtle" @click="open(row)">
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-[14px] font-medium">{{ row.name }}<span v-if="row.name_local" class="text-muted"> · {{ row.name_local }}</span></span>
                            <span class="block text-[12.5px] text-muted">
                                <span class="font-mono" dir="ltr">{{ row.mrn }}</span> · {{ t(`patients.sex.${row.sex}`) }} · {{ t('patients.common.years', { count: row.age ?? '—' }) }}<template v-if="row.mobile"> · <span dir="ltr">{{ row.mobile }}</span></template>
                            </span>
                        </span>
                        <ChevronRight class="size-4 shrink-0 text-muted rtl:rotate-180" aria-hidden="true" />
                    </button>
                </li>
            </ul>
            <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-2 text-[12.5px] print:hidden">
                <AppButton size="sm" variant="ghost" :disabled="page <= 1" @click="page--">{{ t('patients.common.previous') }}</AppButton>
                <span class="text-muted">{{ t('patients.common.page', { page: meta.page, last: meta.last_page }) }}</span>
                <AppButton size="sm" variant="ghost" :disabled="page >= meta.last_page" @click="page++">{{ t('patients.common.next') }}</AppButton>
            </div>
        </section>

        <AppDialog :open="adding" size="lg" :title="t('patients.find.new')" :description="t('patients.find.new_text')" :icon="UserPlus" @close="close">
            <div v-if="lookalikes.length" class="grid gap-3">
                <p class="rounded-lg bg-warn/10 px-3 py-2 text-[13px] text-warn" role="alert">{{ t('patients.find.lookalike') }}</p>
                <ul class="divide-y divide-line rounded-lg ring-1 ring-line">
                    <li v-for="match in lookalikes" :key="match.id" class="flex items-center gap-3 px-3 py-2">
                        <span class="min-w-0 flex-1 text-[13.5px]">
                            <span class="font-medium">{{ match.name }}</span>
                            <span class="block text-[12px] text-muted"><span class="font-mono" dir="ltr">{{ match.mrn }}</span> · {{ t(`patients.sex.${match.sex}`) }} · {{ t('patients.common.years', { count: match.age ?? '—' }) }}<template v-if="match.same_id_number"> · <strong class="text-bad">{{ t('patients.find.same_id') }}</strong></template></span>
                        </span>
                        <AppButton size="sm" variant="primary" @click="open(match)">{{ t('patients.find.this_one') }}</AppButton>
                    </li>
                </ul>
            </div>
            <PatientForm v-show="!lookalikes.length" id="patients-new" ref="form" :options="info.options ?? {}" :require-id-number="info.require_id_number" :errors="errors" @submit="register" />
            <template #footer>
                <template v-if="lookalikes.length">
                    <AppButton variant="ghost" @click="lookalikes = []">{{ t('patients.find.back_to_form') }}</AppButton>
                    <AppButton variant="primary" :loading="saving" @click="register(pending, true)">{{ t('patients.find.register_anyway') }}</AppButton>
                </template>
                <template v-else>
                    <AppButton variant="ghost" @click="close">{{ t('patients.common.cancel') }}</AppButton>
                    <AppButton variant="primary" type="submit" form="patients-new" :loading="saving" :disabled="!form?.ready">{{ t('patients.find.save') }}</AppButton>
                </template>
            </template>
        </AppDialog>
    </PageFrame>
</template>
