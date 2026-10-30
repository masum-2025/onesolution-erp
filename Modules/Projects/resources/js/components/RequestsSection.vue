<script setup>
import { reactive, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import { Check, Inbox, X } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatDate } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Renewal requests from connected clients (only when the company is
 * connected with clients on the platform): accept one (a project opens from
 * the matching template; the client is told the start day) or decline it
 * with a reason. Nothing shows when there are none.
 */
const props = defineProps({
    options: { type: Object, default: null },
    calls: { type: Object, required: true },
    highlight: Boolean,
});
const emit = defineEmits(['changed']);
const router = useRouter();

const requests = ref([]);
async function load() {
    try {
        requests.value = (await props.calls.requests(true)).data;
    } catch {
        requests.value = [];
    }
}
load();

const accepting = ref(null);
const form = reactive({ name: '', template_id: '', manager_id: '', starts_on: '', due_on: '' });
const errors = ref({});
const busy = ref(false);
watch(accepting, (request) => {
    if (!request) return;
    Object.assign(form, {
        name: `${request.service.name || request.service.code || ''} · ${request.factory}`.trim(),
        template_id: request.template_id ?? '', manager_id: '',
        starts_on: props.options?.today ?? '', due_on: request.wanted_by ?? '',
    });
    errors.value = {};
});

async function accept() {
    busy.value = true;
    errors.value = {};
    try {
        const { data } = await props.calls.acceptRequest(accepting.value.id, {
            name: form.name.trim() || null, template_id: form.template_id || null, manager_id: form.manager_id || null, starts_on: form.starts_on, due_on: form.due_on || null,
        });
        toast.success(t('projects.requests.accepted', { code: data.code }));
        accepting.value = null;
        emit('changed');
        router.push({ name: 'project', params: { id: data.project_id } });
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
async function decline(request) {
    const answer = await confirmAction({
        title: t('projects.requests.decline_title', { client: request.client }),
        message: t('projects.requests.decline_text'),
        confirmLabel: t('projects.requests.decline'),
        danger: true,
        reason: 'required',
    });
    if (!answer) return;
    try {
        await props.calls.declineRequest(request.id, answer.reason);
        toast.success(t('projects.requests.declined'));
        load();
    } catch (error) {
        toast.error(error.message);
    }
}
const fieldError = (name) => errors.value[name]?.[0] ?? null;
</script>

<template>
    <section v-if="requests.length" class="card mb-5 border-brand/30" :class="highlight ? 'ring-2 ring-brand' : ''" :aria-label="t('projects.requests.title')">
        <header class="flex items-center gap-2 border-b border-line px-5 py-3">
            <Inbox class="size-4 text-brand-text" aria-hidden="true" />
            <h2 class="text-[14.5px] font-semibold">{{ t('projects.requests.title') }}</h2>
            <span class="tabular rounded-full bg-brand-soft px-2 text-[12px] font-semibold text-brand-text">{{ requests.length }}</span>
        </header>
        <ul class="divide-y divide-line">
            <li v-for="request in requests" :key="request.id" class="flex flex-wrap items-center gap-3 px-5 py-3">
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[14px] font-medium">{{ request.service.name || request.service.code }} · {{ request.factory }}</span>
                    <span class="block truncate text-[12.5px] text-muted">
                        {{ request.client }}<template v-if="request.expires_on"> · {{ t('projects.requests.expires', { date: formatDate(request.expires_on) }) }}</template><template v-if="request.wanted_by"> · {{ t('projects.requests.wanted_by', { date: formatDate(request.wanted_by) }) }}</template>
                    </span>
                    <span v-if="request.note" class="block truncate text-[12.5px] text-fg-2">“{{ request.note }}”</span>
                </span>
                <span v-if="request.can.decide" class="flex gap-2">
                    <AppButton size="sm" variant="ghost" class="text-bad" :icon="X" @click="decline(request)">{{ t('projects.requests.decline') }}</AppButton>
                    <AppButton size="sm" variant="primary" :icon="Check" @click="accepting = request">{{ t('projects.requests.accept') }}</AppButton>
                </span>
            </li>
        </ul>

        <AppDialog :open="!!accepting" :title="t('projects.requests.accept_title')" :description="accepting ? `${accepting.client} · ${accepting.factory}` : ''" @close="accepting = null">
            <form id="accept-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="accept">
                <AppField v-slot="{ id }" :label="t('projects.fields.name')" :error="fieldError('name')" class="sm:col-span-2">
                    <input :id="id" v-model="form.name" class="field-input" maxlength="200" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.fields.template')" :hint="accepting?.template_id ? t('projects.requests.template_found') : null" :error="fieldError('template_id')" optional>
                    <select :id="id" v-model="form.template_id" class="field-input">
                        <option value="">{{ t('projects.dialog.no_template') }}</option>
                        <option v-for="template in options?.templates ?? []" :key="template.id" :value="template.id">{{ template.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.fields.manager')" :error="fieldError('manager_id')" optional>
                    <select :id="id" v-model="form.manager_id" class="field-input">
                        <option value="">{{ t('projects.common.none') }}</option>
                        <option v-for="person in options?.people ?? []" :key="person.id" :value="person.id">{{ person.name }}</option>
                    </select>
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.requests.starts_on')" :hint="t('projects.requests.starts_on_hint')" :error="fieldError('starts_on')">
                    <input :id="id" v-model="form.starts_on" type="date" :min="options?.today" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('projects.fields.due_on')" :error="fieldError('due_on')" optional>
                    <input :id="id" v-model="form.due_on" type="date" :min="form.starts_on || undefined" class="field-input" />
                </AppField>
            </form>
            <template #footer>
                <AppButton variant="ghost" @click="accepting = null">{{ t('projects.common.cancel') }}</AppButton>
                <AppButton variant="primary" type="submit" form="accept-form" :loading="busy" :disabled="!form.starts_on">{{ t('projects.requests.accept_open') }}</AppButton>
            </template>
        </AppDialog>
    </section>
</template>
