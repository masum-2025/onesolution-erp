<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { CalendarRange, Check, GraduationCap, Sparkles } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import { currentOrganization } from '@/lib/session';
import { i18n, t } from '@/lib/i18n';
import { textIn } from '@/lib/texts';
import { toast } from '@/lib/toast';
import { educationApi } from '../api';
import { useEducationSetup } from '../setup';
import { suggestSessions } from '../lib';

/**
 * First steps of an institution: pick a ready preset (programs, classes,
 * subjects and lists for a kind of institution) or set up by hand, then
 * make the academic year with the sessions its programs are taught in.
 * Shown on the overview until both are done; everything stays editable.
 */
const emit = defineEmits(['done']);

const org = currentOrganization();
const education = educationApi(org.id);
const setup = useEducationSetup();

const hasPrograms = computed(() => (setup.data.value?.programs?.length ?? 0) > 0);
const step = computed(() => (hasPrograms.value ? 'year' : 'preset'));
const applying = ref(null);

async function apply(key) {
    applying.value = key;
    try {
        const { data } = await education.applyPreset(key);
        toast.success(t('education.wizard.applied', { count: Object.values(data.made).reduce((sum, count) => sum + count, 0) }));
        await setup.reload();
    } catch (error) {
        toast.error(error.message);
    } finally {
        applying.value = null;
    }
}

// The year: this calendar year by default (Bangladesh schools run January to December).
const thisYear = new Date().getFullYear();
const year = reactive({ name: String(thisYear), starts_on: `${thisYear}-01-01`, ends_on: `${thisYear}-12-31` });
const sessions = ref([]);
const errors = ref({});
const making = ref(false);

/** Kinds of session the programs need, with the most periods any program of that kind has. */
const kinds = computed(() => {
    const found = new Map();
    for (const program of setup.data.value?.programs ?? []) {
        if (!program.is_active) continue;
        found.set(program.progression, Math.max(found.get(program.progression) ?? 1, program.periods_per_year ?? 1));
    }
    return [...found.entries()].map(([kind, periods]) => ({ kind, periods }));
});

function suggest() {
    sessions.value = kinds.value.flatMap(({ kind, periods }) =>
        suggestSessions(kind, periods, year.starts_on, year.ends_on).map((session) => ({
            ...session,
            kind,
            name: kind === 'year' ? year.name : `${t(`education.progressions.${kind}`)} ${session.sequence} · ${year.name}`,
        })),
    );
}
watch(() => [year.name, year.starts_on, year.ends_on, kinds.value], suggest, { immediate: true });

async function makeYear() {
    making.value = true;
    errors.value = {};
    try {
        const { data: made } = await education.create('years', { name: year.name.trim(), starts_on: year.starts_on, ends_on: year.ends_on, status: 'open' });
        for (const session of sessions.value) {
            // The name people typed, in English and in the language they work in (editable later).
            const name = { en: session.name.trim(), [i18n.locale]: session.name.trim() };
            await education.create('sessions', { academic_year_id: made.id, kind: session.kind, sequence: session.sequence, name, starts_on: session.starts_on, ends_on: session.ends_on, status: 'open' });
        }
        toast.success(t('education.wizard.made', { name: made.name }));
        await setup.reload();
        emit('done');
    } catch (error) {
        errors.value = error.errors ?? {};
        if (!Object.keys(errors.value).length) toast.error(error.message);
        await setup.reload();
    } finally {
        making.value = false;
    }
}

const fieldError = (name) => errors.value[name]?.[0] ?? null;
const stepsDone = computed(() => ({ preset: hasPrograms.value, year: false }));
</script>

<template>
    <section class="card overflow-hidden">
        <header class="relative border-b border-line bg-gradient-to-br from-brand-soft via-surface to-surface px-5 py-6 sm:px-7">
            <span class="mb-3 grid size-11 place-items-center rounded-2xl bg-brand text-brand-fg shadow-sm" aria-hidden="true"><GraduationCap class="size-6" /></span>
            <h2 class="text-[18px] font-semibold text-fg">{{ t('education.wizard.title') }}</h2>
            <p class="mt-1 max-w-2xl text-[13.5px] text-muted">{{ t('education.wizard.text') }}</p>
            <ol class="mt-4 flex flex-wrap gap-2">
                <li v-for="(key, index) in ['preset', 'year']" :key="key"
                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-[12.5px]"
                    :class="step === key ? 'border-brand bg-surface font-semibold text-fg' : 'border-line text-muted'"
                    :aria-current="step === key ? 'step' : undefined">
                    <Check v-if="stepsDone[key]" class="size-3.5 text-ok" aria-hidden="true" />
                    <span v-else class="tabular">{{ index + 1 }}</span>
                    {{ t(`education.wizard.step_${key}`) }}
                </li>
            </ol>
        </header>

        <div v-if="step === 'preset'" class="p-5 sm:p-7">
            <p class="mb-4 text-[13px] text-muted">{{ t('education.wizard.preset_hint') }}</p>
            <ul class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                <li v-for="preset in setup.data.value?.presets ?? []" :key="preset.key" class="flex flex-col rounded-2xl border border-line p-4 transition hover:border-brand/50 hover:shadow-sm">
                    <span class="mb-2 grid size-9 place-items-center rounded-xl bg-brand-soft text-brand-text" aria-hidden="true"><Sparkles class="size-[18px]" /></span>
                    <h3 class="text-[14.5px] font-semibold text-fg">{{ textIn(preset.name) }}</h3>
                    <p class="mt-1 flex-1 text-[12.5px] leading-relaxed text-muted">{{ textIn(preset.description) }}</p>
                    <AppButton class="mt-3 self-start" size="sm" variant="primary" :loading="applying === preset.key" :disabled="applying !== null" @click="apply(preset.key)">
                        {{ t('education.wizard.apply') }}
                    </AppButton>
                </li>
            </ul>
            <p class="mt-5 text-[12.5px] text-muted">
                {{ t('education.wizard.manual_hint') }}
                <RouterLink :to="{ name: 'education-structure' }" class="font-medium text-brand-text underline-offset-2 hover:underline">{{ t('education.wizard.open_structure') }}</RouterLink>
            </p>
        </div>

        <form v-else class="space-y-5 p-5 sm:p-7" novalidate @submit.prevent="makeYear">
            <div class="grid gap-4 sm:grid-cols-3">
                <AppField v-slot="{ id }" :label="t('education.wizard.year_name')" :hint="t('education.wizard.year_name_hint')" :error="fieldError('name')">
                    <input :id="id" v-model="year.name" class="field-input" maxlength="40" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.wizard.starts_on')" :error="fieldError('starts_on')">
                    <input :id="id" v-model="year.starts_on" type="date" class="field-input" />
                </AppField>
                <AppField v-slot="{ id }" :label="t('education.wizard.ends_on')" :error="fieldError('ends_on')">
                    <input :id="id" v-model="year.ends_on" type="date" class="field-input" :min="year.starts_on" />
                </AppField>
            </div>

            <fieldset v-if="sessions.length">
                <legend class="flex items-center gap-2 text-[13.5px] font-semibold text-fg"><CalendarRange class="size-4 text-muted" aria-hidden="true" />{{ t('education.wizard.sessions_title') }}</legend>
                <p class="mb-3 mt-0.5 text-[12.5px] text-muted">{{ t('education.wizard.sessions_text') }}</p>
                <div class="space-y-2">
                    <div v-for="(session, index) in sessions" :key="`${session.kind}-${session.sequence}`" class="grid grid-cols-1 gap-2 rounded-xl border border-line p-3 sm:grid-cols-[minmax(0,1.4fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <AppField v-slot="{ id }" :label="t('education.wizard.session_name')">
                            <input :id="id" v-model="session.name" class="field-input" maxlength="60" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.wizard.starts_on')">
                            <input :id="id" v-model="sessions[index].starts_on" type="date" class="field-input" :min="year.starts_on" :max="year.ends_on" />
                        </AppField>
                        <AppField v-slot="{ id }" :label="t('education.wizard.ends_on')">
                            <input :id="id" v-model="sessions[index].ends_on" type="date" class="field-input" :min="session.starts_on" :max="year.ends_on" />
                        </AppField>
                    </div>
                </div>
            </fieldset>

            <AppButton variant="primary" type="submit" :loading="making" :disabled="!year.name.trim() || !year.starts_on || !year.ends_on">{{ t('education.wizard.make') }}</AppButton>
        </form>
    </section>
</template>
