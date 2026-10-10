<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight, ClipboardList, Search, UserPlus, Users } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { registrationApi } from '../api';
import { useRegistrationSetup } from '../setup';
import { credits, registrationTone } from '../lib';
import SessionBar from '../components/SessionBar.vue';

/**
 * Registrations of the session: by status (with counts), searched by the
 * student; a student's registration opened (started the first time), or a
 * whole section registered for its compulsory subjects at once.
 */
const org = currentOrganization();
const api = registrationApi(org.id);
const setup = useRegistrationSetup();
const router = useRouter();
const STATUSES = ['draft', 'submitted', 'approved', 'returned'];

const status = ref('all');
const search = ref('');
const page = ref(1);
const list = useResource(() => (setup.sessionId.value
    ? api.registrations({ session_id: setup.sessionId.value, status: status.value === 'all' ? '' : status.value, q: search.value.trim(), page: page.value, per_page: 25 })
    : Promise.resolve({ data: [], meta: null })), { immediate: false });
watch(() => setup.sessionId.value, (id) => id && go(1), { immediate: true });
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const counts = computed(() => meta.value?.counts ?? {});
const tabs = computed(() => [
    { key: 'all', label: t('course_registration.registrations.all'), count: Object.values(counts.value).reduce((sum, count) => sum + count, 0) },
    ...STATUSES.map((key) => ({ key, label: t(`course_registration.statuses.${key}`), count: counts.value[key] ?? 0 })),
]);

let timer = null;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => go(1), 300);
});
watch(status, () => go(1));
onBeforeUnmount(() => clearTimeout(timer));
function go(to) {
    page.value = to;
    list.reload();
}

// One student.
const finding = ref(false);
const term = ref('');
const found = ref([]);
const opening = ref(null);
let findTimer = null;
watch(term, (value) => {
    clearTimeout(findTimer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    findTimer = setTimeout(async () => {
        found.value = (await api.findStudents(value.trim()).catch(() => ({ data: [] }))).data;
    }, 300);
});
async function openFor(student) {
    opening.value = student.id;
    try {
        const { data } = await api.open({ student_id: student.id, session_id: setup.sessionId.value });
        router.push({ name: 'crs-registration', params: { id: data.id } });
    } catch (error) {
        toast.error(error.message);
    } finally {
        opening.value = null;
    }
}

// A whole section.
const sectioning = ref(false);
const sections = ref([]);
const sectionId = ref('');
const result = ref(null);
const registering = ref(false);
async function openSections() {
    result.value = null;
    sectionId.value = '';
    sectioning.value = true;
    sections.value = (await api.sections(setup.sessionId.value).catch(() => ({ data: [] }))).data;
}
async function registerSection() {
    const section = sections.value.find((item) => item.id === sectionId.value);
    const confirmed = await confirmAction({
        title: t('course_registration.section_register.confirm_title', { name: `${setup.levelText(section.level_id)} · ${section.name}` }),
        message: t('course_registration.section_register.confirm_text'),
        confirmLabel: t('course_registration.section_register.register'),
    });
    if (!confirmed) return;
    registering.value = true;
    try {
        result.value = (await api.registerSection(sectionId.value)).data;
        toast.success(t('course_registration.section_register.done', result.value));
        list.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        registering.value = false;
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('course_registration.registrations.title')" :description="t('course_registration.registrations.text')">
            <template v-if="setup.can('register')" #actions>
                <AppButton :icon="Users" :disabled="!setup.sessionId.value" @click="openSections">{{ t('course_registration.registrations.register_section') }}</AppButton>
                <AppButton variant="primary" :icon="UserPlus" :disabled="!setup.sessionId.value" @click="finding = true; term = ''">{{ t('course_registration.registrations.register') }}</AppButton>
            </template>
        </PageHeader>

        <SkeletonRows v-if="setup.loading.value && !setup.data.value" :rows="6" />
        <ErrorState v-else-if="setup.error.value" :error="setup.error.value" @retry="setup.reload()" />
        <template v-else-if="setup.data.value">
            <SessionBar @changed="go(1)" />
            <div class="mb-4 overflow-x-auto"><AppTabs v-model="status" :tabs="tabs" :label="t('course_registration.registrations.title')" /></div>
            <AppField v-slot="{ id }" :label="t('course_registration.registrations.search')" sr-only-label class="mb-4">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('course_registration.registrations.search')" autocomplete="off" />
                </div>
            </AppField>

            <section class="card">
                <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
                <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
                <EmptyState v-else-if="!rows.length" :icon="ClipboardList"
                    :title="search || status !== 'all' ? t('course_registration.registrations.none_title') : t('course_registration.registrations.empty_title')"
                    :text="search || status !== 'all' ? t('course_registration.registrations.none_text') : t('course_registration.registrations.empty_text')" compact />
                <template v-else>
                    <header class="border-b border-line px-5 py-2.5 text-[12.5px] text-muted">{{ t('course_registration.registrations.count', { count: meta?.total ?? rows.length }) }}</header>
                    <ul class="divide-y divide-line">
                        <li v-for="row in rows" :key="row.id">
                            <RouterLink :to="{ name: 'crs-registration', params: { id: row.id } }" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-5 py-3 transition hover:bg-subtle/60">
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[14px] font-medium text-fg">{{ row.student?.name ?? '—' }}</span>
                                    <span class="block text-[12.5px] text-muted">
                                        <span class="font-mono" dir="ltr">{{ row.student?.code }}</span> · {{ t('course_registration.credits', { count: Number(credits(row.credits_centi)) }) }}
                                        <template v-if="row.submitted_at"> · {{ t('course_registration.registrations.handed_in', { date: formatDate(row.submitted_at) }) }}</template>
                                    </span>
                                </span>
                                <AppBadge v-if="row.overload" tone="warn">{{ t('course_registration.registrations.overload') }}</AppBadge>
                                <AppBadge :tone="registrationTone(row.status)" dot>{{ t(`course_registration.statuses.${row.status}`) }}</AppBadge>
                            </RouterLink>
                        </li>
                    </ul>
                </template>
                <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                    <span class="tabular text-[12.5px] text-muted">{{ t('course_registration.registrations.page', { page: meta.page, pages: meta.last_page }) }}</span>
                    <div class="flex gap-2">
                        <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                        <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                    </div>
                </footer>
            </section>
        </template>

        <!-- One student. -->
        <AppDialog :open="finding" :title="t('course_registration.find.title')" :description="t('course_registration.find.text')" :icon="UserPlus" @close="finding = false">
            <label class="relative block">
                <span class="sr-only">{{ t('course_registration.find.search') }}</span>
                <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                <input v-model="term" type="search" class="field-input ps-9" :placeholder="t('course_registration.find.search')" autocomplete="off" autofocus />
            </label>
            <p v-if="term.trim().length >= 2 && !found.length" class="mt-3 text-[13px] text-muted">{{ t('course_registration.find.none') }}</p>
            <ul v-else class="mt-3 divide-y divide-line rounded-xl border border-line empty:hidden">
                <li v-for="student in found" :key="student.id" class="flex items-center gap-3 px-3 py-2">
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[13.5px] font-medium">{{ student.name }}</span>
                        <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ student.code }}</span>
                    </span>
                    <AppButton size="sm" :loading="opening === student.id" @click="openFor(student)">{{ t('course_registration.find.open') }}</AppButton>
                </li>
            </ul>
            <template #footer>
                <AppButton variant="ghost" @click="finding = false">{{ t('course_registration.cancel') }}</AppButton>
            </template>
        </AppDialog>

        <!-- A whole section. -->
        <AppDialog :open="sectioning" :title="t('course_registration.section_register.title')" :description="t('course_registration.section_register.text')" :icon="Users" @close="sectioning = false">
            <template v-if="!result">
                <p v-if="!sections.length" class="text-[13px] text-muted">{{ t('course_registration.section_register.no_sections') }}</p>
                <AppField v-else v-slot="{ id }" :label="t('course_registration.section_register.section')">
                    <select :id="id" v-model="sectionId" class="field-input">
                        <option value="" disabled>—</option>
                        <option v-for="section in sections" :key="section.id" :value="section.id">
                            {{ setup.levelText(section.level_id) }} · {{ section.name }} ({{ t('course_registration.section_register.students', { count: section.students }) }})
                        </option>
                    </select>
                </AppField>
            </template>
            <div v-else class="space-y-3 text-[13.5px]">
                <p class="rounded-xl bg-ok-soft px-4 py-3 text-ok">{{ t('course_registration.section_register.done', result) }}</p>
                <template v-if="result.problems.length">
                    <p class="font-medium text-fg">{{ t('course_registration.section_register.problems') }} ({{ result.problems.length }})</p>
                    <ul class="max-h-60 divide-y divide-line overflow-y-auto rounded-xl border border-line">
                        <li v-for="problem in result.problems" :key="problem.student_id" class="px-3 py-2">
                            <span class="block font-medium text-fg">{{ problem.name }}</span>
                            <span class="block text-[12.5px] text-bad">{{ problem.message }}</span>
                        </li>
                    </ul>
                </template>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="sectioning = false">{{ result ? t('course_registration.back') : t('course_registration.cancel') }}</AppButton>
                <AppButton v-if="!result" variant="primary" :loading="registering" :disabled="!sectionId" @click="registerSection">{{ t('course_registration.section_register.register') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
