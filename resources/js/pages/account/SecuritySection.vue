<script setup>
import { computed, ref } from 'vue';
import { Copy, Download, Fingerprint, KeyRound, LifeBuoy, Pencil, ShieldCheck, Smartphone, Trash2 } from 'lucide-vue-next';
import AppBadge from '@/components/AppBadge.vue';
import AppButton from '@/components/AppButton.vue';
import AppDialog from '@/components/AppDialog.vue';
import AppField from '@/components/AppField.vue';
import SkeletonRows from '@/components/SkeletonRows.vue';
import ErrorState from '@/components/ErrorState.vue';
import { api } from '@/lib/http';
import { useResource } from '@/lib/useResource';
import { confirmAction } from '@/lib/dialogs';
import { formatDate, formatRelative } from '@/lib/format';
import { loadMe } from '@/lib/session';
import { addPasskey, passkeyErrorMessage, passkeysSupported } from '@/lib/passkeys';
import { toast } from '@/lib/toast';
import { t } from '@/lib/i18n';

/**
 * My account → Security (Phase 8-1): two-step sign-in with an
 * authenticator app and/or passkeys, and recovery codes for when the phone
 * is lost. Shows whether the organization being worked in requires it,
 * where that comes from, and until when to set it up.
 */
const security = useResource(() => api('/api/me/security').then((response) => response.data));
const data = computed(() => security.data.value);
const canPasskey = computed(() => passkeysSupported() && data.value?.passkeys_available);

const levelName = (level) => t(`security.levels.${level}`);

async function refresh() {
    await security.reload();
    // The app shows reminders from /api/me: keep it in step.
    loadMe().catch(() => null);
}

// ── Authenticator app ──
const setup = ref(null); // { secret, qr }
const setupCode = ref('');
const setupError = ref(null);
const busy = ref(false);

async function startApp() {
    busy.value = true;
    try {
        setup.value = (await api('/api/me/security/totp', { method: 'POST' })).data;
        setupCode.value = '';
        setupError.value = null;
    } catch (error) {
        toast.error(error.message);
    } finally {
        busy.value = false;
    }
}

async function confirmApp() {
    if (!setupCode.value.trim()) return;
    busy.value = true;
    setupError.value = null;
    try {
        const response = await api('/api/me/security/totp/confirm', { method: 'POST', body: { code: setupCode.value.replace(/\s+/g, '') } });
        setup.value = null;
        toast.success(response.message);
        if (response.data.recovery_codes) codes.value = response.data.recovery_codes;
        await refresh();
    } catch (error) {
        setupError.value = error.message;
    } finally {
        busy.value = false;
    }
}

async function removeApp() {
    const confirmed = await confirmAction({ title: t('security.app.remove_title'), message: t('security.app.remove_text'), confirmLabel: t('security.app.remove'), danger: true });
    if (!confirmed) return;
    try {
        toast.success((await api('/api/me/security/totp', { method: 'DELETE' })).message);
        await refresh();
    } catch (error) {
        toast.error(error.message);
    }
}

async function copySecret() {
    try {
        await navigator.clipboard.writeText(setup.value.secret);
        toast.success(t('security.copied'));
    } catch {
        toast.error(t('security.copy_failed'));
    }
}

// ── Passkeys ──
const naming = ref(null); // { id?, name }
const nameError = ref(null);

function defaultDeviceName() {
    const platform = navigator.userAgentData?.platform || (/(iPhone|iPad)/.test(navigator.userAgent) ? 'iPhone' : /Android/.test(navigator.userAgent) ? 'Android' : /Mac/.test(navigator.platform) ? 'Mac' : /Win/.test(navigator.platform) ? 'Windows' : '');
    return platform ? t('security.passkeys.default_name', { device: platform }) : t('security.passkeys.default_name_plain');
}

function askName(passkey = null) {
    naming.value = { id: passkey?.id ?? null, name: passkey?.name ?? defaultDeviceName() };
    nameError.value = null;
}

async function saveName() {
    const name = naming.value.name.trim();
    if (!name) {
        nameError.value = t('security.passkeys.name_required');
        return;
    }
    busy.value = true;
    try {
        if (naming.value.id) {
            toast.success((await api(`/api/me/security/passkeys/${naming.value.id}`, { method: 'PATCH', body: { name } })).message);
        } else {
            const response = await addPasskey(name);
            toast.success(response.message);
            if (response.data.recovery_codes) codes.value = response.data.recovery_codes;
        }
        naming.value = null;
        await refresh();
    } catch (error) {
        nameError.value = error.status ? error.message : passkeyErrorMessage(error);
    } finally {
        busy.value = false;
    }
}

async function removePasskey(passkey) {
    const confirmed = await confirmAction({ title: t('security.passkeys.remove_title', { name: passkey.name }), message: t('security.passkeys.remove_text'), confirmLabel: t('security.passkeys.remove'), danger: true });
    if (!confirmed) return;
    try {
        toast.success((await api(`/api/me/security/passkeys/${passkey.id}`, { method: 'DELETE' })).message);
        await refresh();
    } catch (error) {
        toast.error(error.message);
    }
}

// ── Recovery codes ──
const codes = ref(null);

async function newCodes() {
    const confirmed = await confirmAction({ title: t('security.codes.new_title'), message: t('security.codes.new_text'), confirmLabel: t('security.codes.new') });
    if (!confirmed) return;
    try {
        const response = await api('/api/me/security/recovery-codes', { method: 'POST' });
        codes.value = response.data.recovery_codes;
        await security.reload();
    } catch (error) {
        toast.error(error.message);
    }
}

async function copyCodes() {
    try {
        await navigator.clipboard.writeText(codes.value.join('\n'));
        toast.success(t('security.copied'));
    } catch {
        toast.error(t('security.copy_failed'));
    }
}

function downloadCodes() {
    const blob = new Blob([`${t('security.codes.file_title')}\n\n${codes.value.join('\n')}\n`], { type: 'text/plain' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'recovery-codes.txt';
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <section id="security" class="card">
        <header class="flex flex-wrap items-start gap-3 border-b border-line px-5 py-4">
            <div class="min-w-0 flex-1">
                <h2 class="flex items-center gap-2 text-[15px] font-semibold text-fg"><ShieldCheck class="size-4 text-muted" aria-hidden="true" />{{ t('security.title') }}</h2>
                <p class="mt-1 text-[13px] leading-relaxed text-muted">{{ t('security.text') }}</p>
            </div>
            <AppBadge v-if="data" :tone="data.enabled ? 'ok' : 'neutral'">{{ data.enabled ? t('security.on') : t('security.off') }}</AppBadge>
        </header>

        <SkeletonRows v-if="security.loading.value && !data" :rows="3" class="p-5" />
        <ErrorState v-else-if="security.error.value" :error="security.error.value" compact @retry="security.reload()" />

        <template v-else-if="data">
            <!-- Required here? Where it comes from, until when. -->
            <div
                v-if="data.requirement?.required"
                class="mx-5 mt-4 rounded-xl border px-3.5 py-3 text-[13px]"
                :class="data.enabled ? 'border-line bg-subtle/60 text-fg-2' : 'border-warn/25 bg-warn-soft text-fg-2'"
                role="status"
            >
                <p class="font-medium text-fg">
                    {{ data.requirement.source ? t('security.required_by', { name: data.requirement.source.name ?? levelName(data.requirement.source.level), level: levelName(data.requirement.source.level) }) : t('security.required') }}
                    <span v-if="data.requirement.locked_by" class="font-normal text-muted"> · {{ t('security.locked_by', { name: data.requirement.locked_by.name ?? levelName(data.requirement.locked_by.level) }) }}</span>
                </p>
                <p v-if="!data.enabled && data.requirement.due_at" class="mt-0.5">{{ t('security.due', { date: formatDate(data.requirement.due_at) }) }}</p>
            </div>

            <ul class="divide-y divide-line">
                <!-- Authenticator app -->
                <li class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-muted"><Smartphone class="size-4" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] font-medium text-fg">{{ t('security.app.title') }}</p>
                        <p class="text-[12.5px] text-muted">{{ data.totp ? t('security.app.on') : t('security.app.text') }}</p>
                    </div>
                    <AppButton v-if="data.totp" size="sm" variant="danger-soft" @click="removeApp">{{ t('security.app.remove') }}</AppButton>
                    <AppButton v-else size="sm" :icon="KeyRound" :loading="busy && !setup" @click="startApp">{{ t('security.app.set_up') }}</AppButton>
                </li>

                <!-- Passkeys -->
                <li class="px-5 py-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-muted"><Fingerprint class="size-4" aria-hidden="true" /></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-[13.5px] font-medium text-fg">{{ t('security.passkeys.title') }}</p>
                            <p class="text-[12.5px] text-muted">{{ canPasskey ? t('security.passkeys.text') : t('security.passkeys.unavailable') }}</p>
                        </div>
                        <AppButton v-if="canPasskey" size="sm" :icon="Fingerprint" @click="askName()">{{ t('security.passkeys.add') }}</AppButton>
                    </div>
                    <ul v-if="data.passkeys.length" class="mt-3 space-y-2 ps-12">
                        <li v-for="passkey in data.passkeys" :key="passkey.id" class="flex flex-wrap items-center gap-2 rounded-xl border border-line px-3 py-2.5">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2 text-[13px] font-medium text-fg">
                                    {{ passkey.name }}
                                    <AppBadge v-if="passkey.synced" tone="neutral">{{ t('security.passkeys.synced') }}</AppBadge>
                                    <AppBadge v-if="!passkey.here" tone="warn">{{ t('security.passkeys.other_address', { address: passkey.address }) }}</AppBadge>
                                </p>
                                <p class="text-[12px] text-muted">
                                    {{ passkey.last_used_at ? t('security.passkeys.used', { when: formatRelative(passkey.last_used_at) }) : t('security.passkeys.never_used') }}
                                    · {{ t('security.passkeys.added', { date: formatDate(passkey.created_at) }) }}
                                </p>
                            </div>
                            <AppButton size="icon" variant="ghost" :icon="Pencil" :aria-label="t('security.passkeys.rename')" @click="askName(passkey)" />
                            <AppButton size="icon" variant="ghost" :icon="Trash2" :aria-label="t('security.passkeys.remove')" @click="removePasskey(passkey)" />
                        </li>
                    </ul>
                </li>

                <!-- Recovery codes -->
                <li v-if="data.enabled" class="flex flex-wrap items-center gap-3 px-5 py-4">
                    <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-subtle text-muted"><LifeBuoy class="size-4" aria-hidden="true" /></span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[13.5px] font-medium text-fg">{{ t('security.codes.title') }}</p>
                        <p class="text-[12.5px]" :class="data.recovery_codes_left <= 3 ? 'text-warn' : 'text-muted'">{{ t('security.codes.left', { count: data.recovery_codes_left }) }}</p>
                    </div>
                    <AppButton size="sm" @click="newCodes">{{ t('security.codes.new') }}</AppButton>
                </li>
            </ul>
        </template>

        <!-- Setting up the app: scan, then the first code -->
        <AppDialog :open="setup !== null" :title="t('security.app.setup_title')" :description="t('security.app.setup_text')" :icon="KeyRound" size="sm" @close="setup = null">
            <form v-if="setup" id="totp-setup" class="space-y-4" novalidate @submit.prevent="confirmApp">
                <img :src="setup.qr" :alt="t('security.app.qr_alt')" class="mx-auto size-48 rounded-xl bg-white p-2 ring-1 ring-line" />
                <div class="rounded-xl border border-line bg-subtle/60 p-3">
                    <p class="text-[12px] text-muted">{{ t('security.app.manual') }}</p>
                    <div class="mt-1 flex items-center gap-2">
                        <code class="min-w-0 flex-1 break-all font-mono text-[13px] text-fg" dir="ltr">{{ setup.secret }}</code>
                        <AppButton size="icon" variant="ghost" :icon="Copy" :aria-label="t('security.copy')" @click="copySecret" />
                    </div>
                </div>
                <AppField :label="t('security.app.first_code')" :error="setupError">
                    <template #default="{ id, invalid, describedby }">
                        <input
                            :id="id"
                            v-model="setupCode"
                            data-autofocus
                            class="field-input h-11 font-mono tracking-[0.2em]"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            maxlength="8"
                            :aria-invalid="invalid || undefined"
                            :aria-describedby="describedby"
                        />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="setup = null">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="totp-setup" variant="primary" :loading="busy" :disabled="!setupCode.trim()">{{ t('security.app.turn_on') }}</AppButton>
            </template>
        </AppDialog>

        <!-- Naming a new passkey, or renaming one -->
        <AppDialog :open="naming !== null" :title="naming?.id ? t('security.passkeys.rename') : t('security.passkeys.add')" :icon="Fingerprint" size="sm" @close="naming = null">
            <form v-if="naming" id="passkey-name" class="space-y-3" novalidate @submit.prevent="saveName">
                <p v-if="!naming.id" class="text-[13px] text-muted">{{ t('security.passkeys.add_text') }}</p>
                <AppField :label="t('security.passkeys.name')" :hint="t('security.passkeys.name_hint')" :error="nameError">
                    <template #default="{ id, invalid, describedby }">
                        <input :id="id" v-model="naming.name" data-autofocus maxlength="80" class="field-input" :aria-invalid="invalid || undefined" :aria-describedby="describedby" />
                    </template>
                </AppField>
            </form>
            <template #footer>
                <AppButton @click="naming = null">{{ t('core.actions.cancel') }}</AppButton>
                <AppButton type="submit" form="passkey-name" variant="primary" :loading="busy">{{ naming?.id ? t('core.actions.save') : t('security.passkeys.continue') }}</AppButton>
            </template>
        </AppDialog>

        <!-- New recovery codes: shown once -->
        <AppDialog :open="codes !== null" :title="t('security.codes.show_title')" :description="t('security.codes.show_text')" :icon="LifeBuoy" tone="warn" size="sm" @close="codes = null">
            <ol v-if="codes" class="grid grid-cols-2 gap-2 rounded-xl border border-line bg-subtle/60 p-3 font-mono text-[13.5px] text-fg" dir="ltr">
                <li v-for="code in codes" :key="code" class="text-center">{{ code }}</li>
            </ol>
            <div class="mt-3 flex flex-wrap gap-2">
                <AppButton size="sm" :icon="Copy" @click="copyCodes">{{ t('security.copy') }}</AppButton>
                <AppButton size="sm" :icon="Download" @click="downloadCodes">{{ t('security.codes.download') }}</AppButton>
            </div>
            <template #footer>
                <AppButton variant="primary" @click="codes = null">{{ t('security.codes.saved') }}</AppButton>
            </template>
        </AppDialog>
    </section>
</template>
