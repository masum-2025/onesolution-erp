<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Mail, Plus, Search } from 'lucide-vue-next';
import { AppBadge, AppButton, EmptyState, ErrorState, PageFrame, SkeletonRows } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { lettersApi } from '../api';
import { letterTone } from '../lib';
import IssueDialog from '../components/IssueDialog.vue';

/**
 * Letters issued to the people of this unit and below, newest first:
 * searched by number, name or code, filtered by kind and whether revoked.
 * Salary letters are listed for everyone who sees letters; only people who
 * may use salary open them. "Issue letters" opens the dialog.
 */
const org = currentOrganization();
const letters = lettersApi(org.id);
const router = useRouter();

const filters = reactive({ q: '', kind: '', status: '', page: 1 });
const list = useResource(() => letters.letters({ q: filters.q.trim() || undefined, kind: filters.kind || undefined, status: filters.status || undefined, page: filters.page }));
const rows = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? {});
let timer = null;
watch(() => [filters.q, filters.kind, filters.status], () => {
    clearTimeout(timer);
    timer = setTimeout(() => {
        filters.page = 1;
        list.reload();
    }, 250);
});
function goTo(page) {
    filters.page = page;
    list.reload();
}

const options = useResource(() => letters.options());
const kinds = computed(() => options.data.value?.data?.kinds ?? []);
const canIssue = computed(() => Boolean(options.data.value?.data?.can?.issue));
const templates = useResource(() => letters.templates(), { immediate: false });
const active = computed(() => (templates.data.value?.data ?? []).filter((item) => item.status === 'active'));
const issuing = ref(false);
async function openIssue() {
    await templates.reload();
    issuing.value = true;
}
function issued(created) {
    issuing.value = false;
    list.reload();
    if (created.length === 1) router.push({ name: 'letters-letter', params: { id: created[0].id } });
}

const day = (iso) => formatDate(iso, { dateStyle: 'medium' });

// Export and print: the letters on screen, or all that match (up to the limit).
const exported = computed(() => ({
    module: 'letters',
    page: 'letters.issued',
    columns: [
        { key: 'number', label: t('letters.list.number') },
        { key: 'employee', label: t('letters.list.employee'), value: (row) => `${row.employee.name} (${row.employee.code})` },
        { key: 'title', label: t('letters.list.title') },
        { key: 'kind', label: t('letters.list.kind'), value: (row) => t(`letters.kinds.${row.kind}`) },
        { key: 'issued_at', label: t('letters.list.issued_at'), type: 'date' },
        { key: 'issued_by', label: t('letters.list.issued_by') },
        { key: 'status', label: t('letters.list.status'), value: (row) => t(row.revoked ? 'letters.status.revoked' : 'letters.status.issued') },
    ],
    fetchAll: async (maxRows) => {
        const all = [];
        for (let page = 1; all.length < maxRows; page += 1) {
            const result = await letters.letters({ q: filters.q.trim() || undefined, kind: filters.kind || undefined, status: filters.status || undefined, page, per_page: 100 });
            all.push(...result.data);
            if (page >= result.meta.last_page) break;
        }
        return all.slice(0, maxRows);
    },
}));
</script>

<template>
    <PageFrame :title="t('letters.list.page_title')" :description="t('letters.list.text')" width="full" :export="exported">
        <template #actions>
            <AppButton variant="ghost" :to="{ name: 'letters-templates' }">{{ t('letters.list.designs') }}</AppButton>
            <AppButton v-if="canIssue" variant="primary" :icon="Plus" @click="openIssue">{{ t('letters.issue.title') }}</AppButton>
        </template>

        <section class="card">
            <div class="flex flex-wrap gap-2 border-b border-line p-3 print:hidden">
                <div class="relative min-w-52 flex-1">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted" aria-hidden="true" />
                    <input v-model="filters.q" type="search" class="field-input ps-9" :placeholder="t('letters.list.search')" :aria-label="t('letters.list.search')" />
                </div>
                <select v-model="filters.kind" class="field-input w-auto" :aria-label="t('letters.list.kind')">
                    <option value="">{{ t('letters.list.all_kinds') }}</option>
                    <option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`letters.kinds.${kind}`) }}</option>
                </select>
                <select v-model="filters.status" class="field-input w-auto" :aria-label="t('letters.list.status')">
                    <option value="">{{ t('letters.list.all_statuses') }}</option>
                    <option value="issued">{{ t('letters.status.issued') }}</option>
                    <option value="revoked">{{ t('letters.status.revoked') }}</option>
                </select>
            </div>

            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="5" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState
                v-else-if="!rows.length"
                :icon="Mail"
                :title="filters.q || filters.kind || filters.status ? t('letters.list.none_found') : t('letters.list.empty')"
                :text="filters.q || filters.kind || filters.status ? '' : t('letters.list.empty_text')"
                compact
            />
            <template v-else>
                <!-- Wide screens: a table. -->
                <table class="hidden w-full text-[13.5px] md:table">
                    <thead class="text-[12px] text-muted">
                        <tr class="border-b border-line">
                            <th class="px-5 py-2.5 text-start font-medium">{{ t('letters.list.number') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('letters.list.employee') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('letters.list.title') }}</th>
                            <th class="px-3 py-2.5 text-start font-medium">{{ t('letters.list.issued_at') }}</th>
                            <th class="px-5 py-2.5 text-end font-medium">{{ t('letters.list.status') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="row in rows" :key="row.id" class="cursor-pointer hover:bg-surface-2" @click="router.push({ name: 'letters-letter', params: { id: row.id } })">
                            <td class="px-5 py-3 font-mono text-[12.5px]" dir="ltr">
                                <RouterLink :to="{ name: 'letters-letter', params: { id: row.id } }" class="hover:underline" @click.stop>{{ row.number }}</RouterLink>
                            </td>
                            <td class="px-3 py-3">
                                <div class="font-medium">{{ row.employee.name }}</div>
                                <div class="font-mono text-[12px] text-muted" dir="ltr">{{ row.employee.code }}</div>
                            </td>
                            <td class="px-3 py-3">
                                <div>{{ row.title }} <span v-if="row.uses_salary" :title="t('letters.salary.badge')">💰</span></div>
                                <div class="text-[12px] text-muted">{{ t(`letters.kinds.${row.kind}`) }} · {{ t(`letters.languages.${row.locale}`) }}</div>
                            </td>
                            <td class="px-3 py-3">
                                <div>{{ day(row.issued_at) }}</div>
                                <div v-if="row.issued_by" class="text-[12px] text-muted">{{ row.issued_by }}</div>
                            </td>
                            <td class="px-5 py-3 text-end"><AppBadge :tone="letterTone(row)" dot>{{ t(row.revoked ? 'letters.status.revoked' : 'letters.status.issued') }}</AppBadge></td>
                        </tr>
                    </tbody>
                </table>
                <!-- Phones: a list. -->
                <ul class="divide-y divide-line md:hidden">
                    <li v-for="row in rows" :key="row.id">
                        <RouterLink :to="{ name: 'letters-letter', params: { id: row.id } }" class="flex items-start gap-3 px-4 py-3 hover:bg-surface-2">
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium">{{ row.employee.name }}</span>
                                <span class="block text-[13px]">{{ row.title }} <span v-if="row.uses_salary">💰</span></span>
                                <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ row.number }}</span>
                            </span>
                            <span class="flex flex-col items-end gap-1 text-[12px] text-muted">
                                <AppBadge :tone="letterTone(row)" dot>{{ t(row.revoked ? 'letters.status.revoked' : 'letters.status.issued') }}</AppBadge>
                                {{ day(row.issued_at) }}
                            </span>
                        </RouterLink>
                    </li>
                </ul>
                <div v-if="meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3 text-[13px] print:hidden">
                    <span class="text-muted">{{ t('letters.list.page', { page: meta.page, pages: meta.last_page, total: meta.total }) }}</span>
                    <span class="flex gap-2">
                        <AppButton size="sm" variant="ghost" :disabled="meta.page <= 1" @click="goTo(meta.page - 1)">{{ t('letters.list.previous') }}</AppButton>
                        <AppButton size="sm" variant="ghost" :disabled="meta.page >= meta.last_page" @click="goTo(meta.page + 1)">{{ t('letters.list.next') }}</AppButton>
                    </span>
                </div>
            </template>
        </section>

        <p v-if="canIssue && templates.data.value && !active.length" class="mt-3 text-[13px] text-muted">{{ t('letters.issue.no_active') }}</p>
        <IssueDialog :open="issuing && active.length > 0" :templates="active" :max-people="options.data.value?.data?.max_people ?? 200" @close="issuing = false" @issued="issued" />
    </PageFrame>
</template>
