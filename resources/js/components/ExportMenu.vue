<script setup>
import { computed, ref, watch } from 'vue';
import { Download, FileSpreadsheet, FileText, FileType, Printer, Sheet } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppMenu from '@/components/AppMenu.vue';
import { exportSettings, runExport } from '@/lib/pageExport';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * Export and print for one page: only what the company's rules and the
 * module allow here (asked from the server), so a switched-off format or
 * module shows no button at all. options: { module, page, title, columns,
 * rows?, fetchAll? } (see lib/pageExport.js).
 */
const props = defineProps({
    options: { type: Object, required: true },
});

const allowed = ref({ formats: [], print: false });
const busy = ref(false);
watch(() => props.options.module, async (module) => {
    allowed.value = await exportSettings(module);
}, { immediate: true });

const icons = { pdf: FileType, xlsx: FileSpreadsheet, csv: Sheet, docx: FileText };
// Tables export to every allowed format; a page without columns (one record) only prints, or saves as PDF.
const formats = computed(() => allowed.value.formats.filter((format) => format === 'pdf' || props.options.columns?.length));
const items = computed(() => [
    ...formats.value.map((format) => ({ label: t(`core.export.formats.${format}`), icon: icons[format], hint: format === 'pdf' ? t('core.export.pdf_hint') : undefined, onSelect: () => go(format) })),
    ...(allowed.value.print ? [{ divider: true }, { label: t('core.export.print'), icon: Printer, onSelect: () => go('print') }] : []),
].filter((item, index, list) => !(item.divider && index === 0) && !(item.divider && index === list.length - 1)));

async function go(format) {
    busy.value = true;
    try {
        await runExport(format, props.options);
        if (!['pdf', 'print'].includes(format)) toast.success(t('core.export.done'));
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <AppMenu v-if="items.length" :items="items" :label="t('core.export.menu')" class="print:hidden">
        <template #trigger="{ toggle, attrs }">
            <AppButton size="sm" :icon="Download" :loading="busy" v-bind="attrs" @click="toggle(false)">{{ t('core.export.menu') }}</AppButton>
        </template>
    </AppMenu>
</template>
