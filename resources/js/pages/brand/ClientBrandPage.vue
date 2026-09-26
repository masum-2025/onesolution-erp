<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { ImageUp, Lock, Palette, Trash2 } from 'lucide-vue-next';
import PageHeader from '@/components/PageHeader.vue';
import AppButton from '@/components/AppButton.vue';
import AppField from '@/components/AppField.vue';
import EmptyState from '@/components/EmptyState.vue';
import ErrorState from '@/components/ErrorState.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { contrastProblem, readableOn } from '@/lib/brand';
import { currentOrganization, loadMe } from '@/lib/session';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * The organization's own brand: name, color and logo its people see (the
 * app, its own address, emails), where its provider allows it.
 */
const org = currentOrganization();
const brand = useResource(() => api(`/api/organizations/${org.id}/brand`).then((response) => response.data));
const data = computed(() => brand.data.value);
const form = reactive({ display_name: '', primary_color: '' });
const errors = ref({});
const saving = ref(false);
const uploading = ref(false);

// Fill the form from the server on first load and after a save only, so a
// logo upload never wipes a name or color the person has not saved yet.
function fill(value) {
    form.display_name = value.own.display_name ?? '';
    form.primary_color = value.own.primary_color ?? '';
}
const stopFirstFill = watch(data, (value) => {
    if (!value) return;
    fill(value);
    stopFirstFill();
});

const color = computed(() => (/^#[0-9A-Fa-f]{6}$/.test(form.primary_color) ? form.primary_color : data.value?.partner.primary_color ?? '#2B4C9B'));
const colorProblem = computed(() => (/^#[0-9A-Fa-f]{6}$/.test(form.primary_color) ? contrastProblem(form.primary_color) : null));

async function save() {
    errors.value = {};
    saving.value = true;
    try {
        const response = await api(`/api/organizations/${org.id}/brand`, {
            method: 'PATCH',
            body: { display_name: form.display_name.trim() || null, primary_color: form.primary_color.trim() || null },
        });
        brand.data.value = response.data;
        fill(response.data);
        toast.success(response.message);
        await loadMe();
    } catch (error) {
        errors.value = Object.fromEntries(Object.entries(error.errors ?? {}).map(([field, messages]) => [field, messages[0]]));
        if (error.data?.field) errors.value[error.data.field] = error.message;
        if (!Object.keys(errors.value).length) toast.error(error.message);
    } finally {
        saving.value = false;
    }
}

async function upload(event) {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;
    const body = new FormData();
    body.append('file', file);
    uploading.value = true;
    try {
        const response = await api(`/api/organizations/${org.id}/brand/logo`, { method: 'POST', body });
        brand.data.value = response.data;
        toast.success(response.message);
        await loadMe();
    } catch (error) {
        toast.error(error.field?.('file') ?? error.message);
    } finally {
        uploading.value = false;
    }
}

async function removeLogo() {
    try {
        const response = await api(`/api/organizations/${org.id}/brand/logo`, { method: 'DELETE' });
        brand.data.value = response.data;
        toast.success(response.message);
        await loadMe();
    } catch (error) {
        toast.error(error.message);
    }
}
</script>

<template>
    <div>
        <PageHeader :title="t('brand.title')" :description="t('brand.text')" />

        <SkeletonRows v-if="brand.loading.value && !data" :rows="4" />
        <ErrorState v-else-if="brand.error.value" :error="brand.error.value" @retry="brand.reload()" />
        <section v-else-if="data && !data.allowed" class="card">
            <EmptyState :icon="Lock" :title="t('brand.not_offered_title')" :text="t('brand.not_offered_text', { provider: data.partner.name })" compact />
        </section>

        <div v-else-if="data" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
            <form class="card space-y-5 p-5" novalidate @submit.prevent="save">
                <p v-if="!data.can_edit" class="flex items-center gap-2 rounded-xl bg-subtle p-3 text-[13px] text-fg-2"><Lock class="size-4 text-muted" aria-hidden="true" />{{ t('brand.read_only') }}</p>

                <AppField :label="t('brand.name')" :hint="t('brand.name_hint', { name: data.partner.name })" :error="errors.display_name" optional>
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="form.display_name" class="field-input" maxlength="60" :placeholder="data.partner.name" :disabled="!data.can_edit" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>

                <AppField :label="t('brand.color')" :hint="t('brand.color_hint')" :error="errors.primary_color ?? (colorProblem ? t(`brand.contrast_${colorProblem}`) : null)" optional>
                    <template #default="{ id, invalid, describedby }">
                        <div class="flex items-center gap-2">
                            <input type="color" :value="color" class="size-10 shrink-0 cursor-pointer rounded-lg border border-line bg-surface" :disabled="!data.can_edit" :aria-label="t('brand.color')" @input="form.primary_color = $event.target.value.toUpperCase()" />
                            <input :id="id" v-model="form.primary_color" class="field-input max-w-[9rem] font-mono uppercase" maxlength="7" dir="ltr" :placeholder="data.partner.primary_color" :disabled="!data.can_edit" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                        </div>
                    </template>
                </AppField>

                <div class="space-y-2">
                    <p class="text-[13px] font-medium text-fg">{{ t('brand.logo') }}</p>
                    <p class="text-[12.5px] text-muted">{{ t('brand.logo_hint') }}</p>
                    <div v-if="data.can_edit" class="flex flex-wrap gap-2">
                        <label class="inline-flex cursor-pointer items-center gap-2 rounded-[9px] border border-line-strong bg-surface px-3.5 py-2 text-[13.5px] font-medium text-fg hover:bg-subtle">
                            <ImageUp class="size-4" aria-hidden="true" />{{ uploading ? t('core.states.loading') : t('brand.upload') }}
                            <input type="file" accept="image/png,image/webp,image/jpeg" class="sr-only" :disabled="uploading" @change="upload" />
                        </label>
                        <AppButton v-if="data.own.has_logo" variant="danger-soft" :icon="Trash2" @click="removeLogo">{{ t('brand.remove_logo') }}</AppButton>
                    </div>
                </div>

                <AppButton v-if="data.can_edit" type="submit" variant="primary" :loading="saving" :disabled="!!colorProblem">{{ t('brand.save') }}</AppButton>
            </form>

            <!-- What the organization's people will see. -->
            <section class="card overflow-hidden" :aria-label="t('brand.preview')">
                <header class="border-b border-line px-5 py-3 text-[13px] font-medium text-fg-2">{{ t('brand.preview') }}</header>
                <div class="space-y-4 p-5">
                    <div class="flex items-center gap-3">
                        <img v-if="data.effective.logo_url" :src="data.effective.logo_url" alt="" class="h-9 max-w-[10rem] object-contain" />
                        <span v-else class="grid size-9 place-items-center rounded-lg text-[15px] font-bold" :style="{ background: color, color: readableOn(color) }">{{ (form.display_name || data.partner.name).slice(0, 1).toUpperCase() }}</span>
                        <span class="text-[15px] font-semibold text-fg">{{ form.display_name || data.partner.name }}</span>
                    </div>
                    <button type="button" class="rounded-[9px] px-3.5 py-2 text-[13.5px] font-medium" :style="{ background: color, color: readableOn(color) }" tabindex="-1">{{ t('brand.sample_button') }}</button>
                    <p class="flex items-center gap-2 text-[12.5px] text-muted"><Palette class="size-4" aria-hidden="true" />{{ t('brand.preview_note') }}</p>
                </div>
            </section>
        </div>
    </div>
</template>
