import { reactive } from 'vue';

/**
 * What this address offers for sign-up, sign-in and recovery (from the page
 * shell; no secrets): whether individuals can sign up, email and/or phone,
 * phone countries, the bot check and the current terms version.
 */
export const signup = reactive({
    allowed: false,
    channels: ['mail'],
    phone: false,
    phone_countries: [],
    default_country: 'BD',
    bot: { driver: 'none', site_key: null },
    legal: { terms_version: null, privacy_version: null },
    otp_length: 6,
});

export function applySignupOptions(options) {
    if (options && typeof options === 'object') Object.assign(signup, options);
}

/** A strong-enough password: the same rule the server applies. */
export function passwordProblem(password) {
    return password.length < 10 || !/[A-Za-z]/.test(password) || !/\d/.test(password);
}

/** The body fields for an email or a phone address. */
export function addressBody(form) {
    return form.channel === 'sms'
        ? { channel: 'sms', phone: form.phone.trim(), country_code: form.country }
        : { channel: 'mail', email: form.email.trim() };
}
