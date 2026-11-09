<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowDown, ArrowLeft, ArrowUp, Check, ExternalLink, Facebook, ImagePlus, Megaphone, Search, Send, Trash2, Undo2, X } from 'lucide-vue-next';
import { AppButton, AppDialog, AppField, confirmAction, ErrorState, newOpId, PageFrame, SkeletonRows, StatusBadge, toast } from '@/ui';
import { useResource } from '@/lib/useResource';
import { formatDateTime, formatMoney } from '@/lib/format';
import { currentOrganization } from '@/lib/session';
import { i18n, t } from '@/lib/i18n';
import { fromLocalInput, POST_TONES, socialApi, toLocalInput } from '../api';

/**
 * Writing a Facebook post and following it: the page and language, the
 * text, products (each adds its line), photos, when it goes out; then send
 * it (for approval where the rules ask), approve or turn it down, take it
 * back, or see it on Facebook. The preview shows exactly what goes out.
 */
const social = socialApi(currentOrganization().id);
const route = useRoute();
const router = useRouter();
const isNew = computed(() => !route.params.id);
const options = useResource(() => social.options());
const opts = computed(() => options.data.value?.data ?? null);
const record = useResource(() => (isNew.value ? Promise.resolve({ data: null }) : social.post(route.params.id)));
const post = computed(() => record.data.value?.data ?? null);
const editable = computed(() => isNew.value || ['draft', 'rejected', 'failed'].includes(post.value?.status));
const busy = ref(false);

// The form (filled from the post when it loads).
const form = reactive({ page_id: '', locale: i18n.locale, message: '', publish_at: '', item_ids: [], products: [] });
const opId = newOpId();
watch(post, (value) => {
    if (!value) return;
    Object.assign(form, {
        page_id: value.page?.id ?? '',
        locale: value.locale,
        message: value.message ?? '',
        publish_at: toLocalInput(value.publish_at),
        item_ids: value.products.map((product) => product.item_id),
        products: value.products.map((product) => ({ id: product.item_id, name: product.name })),
    });
}, { immediate: true });
watch(opts, (value) => {
    if (value && !form.page_id) form.page_id = value.pages.find((page) => page.usable)?.id ?? value.pages[0]?.id ?? '';
}, { immediate: true });

const page = computed(() => opts.value?.pages.find((item) => item.id === form.page_id) ?? null);
const footer = computed(() => opts.value?.footers?.[form.locale] ?? '');
const preview = computed(() => [form.message.trim(), footer.value].filter(Boolean).join('\n\n'));
const photos = computed(() => post.value?.media ?? []);
const dirty = computed(() => {
    if (isNew.value) return true;
    const value = post.value;
    return !!value && (form.page_id !== value.page?.id || form.locale !== value.locale || form.message !== (value.message ?? '')
        || fromLocalInput(form.publish_at) !== (value.publish_at ? new Date(value.publish_at).toISOString() : null)
        || form.item_ids.join() !== value.products.map((product) => product.item_id).join());
});

function body() {
    return { page_id: form.page_id, locale: form.locale, message: form.message || null, publish_at: fromLocalInput(form.publish_at), item_ids: form.item_ids };
}

async function run(call, message) {
    busy.value = true;
    try {
        const response = await call();
        if (response?.data) record.data.value = { data: response.data };
        if (message ?? response?.message) toast.success(message ?? response.message);
        return response;
    } catch (error) {
        toast.error(error.message);
        if (error.status === 409) record.reload();
        return null;
    } finally {
        busy.value = false;
    }
}

async function save() {
    if (isNew.value) {
        const response = await run(() => social.create({ ...body(), op_id: opId }), t('social_commerce.post.saved'));
        if (response?.data) router.replace({ name: 'social-post', params: { id: response.data.id } });
        return response;
    }
    return run(() => social.update(post.value.id, { ...body(), base_version: post.value.version }), t('social_commerce.post.saved'));
}

async function send() {
    if (dirty.value && !(await save())) return;
    const fresh = post.value;
    if (fresh) await run(() => social.step(fresh.id, 'submit', { base_version: fresh.version }));
}

const approve = () => run(() => social.step(post.value.id, 'approve', { base_version: post.value.version }));
const withdraw = () => run(() => social.step(post.value.id, 'withdraw', { base_version: post.value.version }));

const turningDown = reactive({ open: false, reason: '' });
async function reject() {
    if (await run(() => social.step(post.value.id, 'reject', { base_version: post.value.version, reason: turningDown.reason }))) {
        turningDown.open = false;
    }
}

async function remove() {
    const ok = await confirmAction({ title: t('social_commerce.post.delete_title'), message: t('social_commerce.post.delete_text'), danger: true });
    if (!ok) return;
    if (await run(() => social.remove(post.value.id, { base_version: post.value.version }))) router.replace({ name: 'social-posts' });
}

// Products: search Inventory, each one chosen adds its line (name, price, stock) to the text.
const picking = reactive({ search: '', results: [], loading: false });
async function findItems() {
    picking.loading = true;
    try {
        picking.results = (await social.items({ search: picking.search || undefined, locale: form.locale })).data;
    } catch (error) {
        toast.error(error.message);
    } finally {
        picking.loading = false;
    }
}
function addItem(item) {
    if (form.item_ids.includes(item.id)) return;
    form.item_ids.push(item.id);
    form.products.push({ id: item.id, name: item.name });
    form.message = [form.message.trimEnd(), item.line].filter(Boolean).join('\n');
}
function dropItem(id) {
    form.item_ids = form.item_ids.filter((value) => value !== id);
    form.products = form.products.filter((product) => product.id !== id);
}

// Photos: added one by one (the post must be saved first), moved, removed.
const fileInput = ref(null);
async function addPhotos(event) {
    const files = [...(event.target.files ?? [])];
    event.target.value = '';
    if (isNew.value && !(await save())) return;
    for (const file of files) {
        if (file.size > (opts.value?.max_image_mb ?? 4) * 1024 * 1024) {
            toast.error(t('social_commerce.post.too_big', { name: file.name, mb: opts.value?.max_image_mb ?? 4 }));
            continue;
        }
        if (!(await run(() => social.addMedia(post.value.id, post.value.version, file)))) break;
    }
}
const removePhoto = (media) => run(() => social.removeMedia(post.value.id, media.id, { base_version: post.value.version }));
function movePhoto(index, by) {
    const ids = photos.value.map((media) => media.id);
    const [moved] = ids.splice(index, 1);
    ids.splice(index + by, 0, moved);
    run(() => social.orderMedia(post.value.id, { base_version: post.value.version, media_ids: ids }));
}

const sendLabel = computed(() => (opts.value?.needs_approval && !opts.value?.can_approve ? t('social_commerce.post.send_for_approval') : form.publish_at ? t('social_commerce.post.schedule') : t('social_commerce.post.publish_now')));
const title = computed(() => (isNew.value ? t('social_commerce.post.new_title') : t('social_commerce.post.title')));
</script>

<template>
    <PageFrame :title="title" :description="t('social_commerce.post.text')" width="full" :resource="isNew ? options : record" :not-found="{ icon: Megaphone, title: t('social_commerce.post.not_found') }">
        <template #before><AppButton variant="ghost" size="sm" :icon="ArrowLeft" :to="{ name: 'social-posts' }">{{ t('social_commerce.post.back') }}</AppButton></template>
        <template #actions>
            <StatusBadge v-if="post" :status="post.status" :tones="POST_TONES">{{ t(`social_commerce.status.${post.status}`) }}</StatusBadge>
            <a v-if="post?.link" :href="post.link" target="_blank" rel="noopener noreferrer" class="inline-flex h-9 items-center gap-2 rounded-[9px] border border-line-strong bg-surface px-3.5 text-[13.5px] font-medium hover:bg-subtle">
                <ExternalLink class="size-4" aria-hidden="true" />{{ t('social_commerce.post.on_facebook') }}
            </a>
        </template>

        <SkeletonRows v-if="!opts" :rows="6" />
        <ErrorState v-else-if="options.error.value" :error="options.error.value" @retry="options.reload()" />
        <div v-else class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
            <div class="grid content-start gap-4">
                <div v-if="post?.error" class="rounded-xl border border-bad/40 bg-bad-soft p-4 text-[13.5px]">
                    <p class="font-medium text-bad">{{ post.error.text }}</p>
                    <p v-if="post.error.detail" class="mt-1 text-muted">{{ post.error.detail }}</p>
                </div>
                <div v-if="post?.status === 'rejected'" class="rounded-xl border border-warn/40 bg-warn-soft p-4 text-[13.5px]">
                    <p class="font-medium">{{ t('social_commerce.post.rejected_by', { name: post.rejected_by ?? '—' }) }}</p>
                    <p class="mt-1">{{ post.reject_reason }}</p>
                </div>

                <section class="card grid gap-4 p-5">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <AppField :label="t('social_commerce.fields.page')" v-slot="{ id }">
                            <select :id="id" v-model="form.page_id" class="field-input" :disabled="!editable">
                                <option v-for="item in opts.pages" :key="item.id" :value="item.id">{{ item.name }}{{ item.usable ? '' : ` (${t('social_commerce.post.page_unusable')})` }}</option>
                            </select>
                        </AppField>
                        <AppField :label="t('social_commerce.fields.language')" v-slot="{ id }">
                            <select :id="id" v-model="form.locale" class="field-input" :disabled="!editable">
                                <option v-for="code in opts.locales" :key="code" :value="code">{{ t(`core.languages.${code}`) }}</option>
                            </select>
                        </AppField>
                    </div>
                    <AppField :label="t('social_commerce.fields.message')" :hint="t('social_commerce.post.message_hint')" v-slot="{ id }">
                        <textarea :id="id" v-model="form.message" class="field-input" rows="8" maxlength="5000" :readonly="!editable" />
                    </AppField>
                    <AppField :label="t('social_commerce.fields.when')" :hint="t('social_commerce.post.when_hint', { minutes: opts.min_schedule_minutes })" optional v-slot="{ id }">
                        <input :id="id" v-model="form.publish_at" type="datetime-local" class="field-input max-w-xs" :readonly="!editable" />
                    </AppField>
                </section>

                <section class="card grid gap-3 p-5">
                    <h2 class="text-[14px] font-semibold">{{ t('social_commerce.post.products') }}</h2>
                    <ul v-if="form.products.length" class="flex flex-wrap gap-2">
                        <li v-for="product in form.products" :key="product.id" class="flex items-center gap-1 rounded-full border border-line bg-surface-2 py-1 ps-3 pe-1 text-[13px]">
                            {{ product.name ?? product.id }}
                            <button v-if="editable" type="button" class="grid size-6 place-items-center rounded-full hover:bg-surface" :aria-label="t('social_commerce.post.remove_product', { name: product.name })" @click="dropItem(product.id)"><X class="size-3.5" /></button>
                        </li>
                    </ul>
                    <form v-if="editable" class="flex gap-2" @submit.prevent="findItems">
                        <div class="relative min-w-0 flex-1">
                            <Search class="pointer-events-none absolute inset-y-0 start-3 my-auto size-4 text-muted" aria-hidden="true" />
                            <input v-model="picking.search" class="field-input ps-9" :placeholder="t('social_commerce.post.find_product')" :aria-label="t('social_commerce.post.find_product')" />
                        </div>
                        <AppButton type="submit" :loading="picking.loading">{{ t('social_commerce.post.find') }}</AppButton>
                    </form>
                    <ul v-if="editable && picking.results.length" class="max-h-64 divide-y divide-line overflow-auto rounded-lg border border-line">
                        <li v-for="item in picking.results" :key="item.id">
                            <button type="button" class="flex w-full items-center gap-3 px-4 py-2 text-start hover:bg-surface-2 disabled:opacity-50" :disabled="form.item_ids.includes(item.id)" @click="addItem(item)">
                                <span class="min-w-0 flex-1">
                                    <span class="block text-[13.5px] font-medium">{{ item.name }}</span>
                                    <span class="block text-[12px] text-muted">{{ item.line }}</span>
                                </span>
                                <Check v-if="form.item_ids.includes(item.id)" class="size-4 text-ok" aria-hidden="true" />
                            </button>
                        </li>
                    </ul>
                </section>

                <section class="card grid gap-3 p-5">
                    <div class="flex items-center justify-between gap-2">
                        <h2 class="text-[14px] font-semibold">{{ t('social_commerce.post.photos', { count: photos.length, max: opts.max_images }) }}</h2>
                        <template v-if="editable && photos.length < opts.max_images">
                            <input ref="fileInput" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="addPhotos" />
                            <AppButton size="sm" :icon="ImagePlus" :loading="busy" @click="fileInput?.click()">{{ t('social_commerce.post.add_photos') }}</AppButton>
                        </template>
                    </div>
                    <p v-if="!photos.length" class="text-[13px] text-muted">{{ t('social_commerce.post.no_photos', { mb: opts.max_image_mb }) }}</p>
                    <ul v-else class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        <li v-for="(media, index) in photos" :key="media.id" class="relative overflow-hidden rounded-lg border border-line">
                            <img :src="media.url" :alt="t('social_commerce.post.photo_alt', { n: index + 1 })" class="aspect-square w-full object-cover" loading="lazy" />
                            <div v-if="editable" class="absolute inset-x-0 bottom-0 flex justify-between gap-1 bg-black/50 p-1">
                                <span class="flex gap-1">
                                    <button type="button" class="grid size-7 place-items-center rounded text-white hover:bg-white/20 disabled:opacity-40" :disabled="index === 0 || busy" :aria-label="t('social_commerce.post.move_up')" @click="movePhoto(index, -1)"><ArrowUp class="size-4" /></button>
                                    <button type="button" class="grid size-7 place-items-center rounded text-white hover:bg-white/20 disabled:opacity-40" :disabled="index === photos.length - 1 || busy" :aria-label="t('social_commerce.post.move_down')" @click="movePhoto(index, 1)"><ArrowDown class="size-4" /></button>
                                </span>
                                <button type="button" class="grid size-7 place-items-center rounded text-white hover:bg-white/20" :disabled="busy" :aria-label="t('social_commerce.post.remove_photo')" @click="removePhoto(media)"><Trash2 class="size-4" /></button>
                            </div>
                        </li>
                    </ul>
                </section>

                <div class="flex flex-wrap justify-end gap-2">
                    <AppButton v-if="post && editable && !post.fb_post_id" variant="ghost" :icon="Trash2" :disabled="busy" @click="remove">{{ t('social_commerce.post.delete') }}</AppButton>
                    <template v-if="editable && opts.can_compose">
                        <AppButton :loading="busy" :disabled="!dirty || !form.page_id" @click="save">{{ t('social_commerce.post.save') }}</AppButton>
                        <AppButton variant="primary" :icon="Send" :loading="busy" :disabled="!form.page_id || !page?.usable" @click="send">{{ sendLabel }}</AppButton>
                    </template>
                    <template v-if="post?.status === 'pending'">
                        <AppButton v-if="opts.can_compose" :icon="Undo2" :disabled="busy" @click="withdraw">{{ t('social_commerce.post.withdraw') }}</AppButton>
                        <template v-if="opts.can_approve">
                            <AppButton variant="danger" :disabled="busy" @click="Object.assign(turningDown, { open: true, reason: '' })">{{ t('social_commerce.post.reject') }}</AppButton>
                            <AppButton variant="primary" :icon="Check" :loading="busy" @click="approve">{{ t('social_commerce.post.approve') }}</AppButton>
                        </template>
                    </template>
                    <AppButton v-if="post?.status === 'scheduled' && opts.can_compose" :icon="Undo2" :disabled="busy" @click="withdraw">{{ t('social_commerce.post.unschedule') }}</AppButton>
                </div>
                <p v-if="post && (post.created_by || post.approved_by)" class="text-end text-[12.5px] text-muted">
                    <template v-if="post.created_by">{{ t('social_commerce.post.written_by', { name: post.created_by, when: formatDateTime(post.created_at) }) }}</template>
                    <template v-if="post.approved_by"> · {{ t('social_commerce.post.approved_by', { name: post.approved_by, when: formatDateTime(post.approved_at) }) }}</template>
                </p>
            </div>

            <!-- What goes out, as Facebook shows it. -->
            <aside class="lg:sticky lg:top-4 lg:self-start" :aria-label="t('social_commerce.post.preview')">
                <p class="mb-2 text-[12.5px] font-medium text-muted">{{ t('social_commerce.post.preview') }}</p>
                <article class="card overflow-hidden">
                    <header class="flex items-center gap-3 p-4">
                        <img v-if="page?.picture_url" :src="page.picture_url" alt="" class="size-10 rounded-full object-cover" referrerpolicy="no-referrer" />
                        <span v-else class="grid size-10 place-items-center rounded-full bg-surface-2"><Facebook class="size-5 text-muted" aria-hidden="true" /></span>
                        <span>
                            <span class="block text-[14px] font-semibold">{{ page?.name ?? '—' }}</span>
                            <span class="block text-[12px] text-muted">{{ form.publish_at ? formatDateTime(fromLocalInput(form.publish_at)) : t('social_commerce.post.just_now') }}</span>
                        </span>
                    </header>
                    <p class="whitespace-pre-line px-4 pb-3 text-[14px] leading-relaxed" :lang="form.locale">{{ preview || t('social_commerce.post.preview_empty') }}</p>
                    <div v-if="photos.length" class="grid gap-0.5" :class="photos.length === 1 ? 'grid-cols-1' : 'grid-cols-2'">
                        <img v-for="media in photos.slice(0, 4)" :key="media.id" :src="media.url" alt="" class="aspect-square w-full object-cover" />
                    </div>
                </article>
                <p v-if="form.products.length && post?.products?.length" class="mt-2 text-[12.5px] text-muted">
                    {{ t('social_commerce.post.prices_kept', { list: post.products.map((product) => `${product.name ?? ''} ${product.price_minor != null ? formatMoney({ amount: product.price_minor, currency: product.currency }) : ''}`).join(', ') }) }}
                </p>
            </aside>
        </div>

        <AppDialog :open="turningDown.open" :title="t('social_commerce.post.reject')" :description="t('social_commerce.post.reject_text')" tone="bad" @close="turningDown.open = false">
            <form class="grid gap-4" @submit.prevent="reject">
                <AppField :label="t('social_commerce.fields.reason')" v-slot="{ id }"><textarea :id="id" v-model="turningDown.reason" class="field-input" rows="3" minlength="3" maxlength="300" required /></AppField>
                <div class="flex justify-end gap-2"><AppButton @click="turningDown.open = false">{{ t('social_commerce.post.cancel') }}</AppButton><AppButton type="submit" variant="danger" :loading="busy">{{ t('social_commerce.post.reject') }}</AppButton></div>
            </form>
        </AppDialog>
    </PageFrame>
</template>
