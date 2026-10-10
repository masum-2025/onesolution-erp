<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Award, Ban, ChevronLeft, ChevronRight, IdCard, Mail, Palette, Printer, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { formatDate } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';

/**
 * The register of issued ID cards, certificates and letters: by kind,
 * status and number; printed again (one or many chosen), or revoked with a
 * reason. Issuing starts from a section (ID cards for everyone) or a
 * student; designs are one click away.
 */
const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();
const route = useRoute();
const router = useRouter();
const KIND_ICONS = { id_card: IdCard, certificate: Award, letter: Mail };

const kind = ref(route.query.kind ?? '');
const status = ref(route.query.status ?? '');
const search = ref(route.query.q ?? '');
const page = ref(Number(route.query.page) || 1);
const list = useResource(() => education.documents({ kind: kind.value, status: status.value, q: search.value.trim(), page: page.value, per_page: 25 }));
const documents = computed(() => list.data.value?.data ?? []);
const meta = computed(() => list.data.value?.meta ?? null);
const filtering = computed(() => kind.value || status.value || search.value.trim());

let timer = null;
watch([kind, status, search], () => {
    clearTimeout(timer);
    timer = setTimeout(() => go(1), 300);
});
onBeforeUnmount(() => clearTimeout(timer));
function go(to) {
    page.value = to;
    router.replace({ query: { ...(kind.value ? { kind: kind.value } : {}), ...(status.value ? { status: status.value } : {}), ...(search.value.trim() ? { q: search.value.trim() } : {}), ...(to > 1 ? { page: to } : {}) } });
    list.reload();
}

// Chosen to print together.
const chosen = ref(new Set());
function toggle(id) {
    const next = new Set(chosen.value);
    next.has(id) ? next.delete(id) : next.add(id);
    chosen.value = next;
}
function print(ids) {
    router.push({ name: 'education-print', query: { ids: ids.join(',') } });
}

// Revoking.
const revoking = ref(null);
const reason = ref('');
const saving = ref(false);
function openRevoke(document) {
    reason.value = '';
    revoking.value = document;
}
async function revoke() {
    saving.value = true;
    try {
        await education.revoke(revoking.value.id, { reason: reason.value.trim() });
        toast.success(t('education.document_statuses.revoked'));
        revoking.value = null;
        list.reload();
    } catch (error) {
        toast.error(error.errors?.reason?.[0] ?? error.message);
    } finally {
        saving.value = false;
    }
}

const statusTone = { valid: 'ok', revoked: 'bad', expired: 'warn' };
</script>

<template>
    <div>
        <PageHeader :title="t('education.documents.title')" :description="t('education.documents.text')">
            <template #actions>
                <AppButton v-if="chosen.size" :icon="Printer" @click="print([...chosen])">{{ t('education.documents.print_selected', { count: chosen.size }) }}</AppButton>
                <AppButton :icon="Palette" :to="{ name: 'education-designs' }">{{ t('education.documents.designs') }}</AppButton>
            </template>
        </PageHeader>

        <div class="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_200px_200px]">
            <AppField v-slot="{ id }" :label="t('education.documents.search')" sr-only-label>
                <div class="relative">
                    <Search class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-faint" aria-hidden="true" />
                    <input :id="id" v-model="search" type="search" class="field-input ps-9" dir="auto" :placeholder="t('education.documents.search')" autocomplete="off" />
                </div>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.documents.all_kinds')" sr-only-label>
                <select :id="id" v-model="kind" class="field-input">
                    <option value="">{{ t('education.documents.all_kinds') }}</option>
                    <option v-for="value in ['id_card', 'certificate', 'letter']" :key="value" :value="value">{{ t(`education.document_kinds.${value}`) }}</option>
                </select>
            </AppField>
            <AppField v-slot="{ id }" :label="t('education.documents.all_statuses')" sr-only-label>
                <select :id="id" v-model="status" class="field-input">
                    <option value="">{{ t('education.documents.all_statuses') }}</option>
                    <option v-for="value in ['valid', 'revoked', 'expired']" :key="value" :value="value">{{ t(`education.document_statuses.${value}`) }}</option>
                </select>
            </AppField>
        </div>

        <section class="card">
            <SkeletonRows v-if="list.loading.value && !list.data.value" :rows="6" />
            <ErrorState v-else-if="list.error.value" compact :error="list.error.value" @retry="list.reload()" />
            <EmptyState v-else-if="!documents.length" :icon="IdCard"
                :title="filtering ? t('education.documents.none_title') : t('education.documents.empty_title')"
                :text="filtering ? t('education.documents.none_text') : t('education.documents.empty_text')" compact>
                <AppButton v-if="!filtering && setup.can('manage')" :icon="Palette" :to="{ name: 'education-designs' }">{{ t('education.documents.designs') }}</AppButton>
            </EmptyState>
            <template v-else>
                <header class="border-b border-line px-4 py-2.5 text-[12.5px] text-muted sm:px-5">{{ t('education.documents.count', { count: meta?.total ?? documents.length }) }}</header>
                <ul class="divide-y divide-line">
                    <li v-for="document in documents" :key="document.id" class="flex flex-wrap items-center gap-3 px-4 py-3 sm:px-5">
                        <input type="checkbox" class="size-4 accent-[var(--brand)]" :checked="chosen.has(document.id)" :aria-label="document.number" @change="toggle(document.id)" />
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-fg-2" aria-hidden="true"><component :is="KIND_ICONS[document.kind]" class="size-[18px]" /></span>
                        <span class="min-w-0 flex-1">
                            <RouterLink :to="{ name: 'education-student', params: { id: document.student_id } }" class="block truncate text-[14px] font-medium text-fg hover:underline">{{ document.student_name ?? '—' }}</RouterLink>
                            <span class="block truncate text-[12.5px] text-muted">
                                <span class="font-mono" dir="ltr">{{ document.number }}</span> · {{ document.title_text }} · {{ t('education.documents.issued_on', { date: formatDate(document.issued_on) }) }}
                                <template v-if="document.valid_until"> · {{ t('education.documents.valid_until', { date: formatDate(document.valid_until) }) }}</template>
                            </span>
                            <span v-if="document.revoke_reason" class="block truncate text-[12px] text-bad">{{ t('education.documents.revoked', { reason: document.revoke_reason }) }}</span>
                        </span>
                        <AppBadge :tone="statusTone[document.status]" dot>{{ t(`education.document_statuses.${document.status}`) }}</AppBadge>
                        <span class="flex w-full justify-end gap-1 sm:w-auto">
                            <AppButton size="sm" variant="ghost" :icon="Printer" @click="print([document.id])">{{ t('education.documents.print') }}</AppButton>
                            <AppButton v-if="document.status !== 'revoked'" size="sm" variant="danger-soft" :icon="Ban" @click="openRevoke(document)">{{ t('education.documents.revoke') }}</AppButton>
                        </span>
                    </li>
                </ul>
            </template>
            <footer v-if="meta && meta.last_page > 1" class="flex items-center justify-between border-t border-line px-5 py-3">
                <span class="tabular text-[12.5px] text-muted">{{ t('education.documents.page', { page: meta.page, pages: meta.last_page }) }}</span>
                <div class="flex gap-2">
                    <AppButton size="sm" :icon="ChevronLeft" :disabled="meta.page <= 1" @click="go(meta.page - 1)">{{ t('core.actions.previous') }}</AppButton>
                    <AppButton size="sm" :icon-end="ChevronRight" :disabled="meta.page >= meta.last_page" @click="go(meta.page + 1)">{{ t('core.actions.next') }}</AppButton>
                </div>
            </footer>
        </section>

        <AppDialog :open="revoking !== null" :title="revoking ? t('education.documents.revoke_title', { number: revoking.number }) : ''" :description="t('education.documents.revoke_text')" :icon="Ban" tone="bad" @close="revoking = null">
            <AppField v-slot="{ id }" :label="t('education.documents.revoke_reason')" :hint="t('education.documents.revoke_hint')">
                <textarea :id="id" v-model="reason" rows="3" class="field-input" maxlength="300" />
            </AppField>
            <template #footer>
                <AppButton variant="ghost" @click="revoking = null">{{ t('education.cancel') }}</AppButton>
                <AppButton variant="danger" :loading="saving" :disabled="reason.trim().length < 3" @click="revoke">{{ t('education.documents.revoke') }}</AppButton>
            </template>
        </AppDialog>
    </div>
</template>
