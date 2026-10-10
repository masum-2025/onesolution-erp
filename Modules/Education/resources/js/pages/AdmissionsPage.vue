<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ChevronLeft, ChevronRight, FilePlus, FileText, Handshake, Search, X } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppTabs from '@/components/AppTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { textIn } from '@/lib/texts';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { admissionTone, phoneText } from '../lib';
import ApplicationDialog from '../components/ApplicationDialog.vue';
import StudentAvatar from '../components/StudentAvatar.vue';

/**
 * Applications of this unit and below (education.admit): by status in tabs
 * with counts, searched by number, name or phone, filtered by program. New
 * applications from here or the header "New" menu (?new=1).
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();
const STATUSES = ['applied', 'test', 'offered', 'admitted', 'rejected', 'withdrawn'];

const search = ref(route.query.q ?? '');
const status = ref(STATUSES.includes(route.query.status) ? route.query.status : 'all');
const programId = ref(route.query.program ?? '');
const page = ref(Number(route.query.page) || 1);

const list = useResource(() => education.admissions({ q: search.value.trim(), status: status.value === 'all' ? '' : status.value, program_id: programId.value, page: page.value, per_page: 25 }));
const admissions = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const counts = computed(() => meta.value?.counts ?? {});
const filtering = computed(() => search.value.trim() !== '' || programId.value !== '');
const tabs = computed(() => [
    { key: 'all', label: t('education.admissions.all'), count: Object.values(counts.value).reduce((sum, count) => sum + count, 0) },
    ...STATUSES.map((key) => ({ key, label: t(`education.admission_statuses.${key}`), count: counts.value[key] ?? 0 })),
]);

let timer = null;
watch([search, programId], () => {
    clearTimeout(timer);
    timer = setTimeout(() => go(1), 300);
});
watch(status, () => go(1));
onBeforeUnmount(() => clearTimeout(timer));

function go(to) {
    page.value = to;
    router.replace({
        query: {
            ...(search.value.trim() ? { q: search.value.trim() } : {}),
            ...(status.value !== 'all' ? { status: status.value } : {}),
            ...(programId.value ? { program: programId.value } : {}),
            ...(page.value > 1 ? { page: page.value } : {}),
        },
    });
    list.reload();
}

const adding = ref(route.query.new === '1');
function closeAdding() {
    adding.value = false;
    if (route.query.new) router.replace({ query: { ...route.query, new: undefined } });
}
function saved(admission) {
    adding.value = false;
    router.push({ name: 'education-admission', params: { id: admission.id } });
}

const placeText = (admission) => (admission.level_id ? setup.levelText(admission.level_id) : t('education.admissions.no_place'));
const guardianPhone = (admission) => admission.applicant?.guardians?.find((guardian) => guardian.is_primary)?.phone ?? admission.applicant?.guardians?.[0]?.phone ?? admission.applicant?.phone;
</script>

<template>
    <div>
        <PageHeader :title="t('education.admissions.title')" :description="t('education.admissions.text')">
            <template #actions>
                <AppButton variant="primary" :icon="FilePlus" :disabled="!setup.ready.value" @click="adding = true">{{ t('education.admissions.new') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-4 overflow-x-auto"><AppTabs v-model="status" :tabs="tabs" :label="t('education.admissions.title')" /></div>

        <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-end">
            <AppField v-slot="{ id }" :label="t('education.admissions.search')" sr-only-label class="flex-1">
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" :placeholder="t('education.admissions.search')" autocomplete="off" />
                </div>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.students.filters.program')" sr-only-label class="sm:w-56">
                <select :id="id" v-model="programId" class="field-input">
                    <option value="">{{ t('education.students.filters.all_programs') }}</option>
                    <option v-for="item in setup.data.value?.programs ?? []" :key="item.id" :value="item.id">{{ textIn(item.name) }}</option>
                </select>
            </AppField>
            <AppButton v-if="filtering" variant="ghost" :icon="X" @click="search = ''; programId = ''">{{ t('education.students.filters.clear') }}</AppButton>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" avatar />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState
                v-else-if="!admissions.length"
                :icon="FileText"
                :title="filtering || status !== 'all' ? t('education.admissions.none_title') : t('education.admissions.empty_title')"
                :text="filtering || status !== 'all' ? t('education.admissions.none_text') : t('education.admissions.empty_text')"
                compact
            >
                <AppButton v-if="!filtering && status === 'all' && setup.ready.value" variant="primary" :icon="FilePlus" @click="adding = true">{{ t('education.admissions.new') }}</AppButton>
            </EmptyState>
            <template v-else>
                <header class="border-b border-line px-4 py-2.5 text-[12.5px] text-muted sm:px-5">{{ t('education.admissions.count', { count: meta?.total ?? admissions.length }) }}</header>
                <ul class="divide-y divide-line">
                    <li v-for="admission in admissions" :key="admission.id">
                        <RouterLink :to="{ name: 'education-admission', params: { id: admission.id } }" class="flex items-center gap-3.5 px-4 py-3 transition hover:bg-subtle/60 sm:px-5">
                            <StudentAvatar :name="admission.applicant?.name ?? '?'" />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-center gap-2 truncate text-[14px] font-medium text-fg">
                                    <span class="truncate">{{ admission.applicant?.name || '—' }}</span>
                                    <Handshake v-if="admission.source === 'crm'" class="size-3.5 shrink-0 text-muted" :aria-label="t('education.admissions.from_crm')" />
                                </span>
                                <span class="mt-0.5 block truncate text-[12.5px] text-muted">
                                    <span class="font-mono" dir="ltr">{{ admission.number }}</span> · {{ placeText(admission) }}
                                    <template v-if="guardianPhone(admission)"> · <span dir="ltr">{{ phoneText(guardianPhone(admission)) }}</span></template>
                                </span>
                            </span>
                            <span class="hidden shrink-0 text-[12px] text-faint md:block">{{ t('education.admissions.applied_on', { date: formatDate(admission.created_at) }) }}</span>
                            <AppBadge :tone="admissionTone(admission.status)" dot class="shrink-0">{{ t(`education.admission_statuses.${admission.status}`) }}</AppBadge>
                        </RouterLink>
                    </li>
                </ul>
            </template>
            <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                <span class="tabular text-[12.5px] text-muted">{{ t('education.admissions.page', { page: meta.page, pages: meta.last_page }) }}</span>
                <div class="flex gap-2">
                    <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                    <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                </div>
            </footer>
        </section>

        <ApplicationDialog :open="adding" @close="closeAdding" @saved="saved" />
    </div>
</template>
