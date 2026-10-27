<script setup>
import { computed, ref, watch } from 'vue';
import { Copy, ExternalLink, Save } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { useResource } from '@/lib/useResource';
import { currentOrganization } from '@/lib/session';
import { toast } from '@/lib/toast';
import { i18n, t } from '@/lib/i18n';
import { recruitmentApi } from '../api';
import { nameIn } from '../lib';

/**
 * The careers page: its address (lower-case letters, digits and hyphens;
 * no other public page may have it), the link to share, whether public
 * applying is on, and the steps of an application (both from the rules:
 * changed in the rules editor).
 */
const org = currentOrganization();
const calls = recruitmentApi(org.id);
const careers = useResource(() => calls.careers());
const data = computed(() => careers.data.value?.data ?? null);
const options = useResource(() => calls.options());
const stages = computed(() => options.data.value?.data?.stages ?? []);

const slug = ref('');
const error = ref(null);
const saving = ref(false);
watch(data, (value) => {
    if (value) slug.value = value.slug ?? '';
}, { immediate: true });
const shaped = computed(() => /^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])$/.test(slug.value));

async function save() {
    saving.value = true;
    error.value = null;
    try {
        await calls.setCareers(slug.value);
        toast.success(t('recruitment.settings.saved'));
        careers.reload();
    } catch (failure) {
        error.value = failure.errors?.slug?.[0] ?? failure.message;
    } finally {
        saving.value = false;
    }
}

async function copy() {
    try {
        await navigator.clipboard.writeText(data.value.url);
        toast.success(t('recruitment.settings.copied'));
    } catch {
        toast.error(t('recruitment.settings.copy_failed'));
    }
}
</script>

<template>
    <div class="space-y-5">
        <PageHeader :title="t('recruitment.settings.title')" :description="t('recruitment.settings.text')" />

        <ErrorState v-if="careers.error.value" :error="careers.error.value" @retry="careers.reload()" />
        <SkeletonRows v-else-if="!data" :rows="3" />
        <template v-else>
            <section class="card space-y-4 p-5" aria-labelledby="careers-address">
                <h2 id="careers-address" class="text-[15px] font-semibold">{{ t('recruitment.settings.address') }}</h2>
                <form class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="save">
                    <AppField v-slot="{ id }" :label="t('recruitment.settings.slug')" :hint="t('recruitment.settings.slug_hint')" :error="error" class="min-w-[240px] flex-1">
                        <div class="flex items-center rounded-[9px] border border-line-strong bg-surface ps-3" dir="ltr">
                            <span class="text-[13px] text-muted">/careers/</span>
                            <input :id="id" v-model.trim="slug" class="w-full border-0 bg-transparent px-1 py-2 text-[14px] outline-none" maxlength="63" :readonly="!data.can_manage" autocomplete="off" />
                        </div>
                    </AppField>
                    <AppButton v-if="data.can_manage" type="submit" variant="primary" :icon="Save" :loading="saving" :disabled="!shaped || slug === data.slug">{{ t('recruitment.common.save') }}</AppButton>
                </form>
                <div v-if="data.url" class="flex flex-wrap items-center gap-2 rounded-xl bg-subtle px-4 py-3">
                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]" dir="ltr">{{ data.url }}</span>
                    <AppButton size="sm" :icon="Copy" @click="copy">{{ t('recruitment.settings.copy') }}</AppButton>
                    <a :href="data.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-[13px] font-medium text-brand hover:bg-surface"><ExternalLink class="size-4" aria-hidden="true" />{{ t('recruitment.settings.open') }}</a>
                </div>
                <p v-else class="text-[13px] text-muted">{{ t('recruitment.settings.no_address') }}</p>
            </section>

            <section class="card space-y-3 p-5" aria-labelledby="careers-rules">
                <h2 id="careers-rules" class="text-[15px] font-semibold">{{ t('recruitment.settings.rules') }}</h2>
                <p class="flex flex-wrap items-center gap-2 text-[13.5px]">
                    {{ t('recruitment.settings.public_apply') }}
                    <AppBadge :tone="data.public_apply ? 'ok' : 'neutral'">{{ data.public_apply ? t('recruitment.settings.on') : t('recruitment.settings.off') }}</AppBadge>
                </p>
                <div>
                    <p class="mb-2 text-[13.5px]">{{ t('recruitment.settings.stages') }}</p>
                    <ol class="flex flex-wrap items-center gap-2">
                        <li v-for="(stage, index) in stages" :key="stage.key" class="inline-flex items-center gap-2 text-[13px]">
                            <span class="rounded-lg bg-subtle px-3 py-1.5">{{ index + 1 }}. {{ nameIn(stage.name, i18n.locale) }}</span>
                            <span v-if="index < stages.length - 1" class="text-muted" aria-hidden="true">→</span>
                        </li>
                    </ol>
                </div>
                <p class="text-[12.5px] text-muted">{{ t('recruitment.settings.rules_hint') }}</p>
            </section>
        </template>
    </div>
</template>
