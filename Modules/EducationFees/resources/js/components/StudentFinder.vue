<script setup>
import { onBeforeUnmount, ref, watch } from 'vue';
import { Search } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { feesApi } from '../api';
import { useFeesSetup } from '../setup';

/**
 * Finding a student by name, student ID or phone (Bangla digits too), each
 * with what they owe and hold, as one types.
 */
const emit = defineEmits(['choose']);
const api = feesApi(currentOrganization().id);
const setup = useFeesSetup();

const term = ref('');
const found = ref([]);
const searching = ref(false);
let timer = null;
watch(term, (value) => {
    clearTimeout(timer);
    if (value.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        searching.value = true;
        found.value = (await api.findStudents(value.trim()).catch(() => ({ data: [] }))).data;
        searching.value = false;
    }, 250);
});
onBeforeUnmount(() => clearTimeout(timer));

function choose(student) {
    emit('choose', student);
    term.value = '';
    found.value = [];
}
</script>

<template>
    <div>
        <label class="relative block">
            <span class="sr-only">{{ t('education_fees.counter.search') }}</span>
            <Search class="pointer-events-none absolute start-4 top-1/2 size-5 -translate-y-1/2 text-faint" aria-hidden="true" />
            <input v-model="term" type="search" class="field-input h-12 rounded-2xl ps-12 text-[15px]" :placeholder="t('education_fees.counter.search')" autocomplete="off" autofocus />
        </label>
        <p v-if="term.trim().length > 0 && term.trim().length < 2" class="mt-2 text-[12.5px] text-muted">{{ t('education_fees.counter.search_hint') }}</p>
        <p v-else-if="term.trim().length >= 2 && !searching && !found.length" class="mt-3 text-[13px] text-muted">{{ t('education_fees.counter.none') }}</p>
        <ul v-if="found.length" class="mt-3 divide-y divide-line overflow-hidden rounded-2xl border border-line bg-surface">
            <li v-for="student in found" :key="student.id">
                <button type="button" class="flex w-full items-center gap-3 px-4 py-3 text-start transition hover:bg-subtle/70" @click="choose(student)">
                    <span class="grid size-9 shrink-0 place-items-center rounded-full bg-brand-soft text-[13px] font-semibold text-brand-text" aria-hidden="true">{{ student.name.slice(0, 1) }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-[14px] font-medium text-fg">{{ student.name }}<template v-if="student.name_local"> · {{ student.name_local }}</template></span>
                        <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ student.code }}</span>
                    </span>
                    <AppBadge v-if="student.owed_minor > 0" tone="warn">{{ t('education_fees.counter.owes') }} {{ setup.money(student.owed_minor) }}</AppBadge>
                    <AppBadge v-else tone="ok">{{ t('education_fees.counter.nothing_owed') }}</AppBadge>
                </button>
            </li>
        </ul>
    </div>
</template>
