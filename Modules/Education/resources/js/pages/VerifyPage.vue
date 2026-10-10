<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { CircleAlert, CircleCheck, CircleX, Clock, RotateCw, ShieldCheck } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import { api } from '@/lib/http';
import { formatDate } from '@/lib/format';
import { t } from '@/lib/i18n';

/**
 * What anyone sees when they scan a document's QR code: no sign-in, no app
 * around it. Whether the document is valid, revoked or expired, by whom it
 * was issued, its number and dates, and of the student only what the
 * institution allows (never private details). A wrong code says so plainly.
 */
const route = useRoute();
const result = ref(null);
const missing = ref(false);
const failed = ref(null);
const loading = ref(true);

async function check() {
    loading.value = true;
    missing.value = false;
    failed.value = null;
    try {
        result.value = (await api(`/api/public/education/verify/${encodeURIComponent(route.params.organization)}/${encodeURIComponent(route.params.code)}`)).data;
    } catch (error) {
        if (error.status === 404) missing.value = true;
        else failed.value = error;
    } finally {
        loading.value = false;
    }
}
onMounted(check);

const state = computed(() => {
    if (missing.value) return { icon: CircleAlert, tone: 'text-warn bg-warn-soft', title: t('education.verify.not_found'), text: t('education.verify.not_found_text') };
    const status = result.value?.status;
    if (status === 'valid') return { icon: CircleCheck, tone: 'text-ok bg-ok-soft', title: t('education.verify.valid'), text: t('education.verify.valid_text') };
    if (status === 'revoked') return { icon: CircleX, tone: 'text-bad bg-bad-soft', title: t('education.verify.revoked'), text: t('education.verify.revoked_text', { date: formatDate(result.value.document.revoked_on) }) };
    if (status === 'expired') return { icon: Clock, tone: 'text-warn bg-warn-soft', title: t('education.verify.expired'), text: t('education.verify.expired_text', { date: formatDate(result.value.document.valid_until) }) };
    return null;
});
const rows = computed(() => {
    const value = result.value;
    if (!value) return [];
    return [
        { label: t('education.verify.kind'), text: value.document.title || t(`education.document_kinds.${value.document.kind}`) },
        { label: t('education.verify.number'), text: value.document.number, ltr: true },
        value.student.name ? { label: t('education.verify.student'), text: value.student.name } : null,
        value.student.level ? { label: t('education.verify.level'), text: value.student.level } : null,
        value.document.issued_on ? { label: t('education.verify.issued_on'), text: formatDate(value.document.issued_on) } : null,
        value.document.valid_until ? { label: t('education.verify.valid_until'), text: formatDate(value.document.valid_until) } : null,
    ].filter(Boolean);
});
</script>

<template>
    <main class="grid min-h-dvh place-items-center bg-subtle px-4 py-10">
        <div class="w-full max-w-md">
            <section class="card overflow-hidden">
                <!-- The institution that issued it. -->
                <header v-if="result" class="flex items-center gap-3 border-b border-line px-5 py-4" :style="result.institution.color ? { borderTop: `4px solid ${result.institution.color}` } : {}">
                    <img v-if="result.institution.logo_url" :src="result.institution.logo_url" alt="" class="size-10 rounded-lg object-contain" />
                    <span class="min-w-0">
                        <span class="block truncate text-[15px] font-semibold text-fg">{{ result.institution.name }}</span>
                        <span class="block text-[12px] text-muted">{{ t('education.verify.title') }}</span>
                    </span>
                </header>

                <div v-if="loading" class="flex flex-col items-center gap-3 px-6 py-12 text-[13.5px] text-muted" role="status">
                    <ShieldCheck class="size-10 animate-pulse text-faint" aria-hidden="true" />{{ t('education.verify.checking') }}
                </div>
                <div v-else-if="failed" class="flex flex-col items-center gap-3 px-6 py-10 text-center">
                    <p class="text-[13.5px] text-muted">{{ failed.message }}</p>
                    <AppButton :icon="RotateCw" @click="check">{{ t('education.verify.try_again') }}</AppButton>
                </div>
                <template v-else-if="state">
                    <div class="flex flex-col items-center gap-3 px-6 pt-8 pb-6 text-center" role="status">
                        <span class="grid size-16 place-items-center rounded-full" :class="state.tone"><component :is="state.icon" class="size-9" aria-hidden="true" /></span>
                        <h1 class="text-[18px] font-semibold text-fg">{{ state.title }}</h1>
                        <p class="max-w-xs text-[13.5px] text-muted">{{ state.text }}</p>
                    </div>
                    <dl v-if="rows.length" class="mx-5 mb-5 divide-y divide-line rounded-xl border border-line text-[13.5px]">
                        <div v-for="row in rows" :key="row.label" class="flex justify-between gap-4 px-4 py-2.5">
                            <dt class="text-muted">{{ row.label }}</dt>
                            <dd class="text-end font-medium text-fg" :dir="row.ltr ? 'ltr' : undefined">{{ row.text }}</dd>
                        </div>
                    </dl>
                </template>
            </section>
            <p class="mt-4 flex items-center justify-center gap-1.5 text-center text-[12px] text-faint"><ShieldCheck class="size-3.5" aria-hidden="true" />{{ t('education.verify.footer') }}</p>
        </div>
    </main>
</template>
