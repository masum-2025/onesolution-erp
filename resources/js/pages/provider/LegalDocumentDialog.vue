<script setup>
import { ref, watch } from 'vue';
import { FileText } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import ErrorState from '@/components/ErrorState.vue';
import LegalText from '@/components/LegalText.vue';
import { api } from '@/lib/http';
import { formatDate, formatDateTime } from '@/lib/format';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Read a legal document in force and, for account owners, accept it. The
 * version read is the version accepted; a newer one must be read first.
 */
const props = defineProps({
    open: Boolean,
    organizationId: { type: String, required: true },
    kind: { type: String, default: null },
});
const emit = defineEmits(['close', 'accepted']);

const document = ref(null);
const error = ref(null);
const agreed = ref(false);
const saving = ref(false);

watch(
    () => [props.open, props.kind],
    async ([open]) => {
        if (!open || !props.kind) return;
        document.value = null;
        error.value = null;
        agreed.value = false;
        try {
            document.value = (await api(`/api/organizations/${props.organizationId}/legal/${props.kind}`)).data;
        } catch (caught) {
            error.value = caught;
        }
    },
);

async function accept() {
    saving.value = true;
    try {
        const response = await api(`/api/organizations/${props.organizationId}/legal/${props.kind}/accept`, { method: 'POST', body: { version: document.value.version } });
        toast.success(response.message);
        emit('accepted', response.data);
    } catch (caught) {
        toast.error(caught.message);
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <AppDialog :open="open" size="lg" :title="document?.title ?? ''" :description="document ? t('provider.documents.version_line', { version: document.version, date: formatDate(document.published_at) }) : ''" :icon="FileText" @close="emit('close')">
        <ErrorState v-if="error" compact :error="error" />
        <div v-else-if="!document" class="space-y-3" role="status" :aria-label="t('core.states.loading')">
            <div v-for="n in 5" :key="n" class="skeleton h-4 w-full" />
        </div>
        <div v-else class="space-y-4">
            <p v-if="document.summary" class="rounded-xl bg-brand-soft/60 p-3 text-[13px] text-fg-2">{{ t('provider.documents.changed', { summary: document.summary }) }}</p>
            <div class="max-h-[50vh] overflow-y-auto rounded-xl border border-line p-4">
                <LegalText :text="document.body" />
            </div>
            <p v-if="document.accepted_at" class="text-[12.5px] text-ok">{{ t('provider.documents.accepted_on', { date: formatDateTime(document.accepted_at) }) }}</p>
            <label v-else-if="document.can_accept" class="flex items-start gap-2.5 text-[13.5px] text-fg-2">
                <input v-model="agreed" type="checkbox" class="mt-0.5 size-4 rounded accent-brand" />
                <span>{{ t('provider.documents.agree', { title: document.title }) }}</span>
            </label>
        </div>

        <template #footer>
            <AppButton @click="emit('close')">{{ t('core.actions.close') }}</AppButton>
            <AppButton v-if="document?.can_accept && !document.accepted_at" variant="primary" :loading="saving" :disabled="!agreed" @click="accept">
                {{ t('provider.documents.accept') }}
            </AppButton>
        </template>
    </AppDialog>
</template>
