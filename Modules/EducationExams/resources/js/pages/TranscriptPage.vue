<script setup>
import { ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ScrollText, Search } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import EmptyState from '@/components/EmptyState.vue';
import { currentOrganization } from '@/lib/session';
import { t } from '@/lib/i18n';
import { examsApi } from '../api';
import StudentResults from '../components/StudentResults.vue';

/**
 * A student's published results, newest first, each with its subjects,
 * and the cumulative grade point. Find a student by name, ID or phone;
 * teachers find only students whose whole result they may see.
 */
const route = useRoute();
const router = useRouter();
const api = examsApi(currentOrganization().id);

const query = ref('');
const found = ref([]);
const searching = ref(false);
const searchError = ref(null);
let timer = null;
watch(query, (text) => {
    clearTimeout(timer);
    searchError.value = null;
    if (text.trim().length < 2) {
        found.value = [];
        return;
    }
    timer = setTimeout(async () => {
        searching.value = true;
        try {
            found.value = (await api.findStudents(text.trim())).data;
        } catch (error) {
            searchError.value = error;
        } finally {
            searching.value = false;
        }
    }, 300);
});
function choose(student) {
    query.value = '';
    found.value = [];
    router.push({ name: 'exams-transcript', params: { id: student.id } });
}

</script>

<template>
    <div>
        <PageHeader :title="t('education_exams.transcript.title')" :description="t('education_exams.transcript.text')" />

        <div class="relative mb-5 sm:w-96">
            <label>
                <span class="sr-only">{{ t('education_exams.transcript.search') }}</span>
                <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                <input v-model="query" type="search" class="field-input ps-9" :placeholder="t('education_exams.transcript.search')" autocomplete="off" />
            </label>
            <div v-if="query.trim().length >= 2" class="absolute inset-x-0 top-full z-20 mt-1 overflow-hidden rounded-lg border border-line bg-raised shadow-lg">
                <p v-if="searching" class="px-4 py-3 text-[13px] text-muted">{{ t('education_exams.transcript.searching') }}</p>
                <p v-else-if="searchError" class="px-4 py-3 text-[13px] text-bad">{{ searchError.message }}</p>
                <p v-else-if="!found.length" class="px-4 py-3 text-[13px] text-muted">{{ t('education_exams.transcript.none_found') }}</p>
                <ul v-else class="max-h-72 divide-y divide-line overflow-y-auto">
                    <li v-for="student in found" :key="student.id">
                        <button type="button" class="block w-full px-4 py-2.5 text-start hover:bg-subtle" @click="choose(student)">
                            <span class="block text-[14px] text-fg">{{ student.name }}</span>
                            <span class="block font-mono text-[12px] text-muted" dir="ltr">{{ student.code }}</span>
                        </button>
                    </li>
                </ul>
            </div>
        </div>

        <div v-if="!route.params.id" class="card">
            <EmptyState :icon="ScrollText" :title="t('education_exams.transcript.pick')" :text="t('education_exams.transcript.pick_text')" />
        </div>
        <StudentResults v-else :student-id="String(route.params.id)" named />
    </div>
</template>
