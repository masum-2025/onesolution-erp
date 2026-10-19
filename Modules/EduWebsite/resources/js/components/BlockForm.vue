<script setup>
import { computed, ref } from 'vue';
import { ArrowDown, ArrowUp, FileText, ImagePlus, Plus, Trash2, X } from 'lucide-vue-next';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import AppSegmented from '@/components/AppSegmented.vue';
import AppSwitch from '@/components/AppSwitch.vue';
import TranslatedFields from '@/components/TranslatedFields.vue';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';
import { blankItem } from '../lib';
import MediaPicker from './MediaPicker.vue';

/**
 * The fields of one block (or of one item of a repeated group), drawn from
 * its field list (BLOCKS in ../lib.js). `form` is the reactive form the
 * parent sends; `errors` are the API's, keyed "data.<field>…"; `media`
 * maps picture ids to rows (for thumbnails).
 */
const props = defineProps({
    fields: { type: Array, required: true },
    form: { type: Object, required: true },
    errors: { type: Object, default: () => ({}) },
    prefix: { type: String, default: 'data' },
    media: { type: Object, default: () => ({}) },
    web: { type: Object, required: true },
    maxMb: { type: Number, default: 5 },
    canUpload: Boolean,
    disabled: Boolean,
});
const emit = defineEmits(['media']);

const visible = computed(() => props.fields.filter((field) => !field.when || field.when(props.form)));
const label = (field) => t(`edu_website.fields.${field.label ?? field.key}`);
const errorOf = (key) => props.errors[`${props.prefix}.${key}`]?.[0] ?? null;

// One picker for the whole form: which field it fills.
const picking = ref(null);
function pick(ids, rows) {
    emit('media', rows);
    const field = picking.value;
    props.form[field.key] = field.kind === 'image' ? ids[0] ?? '' : ids;
    picking.value = null;
}

function move(list, index, step) {
    const [item] = list.splice(index, 1);
    list.splice(index + step, 0, item);
}
</script>

<template>
    <div class="grid gap-4">
        <template v-for="field in visible" :key="field.key">
            <TranslatedFields
                v-if="field.kind === 'text'"
                v-model="form[field.key]"
                :label="label(field)"
                :required="field.required"
                :multiline="field.long"
                :maxlength="field.max"
                :errors="errors"
                :error-prefix="`${prefix}.${field.key}`"
            />

            <AppField v-else-if="field.kind === 'name'" v-slot="{ id }" :label="label(field)" :error="errorOf(field.key)">
                <input :id="id" v-model="form[field.key]" class="field-input" :maxlength="field.max" :disabled="disabled" />
            </AppField>

            <AppField v-else-if="field.kind === 'link'" v-slot="{ id }" :label="label(field)" :hint="t('edu_website.fields.link_hint')" :error="errorOf(field.key)" optional>
                <input :id="id" v-model="form[field.key]" class="field-input" dir="ltr" inputmode="url" maxlength="300" placeholder="https://" :disabled="disabled" />
            </AppField>

            <AppField v-else-if="field.kind === 'date'" v-slot="{ id }" :label="label(field)" :error="errorOf(field.key)" optional>
                <input :id="id" v-model="form[field.key]" type="date" class="field-input sm:max-w-56" :disabled="disabled" />
            </AppField>

            <AppField v-else-if="field.kind === 'number'" v-slot="{ id }" :label="label(field)" :error="errorOf(field.key)">
                <input :id="id" v-model.number="form[field.key]" type="number" :min="field.min" :max="field.max" class="field-input tabular sm:max-w-32" :disabled="disabled" />
            </AppField>

            <AppSwitch v-else-if="field.kind === 'switch'" v-model="form[field.key]" :label="label(field)" show-label :disabled="disabled" />

            <AppField v-else-if="field.kind === 'choice'" :label="label(field)" :error="errorOf(field.key)">
                <AppSegmented
                    v-model="form[field.key]"
                    :label="label(field)"
                    :options="field.options.map((value) => ({ value, label: t(`edu_website.fields.${field.key}_${value}`) }))"
                />
            </AppField>

            <AppField v-else-if="field.kind === 'image'" :label="label(field)" :error="errorOf(field.key)" optional>
                <div class="flex flex-wrap items-center gap-3">
                    <img v-if="form[field.key] && media[form[field.key]]?.thumb" :src="media[form[field.key]].thumb" alt="" class="h-16 w-24 rounded-md border border-line object-cover" />
                    <AppButton size="sm" :icon="ImagePlus" :disabled="disabled" @click="picking = field">{{ form[field.key] ? t('edu_website.media.change') : t('edu_website.media.choose') }}</AppButton>
                    <AppButton v-if="form[field.key]" size="sm" variant="ghost" :icon="X" :disabled="disabled" @click="form[field.key] = ''">{{ t('edu_website.media.remove_from_block') }}</AppButton>
                </div>
            </AppField>

            <AppField v-else-if="field.kind === 'media'" :label="label(field)" :error="errorOf(field.key)">
                <div class="space-y-3">
                    <ul v-if="form[field.key].length" class="grid grid-cols-3 gap-2 sm:grid-cols-5">
                        <li v-for="(id, index) in form[field.key]" :key="id" class="relative overflow-hidden rounded-md border border-line bg-subtle">
                            <img v-if="media[id]?.thumb" :src="media[id].thumb" alt="" class="aspect-[4/3] w-full object-cover" />
                            <span v-else class="flex aspect-[4/3] items-center justify-center px-1 text-center text-[11px] text-muted"><FileText class="me-1 size-4 shrink-0" aria-hidden="true" />{{ media[id]?.name ?? '' }}</span>
                            <button
                                type="button"
                                class="absolute end-1 top-1 flex size-6 items-center justify-center rounded-full bg-black/60 text-white"
                                :aria-label="t('edu_website.media.remove_from_block')"
                                :disabled="disabled"
                                @click="form[field.key].splice(index, 1)"
                            >
                                <X class="size-3.5" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                    <AppButton size="sm" :icon="field.media === 'pdf' ? FileText : ImagePlus" :disabled="disabled" @click="picking = field">
                        {{ t(field.media === 'pdf' ? 'edu_website.media.choose_files' : 'edu_website.media.choose_pictures') }}
                    </AppButton>
                </div>
            </AppField>

            <fieldset v-else-if="field.kind === 'items'" class="rounded-lg border border-line">
                <legend class="ms-3 px-1 text-[13px] font-medium text-fg-2">{{ label(field) }} · {{ formatNumber(form[field.key].length) }}</legend>
                <p v-if="errorOf(field.key)" class="px-4 pt-2 text-[12.5px] text-bad">{{ errorOf(field.key) }}</p>
                <ol class="divide-y divide-line">
                    <li v-for="(item, index) in form[field.key]" :key="index" class="space-y-3 px-4 py-3">
                        <div class="flex items-center gap-1">
                            <span class="me-auto text-[12.5px] font-semibold text-muted">{{ formatNumber(index + 1) }}</span>
                            <AppButton size="icon-sm" variant="ghost" :icon="ArrowUp" :aria-label="t('edu_website.common.move_up')" :disabled="disabled || index === 0" @click="move(form[field.key], index, -1)" />
                            <AppButton size="icon-sm" variant="ghost" :icon="ArrowDown" :aria-label="t('edu_website.common.move_down')" :disabled="disabled || index === form[field.key].length - 1" @click="move(form[field.key], index, 1)" />
                            <AppButton size="icon-sm" variant="ghost" :icon="Trash2" :aria-label="t('edu_website.common.remove')" :disabled="disabled" @click="form[field.key].splice(index, 1)" />
                        </div>
                        <BlockForm
                            :fields="field.fields"
                            :form="item"
                            :errors="errors"
                            :prefix="`${prefix}.${field.key}.${index}`"
                            :media="media"
                            :web="web"
                            :max-mb="maxMb"
                            :can-upload="canUpload"
                            :disabled="disabled"
                            @media="(rows) => emit('media', rows)"
                        />
                    </li>
                </ol>
                <div class="px-4 pb-3 pt-1">
                    <AppButton size="sm" :icon="Plus" :disabled="disabled || form[field.key].length >= field.max" @click="form[field.key].push(blankItem(field.fields))">{{ t('edu_website.fields.add_item') }}</AppButton>
                </div>
            </fieldset>
        </template>

        <MediaPicker
            :open="picking !== null"
            :web="web"
            :kind="picking?.media ?? 'image'"
            :multiple="picking?.kind === 'media'"
            :selected="picking ? (picking.kind === 'image' ? (form[picking.key] ? [form[picking.key]] : []) : form[picking.key]) : []"
            :max-mb="maxMb"
            :can-upload="canUpload"
            @close="picking = null"
            @pick="pick"
        />
    </div>
</template>
