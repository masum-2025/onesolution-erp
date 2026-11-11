<script setup>
import { computed, reactive, ref } from 'vue';
import { LayoutTemplate, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { AppBadge, AppButton, AppDialog, AppField, AppSwitch, PageFrame, confirmAction, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { opdApi } from '../api';
import LinesEditor from '../components/LinesEditor.vue';

/**
 * Prescription templates: frequent medicines, tests and advice under a name,
 * put into a consultation in one tap. A doctor's own, or shared with every
 * doctor (managers only). Medicines and tests here are written by hand; the
 * lists are used while consulting.
 */
const opd = opdApi(currentOrganization().id);
const list = useResource(() => opd.templates());
const rows = computed(() => list.data.value?.data ?? []);
const canShare = computed(() => list.data.value?.meta?.can?.share ?? false);
const options = { pharmacy: false, lab: false, tests: [], timings: ['before_meal', 'after_meal', 'with_meal', 'empty_stomach', 'bedtime', 'as_needed'] };

const editing = ref(null);
const draft = reactive({ name: '', shared: false, advice: '', medicines: [], tests: [] });
const errors = ref({});
function open(template = null) {
    const body = template?.body ?? {};
    Object.assign(draft, {
        name: template?.name ?? '', shared: template?.shared ?? false, advice: body.advice ?? '',
        medicines: (body.medicines ?? []).map((row) => ({ medicine_id: null, dose: '', duration: '', timing: '', note: '', ...row })),
        tests: (body.tests ?? []).map((row) => ({ test_code: null, ...row })),
    });
    errors.value = {};
    editing.value = template ?? { id: null };
}
const blank = (value) => (value === '' ? null : value);
const saving = ref(false);
async function save() {
    saving.value = true;
    errors.value = {};
    const template = editing.value;
    try {
        await opd.saveTemplate(template.id, {
            name: draft.name.trim(), ...(template.id ? { base_version: template.version } : { shared: draft.shared }),
            body: {
                advice: blank(draft.advice.trim()),
                medicines: draft.medicines.filter((row) => row.medicine_text.trim()).map((row) => ({ medicine_text: row.medicine_text.trim(), dose: blank(row.dose), duration: blank(row.duration), timing: blank(row.timing), note: blank(row.note) })),
                tests: draft.tests.filter((row) => row.test_text.trim()).map((row) => ({ test_text: row.test_text.trim() })),
            },
        });
        toast.success(t('outpatient.templates.saved'));
        editing.value = null;
        list.reload();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([key, value]) => [key.replace(/^body\./, ''), value]));
        toast.error(error.message);
    } finally {
        saving.value = false;
    }
}
async function remove(template) {
    const yes = await confirmAction({ title: t('outpatient.templates.remove_title', { name: template.name }), message: t('outpatient.templates.remove_text'), confirmLabel: t('outpatient.templates.remove'), danger: true });
    if (!yes) return;
    try {
        await opd.removeTemplate(template.id, template.version);
        list.reload();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <PageFrame :title="t('outpatient.templates.title')" :description="t('outpatient.templates.text')" width="lg" :resource="list" guide="outpatient.guide.templates"
               :empty="{ when: !rows.length, icon: LayoutTemplate, title: t('outpatient.templates.empty'), text: t('outpatient.templates.empty_text') }">
        <template #actions>
            <AppButton variant="primary" :icon="Plus" @click="open()">{{ t('outpatient.templates.new') }}</AppButton>
        </template>
        <ul class="card divide-y divide-line">
            <li v-for="template in rows" :key="template.id" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <span class="min-w-0 flex-1">
                    <span class="block text-[14px] font-medium">{{ template.name }}</span>
                    <span class="block text-[12.5px] text-muted">{{ t('outpatient.templates.counts', { medicines: (template.body.medicines ?? []).length, tests: (template.body.tests ?? []).length }) }}</span>
                </span>
                <AppBadge :tone="template.shared ? 'info' : 'neutral'">{{ template.shared ? t('outpatient.templates.shared') : t('outpatient.templates.mine') }}</AppBadge>
                <template v-if="!template.shared || canShare">
                    <AppButton size="sm" variant="ghost" :icon="Pencil" :aria-label="t('outpatient.templates.edit')" @click="open(template)" />
                    <AppButton size="sm" variant="ghost" :icon="Trash2" :aria-label="t('outpatient.templates.remove')" @click="remove(template)" />
                </template>
            </li>
        </ul>

        <AppDialog :open="!!editing" size="lg" :title="editing?.id ? t('outpatient.templates.edit') : t('outpatient.templates.new')" :icon="LayoutTemplate" @close="editing = null">
            <div class="grid gap-3">
                <AppField v-slot="{ id }" :label="t('outpatient.templates.name')" :error="errors.name?.[0]"><input :id="id" v-model="draft.name" class="field-input" maxlength="100" /></AppField>
                <AppSwitch v-if="!editing?.id && canShare" v-model="draft.shared" :label="t('outpatient.templates.share')" />
                <LinesEditor :medicines="draft.medicines" :tests="draft.tests" :options="options" :errors="errors" />
                <AppField v-slot="{ id }" :label="t('outpatient.consult.advice')" :error="errors.advice?.[0]"><textarea :id="id" v-model="draft.advice" class="field-input min-h-16" maxlength="2000" /></AppField>
            </div>
            <template #footer>
                <AppButton variant="ghost" @click="editing = null">{{ t('outpatient.common.cancel') }}</AppButton>
                <AppButton variant="primary" :loading="saving" :disabled="!draft.name.trim()" @click="save">{{ t('outpatient.templates.save') }}</AppButton>
            </template>
        </AppDialog>
    </PageFrame>
</template>
