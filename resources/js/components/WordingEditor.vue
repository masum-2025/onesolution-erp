<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { Download, RotateCcw, Save, SearchX, Upload } from 'lucide-vue-next';
import AppButton from './AppButton.vue';
import AppSegmented from './AppSegmented.vue';
import AppTabs from './AppTabs.vue';
import EmptyState from './EmptyState.vue';
import ErrorState from './ErrorState.vue';
import SkeletonRows from './SkeletonRows.vue';
import SourceBadge from './SourceBadge.vue';
import { api } from '@/lib/http';
import { confirmAction } from '@/lib/dialogs';
import { downloadText } from '@/lib/csv';
import { toast } from '@/lib/toast';
import { direction, languageName, t } from '@/lib/i18n';
import { hasMarkup, parseWordingFile, unknownPlaceholders, withPluralForms, wordingCsv } from '@/lib/wording';

/**
 * The wording editor (LANG-1), the same for every level: each text with its
 * English original, what people see now and where it comes from, and this
 * level's own wording. Saves one text at a time; a translator's file can be
 * exported and imported (all or nothing).
 *
 * `base` is the level's endpoint (…/translations); `params` go with every
 * call (the partner console's level).
 */
const props = defineProps({
    base: { type: String, required: true },
    params: { type: Object, default: () => ({}) },
    languages: { type: Array, required: true }, // [{ code, name, source, fallback }]
    canEdit: Boolean,
});

const emit = defineEmits(['changed']);

const locale = ref(props.languages.find((language) => language.code !== 'en')?.code ?? props.languages[0]?.code ?? 'en');
const channel = ref('ui');
const namespace = ref('');
const filter = ref('all');
const query = ref('');
const page = ref(1);

const rows = ref([]);
const meta = ref(null);
const loading = ref(false);
const error = ref(null);
const drafts = reactive({});
const busy = reactive({});
const fileInput = ref(null);

const language = computed(() => props.languages.find((item) => item.code === locale.value) ?? null);
const fallbackName = computed(() => languageName(language.value?.fallback ?? 'en'));
const textDirection = computed(() => direction(locale.value));
const shown = computed(() => (channel.value === 'ui' ? withPluralForms(rows.value, locale.value) : rows.value));

const languageOptions = computed(() => props.languages.map((item) => ({ value: item.code, label: item.name })));
const channelTabs = computed(() => ['ui', 'server'].map((key) => ({ key, label: t(`languages.channels.${key}`) })));
const filterTabs = computed(() => ['all', 'missing', 'own', 'changed'].map((key) => ({ key, label: t(`languages.filters.${key}`), count: meta.value?.counts?.[key] })));

let requestId = 0;
async function load() {
    const id = ++requestId;
    loading.value = true;
    error.value = null;
    try {
        const response = await api(props.base, {
            query: { ...props.params, locale: locale.value, channel: channel.value, namespace: namespace.value, filter: filter.value, q: query.value.trim(), page: page.value },
        });
        if (id !== requestId) return;
        rows.value = response.data;
        meta.value = response.meta;
        Object.keys(drafts).forEach((key) => delete drafts[key]);
    } catch (problem) {
        if (id === requestId) error.value = problem;
    } finally {
        if (id === requestId) loading.value = false;
    }
}

watch([locale, channel], () => {
    namespace.value = '';
    page.value = 1;
    load();
});
watch([namespace, filter], () => {
    page.value = 1;
    load();
});
watch(page, load);
watch(
    () => props.params,
    () => {
        page.value = 1;
        load();
    },
    { deep: true },
);

let searchTimer;
watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        load();
    }, 300);
});

load();

function draftOf(row) {
    return drafts[row.key] ?? row.own ?? '';
}

/** {name} in the browser, :name on the server, as people must write them. */
function placeholderList(names) {
    return (names ?? []).map((name) => (channel.value === 'ui' ? `{${name}}` : `:${name}`)).join(' ');
}

function problemOf(row) {
    const text = draftOf(row);
    if (hasMarkup(text)) return t('languages.markup');
    const unknown = unknownPlaceholders(text, row.placeholders ?? [], channel.value);
    if (unknown.length) {
        const allowed = placeholderList(row.placeholders) || t('languages.no_placeholders');
        return t('languages.unknown_placeholders', { names: unknown.join(', '), allowed });
    }
    return null;
}

function dirty(row) {
    return drafts[row.key] !== undefined && drafts[row.key].trim() !== (row.own ?? '') && drafts[row.key].trim() !== '';
}

function sourceOf(row) {
    if (row.own !== null) return { kind: 'self' };
    if (row.inherited) return { kind: 'inherited', level: row.inherited.level, name: row.inherited.name };
    return { kind: 'default' };
}

async function save(row) {
    if (problemOf(row)) return;
    busy[row.key] = true;
    try {
        const response = await api(props.base, { method: 'PUT', body: { ...props.params, locale: locale.value, channel: channel.value, key: row.key, value: drafts[row.key].trim() } });
        row.own = response.data.own;
        row.effective = response.data.own;
        delete drafts[row.key];
        toast.success(response.message);
        emit('changed');
    } catch (problem) {
        toast.error(problem.message);
    } finally {
        busy[row.key] = false;
    }
}

async function reset(row) {
    const answer = await confirmAction({ title: t('languages.reset_title'), message: t('languages.reset_text'), confirmLabel: t('languages.reset') });
    if (!answer) return;
    busy[row.key] = true;
    try {
        const response = await api(`${props.base}/reset`, { method: 'POST', body: { ...props.params, locale: locale.value, channel: channel.value, key: row.key } });
        toast.success(response.message);
        emit('changed');
        await load();
    } catch (problem) {
        toast.error(problem.message);
    } finally {
        busy[row.key] = false;
    }
}

async function exportFile() {
    try {
        const response = await api(`${props.base}/export`, { query: { ...props.params, locale: locale.value, channel: channel.value } });
        downloadText(`wording-${locale.value}-${channel.value}.csv`, wordingCsv(response.data));
    } catch (problem) {
        toast.error(problem.message);
    }
}

async function importFile(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    let texts;
    try {
        texts = parseWordingFile(await file.text(), file.name).filter((item) => item.value.trim() !== '');
    } catch (problem) {
        toast.error(t(`languages.${problem.code ?? 'bad_file'}`));
        return;
    }
    if (!texts.length) {
        toast.error(t('languages.import_empty'));
        return;
    }

    const answer = await confirmAction({
        title: t('languages.import_title', { count: texts.length }),
        message: t('languages.import_text', { language: language.value?.name ?? locale.value }),
        confirmLabel: t('languages.import'),
    });
    if (!answer) return;

    try {
        const response = await api(`${props.base}/import`, { method: 'POST', body: { ...props.params, locale: locale.value, channel: channel.value, texts } });
        toast.success(response.message);
        emit('changed');
        await load();
    } catch (problem) {
        toast.error(problem.data?.key ? `${problem.data.key}: ${problem.message}` : problem.message);
    }
}
</script>

<template>
    <section class="space-y-4">
        <div class="card space-y-3 p-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <AppSegmented v-if="languageOptions.length <= 5" v-model="locale" :options="languageOptions" :label="t('languages.language')" size="sm" />
                <label v-else class="block min-w-40">
                    <span class="mb-1 block text-[12.5px] font-medium text-fg-2">{{ t('languages.language') }}</span>
                    <select v-model="locale" class="field-input">
                        <option v-for="option in languageOptions" :key="option.value" :value="option.value">{{ option.label }}</option>
                    </select>
                </label>
                <div class="flex flex-wrap gap-2">
                    <AppButton size="sm" :icon="Download" @click="exportFile">{{ t('languages.export') }}</AppButton>
                    <template v-if="canEdit">
                        <AppButton size="sm" :icon="Upload" @click="fileInput?.click()">{{ t('languages.import') }}</AppButton>
                        <input ref="fileInput" type="file" accept=".csv,.json,text/csv,application/json" class="hidden" @change="importFile" />
                    </template>
                </div>
            </div>

            <AppTabs v-model="channel" :tabs="channelTabs" :label="t('languages.channel')" />

            <div class="grid gap-3 sm:grid-cols-[minmax(0,12rem)_minmax(0,1fr)]">
                <label class="block">
                    <span class="sr-only">{{ t('languages.namespace') }}</span>
                    <select v-model="namespace" class="field-input" :aria-label="t('languages.namespace')">
                        <option value="">{{ t('languages.all_parts') }}</option>
                        <option v-for="item in meta?.namespaces ?? []" :key="item" :value="item">{{ item }}</option>
                    </select>
                </label>
                <label class="block">
                    <span class="sr-only">{{ t('languages.search') }}</span>
                    <input v-model="query" type="search" class="field-input" :placeholder="t('languages.search_placeholder')" :aria-label="t('languages.search')" maxlength="100" />
                </label>
            </div>

            <AppTabs v-model="filter" :tabs="filterTabs" :label="t('languages.search')" />
        </div>

        <SkeletonRows v-if="loading && !rows.length" :rows="6" />
        <ErrorState v-else-if="error" :error="error" @retry="load" />
        <EmptyState v-else-if="!shown.length" :icon="SearchX" :title="t('languages.empty_title')" :text="t('languages.empty_text')" compact />

        <ul v-else class="space-y-3" :aria-busy="loading || undefined">
            <li v-for="row in shown" :key="row.key" class="card space-y-2.5 p-4">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <p class="font-mono text-[11.5px] break-all text-muted" dir="ltr">{{ row.key }}</p>
                    <SourceBadge v-bind="sourceOf(row)" />
                </div>
                <p v-if="row.plural" class="text-[12px] text-muted">{{ t('languages.plural_form', { form: row.plural }) }}</p>

                <dl class="grid gap-2 text-[13px] sm:grid-cols-2">
                    <div>
                        <dt class="text-[11.5px] font-medium tracking-wide text-muted uppercase">{{ t('languages.english') }}</dt>
                        <dd class="mt-0.5 whitespace-pre-wrap text-fg-2" dir="ltr" lang="en">{{ row.source }}</dd>
                    </div>
                    <div>
                        <dt class="text-[11.5px] font-medium tracking-wide text-muted uppercase">{{ t('languages.current') }}</dt>
                        <dd v-if="row.effective" class="mt-0.5 whitespace-pre-wrap text-fg" :dir="textDirection" :lang="locale">{{ row.effective }}</dd>
                        <dd v-else class="mt-0.5 text-warn">{{ t('languages.not_translated', { language: fallbackName }) }}</dd>
                    </div>
                </dl>

                <form v-if="canEdit" class="space-y-2" novalidate @submit.prevent="save(row)">
                    <label class="block">
                        <span class="mb-1 block text-[12.5px] font-medium text-fg-2">{{ t('languages.yours') }}</span>
                        <textarea
                            class="field-input min-h-[2.75rem] text-[13.5px]"
                            rows="2"
                            maxlength="1000"
                            :dir="textDirection"
                            :lang="locale"
                            :value="draftOf(row)"
                            :placeholder="row.effective ?? row.source"
                            :aria-invalid="problemOf(row) ? true : undefined"
                            @input="drafts[row.key] = $event.target.value"
                        />
                    </label>
                    <p v-if="problemOf(row)" class="text-[12.5px] text-bad" role="alert">{{ problemOf(row) }}</p>
                    <p v-else-if="row.placeholders?.length" class="text-[12px] text-muted">
                        {{ t('languages.placeholders', { names: placeholderList(row.placeholders) }) }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <AppButton type="submit" size="sm" variant="primary" :icon="Save" :loading="busy[row.key]" :disabled="!dirty(row) || !!problemOf(row)">{{ t('languages.save') }}</AppButton>
                        <AppButton v-if="row.own !== null" size="sm" variant="ghost" :icon="RotateCcw" :disabled="busy[row.key]" @click="reset(row)">{{ t('languages.reset') }}</AppButton>
                    </div>
                </form>
            </li>
        </ul>

        <nav v-if="meta && meta.last_page > 1" class="flex flex-wrap items-center justify-between gap-3" :aria-label="t('languages.page', { page: meta.page, pages: meta.last_page, total: meta.total })">
            <AppButton size="sm" :disabled="page <= 1 || loading" @click="page--">{{ t('languages.previous') }}</AppButton>
            <span class="text-[12.5px] text-muted">{{ t('languages.page', { page: meta.page, pages: meta.last_page, total: meta.total }) }}</span>
            <AppButton size="sm" :disabled="page >= meta.last_page || loading" @click="page++">{{ t('languages.next') }}</AppButton>
        </nav>
        <p v-else-if="meta" class="text-[12.5px] text-muted">{{ t('languages.page', { page: 1, pages: 1, total: meta.total }) }}</p>
    </section>
</template>
