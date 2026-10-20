<script setup>
import { computed, ref } from 'vue';
import { CircleCheck, Copy, Globe, Lock, RefreshCw, ShieldCheck, Trash2 } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import SourceBadge from '@/components/SourceBadge.vue';
import { confirmAction } from '@/lib/dialogs';
import { formatRelative } from '@/lib/format';
import { t } from '@/lib/i18n';
import { toast } from '@/lib/toast';

/**
 * The website on the school's own domain: offered when the plan allows it;
 * a new domain waits until its DNS has the records shown here (pointing
 * at the sites server, and a TXT record proving it). Each step happens at
 * once (no "save"); people who may publish change it.
 */
const props = defineProps({
    web: { type: Object, required: true },
    site: { type: Object, required: true },
    rule: { type: Object, default: null }, // { value, source }
    canPublish: Boolean,
});
const emit = defineEmits(['changed']);

const host = ref('');
const busy = ref(null);
const error = ref(null);
const domain = computed(() => props.site.domain);
const offered = computed(() => !!props.rule?.value);

async function run(key, call, done) {
    busy.value = key;
    error.value = null;
    try {
        await call();
        if (done) toast.success(done);
        emit('changed');
    } catch (caught) {
        error.value = caught.errors?.host?.[0] ?? caught.message;
    } finally {
        busy.value = null;
    }
}

const add = () => run('add', () => props.web.setDomain(host.value.trim()), t('edu_website.domain.added')).then(() => (host.value = ''));
const verify = () => run('verify', () => props.web.verifyDomain(), t('edu_website.domain.verified'));

async function remove() {
    const confirmed = await confirmAction({
        title: t('edu_website.domain.remove_title'),
        message: t('edu_website.domain.remove_text', { host: domain.value.host, address: props.site.addresses.sites.replace(/^https?:\/\//, '') }),
        confirmLabel: t('edu_website.common.remove'),
        danger: true,
    });
    if (confirmed) await run('remove', () => props.web.removeDomain(), t('edu_website.domain.removed'));
}

async function copy(value) {
    try {
        await navigator.clipboard.writeText(value);
        toast.success(t('edu_website.overview.copied'));
    } catch {
        toast.error(t('edu_website.overview.copy_failed'));
    }
}
</script>

<template>
    <section class="card space-y-4 p-5 sm:p-6" aria-labelledby="domain-title">
        <div class="flex flex-wrap items-center gap-2">
            <h2 id="domain-title" class="me-auto text-[15px] font-semibold">{{ t('edu_website.domain.title') }}</h2>
            <AppBadge v-if="domain?.verified" tone="ok" :icon="ShieldCheck">{{ t('edu_website.domain.live') }}</AppBadge>
            <AppBadge v-else-if="domain" tone="warn">{{ t('edu_website.domain.waiting') }}</AppBadge>
        </div>

        <div v-if="!offered && !domain" class="flex flex-wrap items-center gap-2 rounded-lg bg-subtle px-4 py-3 text-[13px] text-fg-2">
            <Lock class="size-4 shrink-0" aria-hidden="true" />
            <span class="me-auto">{{ t('edu_website.domain.not_in_plan') }}</span>
            <SourceBadge v-if="rule?.source && rule.source.kind !== 'default'" v-bind="rule.source" />
        </div>

        <template v-else-if="!domain">
            <p class="text-[13px] text-muted">{{ t('edu_website.domain.text') }}</p>
            <form v-if="canPublish" class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="add">
                <AppField v-slot="{ id }" class="min-w-0 flex-1" :label="t('edu_website.domain.host')" :hint="t('edu_website.domain.host_hint')" :error="error">
                    <input :id="id" v-model="host" class="field-input font-mono" dir="ltr" inputmode="url" maxlength="253" placeholder="www.yourschool.edu.bd" autocomplete="off" spellcheck="false" />
                </AppField>
                <AppButton type="submit" variant="primary" :icon="Globe" :loading="busy === 'add'" :disabled="host.trim().length < 4">{{ t('edu_website.domain.add') }}</AppButton>
            </form>
            <p v-else class="text-[12.5px] text-muted">{{ t('edu_website.settings.address_publishers') }}</p>
        </template>

        <template v-else>
            <div class="flex flex-wrap items-center gap-2">
                <a v-if="domain.verified" :href="`https://${domain.host}`" target="_blank" rel="noopener" class="font-mono text-[15px] font-medium text-brand-text hover:underline" dir="ltr">{{ domain.host }}</a>
                <span v-else class="font-mono text-[15px] font-medium" dir="ltr">{{ domain.host }}</span>
                <span v-if="domain.verified_at" class="text-[12.5px] text-muted">· {{ t('edu_website.domain.proven', { when: formatRelative(domain.verified_at) }) }}</span>
            </div>

            <p v-if="domain.verified" class="flex items-start gap-2 text-[13px] text-fg-2">
                <CircleCheck class="mt-0.5 size-4 shrink-0 text-ok" aria-hidden="true" />{{ t('edu_website.domain.live_text', { address: site.addresses.sites.replace(/^https?:\/\//, '') }) }}
            </p>
            <template v-else>
                <p class="text-[13px] text-fg-2">{{ t('edu_website.domain.records_text') }}</p>
                <div class="overflow-x-auto rounded-lg border border-line">
                    <table class="w-full text-[13px]">
                        <thead class="bg-subtle text-start text-[12px] text-muted">
                            <tr>
                                <th class="px-3 py-2 text-start font-medium">{{ t('edu_website.domain.type') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('edu_website.domain.name') }}</th>
                                <th class="px-3 py-2 text-start font-medium">{{ t('edu_website.domain.value') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line">
                            <tr v-for="record in domain.records" :key="record.type">
                                <td class="px-3 py-2 font-semibold">{{ record.type }}</td>
                                <td class="px-3 py-2">
                                    <span class="flex items-center gap-1 font-mono" dir="ltr">
                                        <span class="break-all">{{ record.name }}</span>
                                        <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('edu_website.domain.copy')" @click="copy(record.name)" />
                                    </span>
                                </td>
                                <td class="px-3 py-2">
                                    <span class="flex items-center gap-1 font-mono" dir="ltr">
                                        <span class="break-all">{{ record.value }}</span>
                                        <AppButton size="icon-sm" variant="ghost" :icon="Copy" :aria-label="t('edu_website.domain.copy')" @click="copy(record.value)" />
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-[12.5px] text-muted">{{ t('edu_website.domain.records_hint') }}</p>
            </template>

            <p v-if="error" class="rounded-lg bg-bad-soft px-3 py-2 text-[13px] text-bad" role="alert">{{ error }}</p>

            <div v-if="canPublish" class="flex flex-wrap gap-2">
                <AppButton v-if="!domain.verified" variant="primary" :icon="RefreshCw" :loading="busy === 'verify'" @click="verify">{{ t('edu_website.domain.verify') }}</AppButton>
                <AppButton variant="ghost" :icon="Trash2" :loading="busy === 'remove'" @click="remove">{{ t('edu_website.domain.remove') }}</AppButton>
            </div>
        </template>
    </section>
</template>
