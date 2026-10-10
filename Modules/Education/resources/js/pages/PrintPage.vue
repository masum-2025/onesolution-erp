<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, Printer } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import ErrorState from '@/components/ErrorState.vue';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { educationApi } from '../api';
import { chunk, mm, sheetLayout } from '../lib';
import DocumentCanvas from '../components/DocumentCanvas.vue';

/**
 * Issued documents laid out to print at their real size: ID cards several
 * to an A4 sheet with a hairline to cut along, certificates and letters one
 * to a page. Only the documents print (the app around them hides). The
 * browser's "Save as PDF" makes a PDF.
 */
const SHEET = [2100, 2970];
const org = currentOrganization();
const education = educationApi(org.id);
const route = useRoute();
const router = useRouter();

const documents = ref([]);
const loading = ref(true);
const error = ref(null);

async function load() {
    const ids = String(route.query.ids ?? '').split(',').filter(Boolean).slice(0, 500);
    loading.value = true;
    error.value = null;
    try {
        const found = [];
        // A few at a time: kind to a slow connection and to the server.
        for (const group of chunk(ids, 6)) {
            found.push(...(await Promise.all(group.map((id) => education.document(id)))).map((result) => result.data));
        }
        documents.value = found;
    } catch (caught) {
        error.value = caught;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

function printPage() {
    window.print();
}

const cards = computed(() => documents.value.filter((document) => document.kind === 'id_card'));
const pages = computed(() => documents.value.filter((document) => document.kind !== 'id_card'));
const card = computed(() => cards.value[0]?.page_size ?? [856, 540]);
const grid = computed(() => sheetLayout(card.value, SHEET));
const sheets = computed(() => chunk(cards.value, grid.value.perSheet));
const paper = computed(() => (pages.value.length ? [...new Set(pages.value.map((document) => document.page.size.toUpperCase().replace('ID_CARD', 'ID')))].join(', ') : 'A4'));
</script>

<template>
    <div>
        <div class="mb-5 flex flex-wrap items-center gap-3 print:hidden">
            <AppButton variant="ghost" size="sm" :icon="ArrowLeft" @click="router.back()">{{ t('education.print.back') }}</AppButton>
            <h1 class="flex-1 text-[18px] font-semibold text-fg">{{ t('education.print.title') }}</h1>
            <AppButton variant="primary" :icon="Printer" :disabled="loading || !documents.length" @click="printPage">{{ t('education.print.print') }}</AppButton>
        </div>
        <div class="mb-5 space-y-1 rounded-xl bg-subtle px-4 py-3 text-[12.5px] text-muted print:hidden">
            <p>{{ t('education.print.hint', { paper }) }}</p>
            <p v-if="cards.length">{{ t('education.print.cards_hint', { count: grid.perSheet }) }}</p>
        </div>

        <p v-if="loading" class="py-10 text-center text-[13px] text-muted">{{ t('education.print.loading') }}</p>
        <ErrorState v-else-if="error" :error="error" @retry="load" />

        <div v-else class="space-y-6 overflow-x-auto print:space-y-0 print:overflow-visible">
            <!-- ID cards: several to an A4 sheet. -->
            <section
                v-for="(sheet, index) in sheets"
                :key="`sheet-${index}`"
                class="print-page mx-auto bg-white shadow-sm ring-1 ring-black/5 print:shadow-none print:ring-0"
                :style="{ width: mm(SHEET[0]), height: mm(SHEET[1]), padding: mm(50) }"
            >
                <div class="grid content-start justify-center" :style="{ gridTemplateColumns: `repeat(${grid.columns}, ${mm(card[0])})`, gap: mm(30) }">
                    <div v-for="document in sheet" :key="document.id" class="relative outline outline-[0.1mm] outline-dashed outline-slate-300">
                        <DocumentCanvas :page-size="document.page_size" :layout="document.layout" :values="document.values" :asset-links="document.asset_links" :photo-url="document.photo_url" :qr="document.qr" />
                        <span v-if="document.status === 'revoked'" class="absolute inset-0 grid place-items-center bg-white/60 text-[14pt] font-bold tracking-widest text-red-600 uppercase">{{ t('education.print.revoked') }}</span>
                    </div>
                </div>
            </section>
            <!-- Certificates and letters: one to a page. -->
            <section v-for="document in pages" :key="document.id" class="print-page relative mx-auto bg-white shadow-sm ring-1 ring-black/5 print:shadow-none print:ring-0" :style="{ width: mm(document.page_size[0]) }">
                <DocumentCanvas :page-size="document.page_size" :layout="document.layout" :values="document.values" :asset-links="document.asset_links" :photo-url="document.photo_url" :qr="document.qr" />
                <span v-if="document.status === 'revoked'" class="absolute inset-0 grid place-items-center text-[40pt] font-bold tracking-widest text-red-600/40 uppercase">{{ t('education.print.revoked') }}</span>
            </section>
        </div>
    </div>
</template>

<style scoped>
@media print {
    .print-page {
        break-after: page;
        margin: 0;
    }
    .print-page:last-child {
        break-after: auto;
    }
}
</style>
