# Incident playbook

What to do when something may have gone wrong with security: a stolen login,
a leaked key, data seen by someone who should not, a bad deploy that exposed
data, a lost or failed backup. Keep calm, work in order, write everything down.

## Roles

| Role | Who | Does |
|------|-----|------|
| Incident lead | the on-call engineer who got the alert | decides, keeps the timeline, calls the others |
| Operator | a second engineer | runs the commands, checks the logs |
| Communications | product owner / management | talks to affected clients and partners, and to authorities when required |
| Legal / privacy | adviser | decides whether regulators or people must be told, and by when |

One person can hold several roles in a small team; the lead is never also the
only operator for long.

## Where to look

- `php artisan security:alerts` — open alerts (from `storage/logs/security.log` events)
- `storage/logs/security.log` — failed sign-ins, refused requests (403/429),
  cross-tenant attempts, sensitive changes (JSON, ids only)
- The audit log — per organization in the app (Audit log screen), or the whole
  table / the shipped copy (`storage/logs/audit.log`, `AUDIT_SHIP_DRIVER=log`)
- `php artisan health:report` — queue, failed jobs, sync errors, backups, drill, scheduler

## Steps

1. **Acknowledge the alert and start a timeline** (time in UTC, who, what).
   `php artisan security:alerts --ack=<id> --by="<name>" --note="investigating"`
2. **Contain.** Cut the way in first, investigate after:
   - one person: `php artisan security:revoke --user=<email> --reason="..."`
   - an organization: `php artisan security:revoke --organization=<id> --reason="..."`
   - a partner (console tokens and API keys): `php artisan security:revoke --partner=<id or slug> --reason="..."`
   - an address: block it at the firewall / load balancer
   - a module that is being abused: switch it off for that organization (Modules
     screen or API); data is kept
   - platform-wide emergency: `php artisan down --secret=<token>` (maintenance mode)

   Revocation deletes API tokens, ends browser sessions, revokes partner API
   keys and offline devices (they wipe on next contact). People sign in again
   with their second step. Every run is in the audit log (`security.access_revoked`).
3. **Assess.** From the audit and security logs: who, which organizations,
   which data, since when. Keep copies of the log lines (they are not personal
   data beyond ids and addresses).
4. **Eradicate.** Fix the cause (patch, rotate the leaked secret — `APP_KEY` via
   `APP_PREVIOUS_KEYS`, backup keys via `BACKUP_KEY_V<n>`, gateway credentials
   via the merchant account screen, partner API keys via the console).
5. **Recover.** Bring access back; if data was changed or lost, restore
   (docs/ops/backups.md, "Restoring for real") into a separate database first.
6. **Notify** affected tenants (template below) and, where the law requires, the
   authorities, within the legal deadline (Legal decides; in the EU this is 72
   hours to the regulator).
7. **Review** within a week: what happened, why, what changes; add a regression
   test for the cause.

## Tenant notification template

Send from the partner's brand (the client's provider) or ours for direct
clients. Fill every bracket; never guess — say what is known and when you will
know more.

### English

> Subject: Security notice about your [product] account
>
> On [date, time and time zone] we found [what happened, in plain words].
> It affected [what data / which parts] of [organization] between [start] and [end].
> [It did not affect … / We are still checking whether …].
>
> What we did: [revoked access / fixed the cause / restored data] on [date].
> What you should do: [e.g. sign in again and check your members and settings;
> change your password; review the audit log from [date]].
>
> We are sorry. If you have questions, contact [support email / phone].
> We will update you by [date].

### বাংলা

> বিষয়: আপনার [product] অ্যাকাউন্ট সম্পর্কে নিরাপত্তা নোটিশ
>
> [তারিখ, সময় ও টাইম জোন]-এ আমরা দেখতে পাই যে [সহজ ভাষায় কী ঘটেছে]।
> এতে [start] থেকে [end] পর্যন্ত [organization]-এর [কোন ডেটা / কোন অংশ] প্রভাবিত হয়েছে।
> [… প্রভাবিত হয়নি / … হয়েছে কিনা আমরা এখনো দেখছি]।
>
> আমরা যা করেছি: [তারিখ]-এ [অ্যাক্সেস বাতিল / কারণ ঠিক / ডেটা ফিরিয়ে আনা] করেছি।
> আপনার যা করা উচিত: [যেমন আবার সাইন-ইন করে সদস্য ও সেটিং দেখে নিন;
> পাসওয়ার্ড বদলান; [তারিখ] থেকে অডিট লগ দেখুন]।
>
> আমরা দুঃখিত। কোনো প্রশ্ন থাকলে [support email / phone]-এ যোগাযোগ করুন।
> [তারিখ]-এর মধ্যে আমরা আবার জানাব।

## Drills

- Backup restore: monthly, automatic (`backup:restore-drill`); a failure raises
  a high alert.
- This playbook: walk through it with the team twice a year on a staging copy
  (revoke a test organization, restore a backup, send the template to yourself).
