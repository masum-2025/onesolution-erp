# Plan: health sectors (pharmacy, diagnostic, hospital) and the "How to" guide panel

Agreed with the owner on 2026-11-10. This is the plan only; each phase still gets its own
detailed plan (tables, endpoints, rules, screens, tests) and waits for "go" (CLAUDE.md §2).
Owner works in Bangla: answer in Bangla.

## Starting point (2026-11-10)

- `main` at `eb72737`, pushed. Done: OO-1, SC-1…SC-7, PERF-1, FE-1, FE-2, website texts fix.
- Sectors live in `database/seeders/data/sector-packages.php` (modules, starting rules, role
  templates per sector). Each module's manifest names its `sectors`.
- Existing modules to build on: `Inventory` (batches, expiry, `inventory.expiry_alert_days`),
  `Pos` (`pos.view/sell/supervise/manage`), `Accounting` (chart templates), `Crm`, `ClientPortal`,
  `OnlinePayments` (bKash/Nagad through the merchant account), `Hrm`/`Payroll`/`Attendance`.
- Frontend: every page uses `PageFrame` from `@/ui` (see `docs/frontend-ui.md`); module screens load
  only where the module is on and the role gives the menu page (FE-1).

## 1. Sectors (new keys in sector-packages.php)

| Sector | For | Modules in the package |
|---|---|---|
| `pharmacy` | medicine shops, pharmacy chains | pharmacy, inventory, pos, accounting, crm |
| `diagnostic` | diagnostic centres, labs | patients, patient_billing, diagnostics, accounting, hrm |
| `hospital` | hospitals, clinics (target: small–mid, 10–100 beds) | all of the above + outpatient, inpatient, payroll, attendance |

## 2. Modules (each independent, own folder, on/off per level)

| Module key | Does | Requires | Uses when on |
|---|---|---|---|
| `pharmacy` | medicine master (brand, generic, strength, form, manufacturer, DGDA no., MRP); never above MRP (rule); sell by piece/strip/box; generic substitutes; near-expiry alerts and returns to supplier; controlled-drug register; sale against a prescription | inventory, pos | patients |
| `patients` | registry and patient ID (QR card), NID/birth registration, guardian, blood group, allergies; one timeline of visits, reports, prescriptions, bills; patient portal | — | client_portal |
| `patient_billing` | service price list; patient bills, advance deposits, discounts, dues, refunds; corporate/panel credit; cashier day close | patients | accounting, online_payments |
| `diagnostics` | test catalog with reference ranges (age/sex); orders; sample collection with barcode; result entry with out-of-range flags; pathologist verification and signature; report print/PDF/SMS/portal; referral doctor commission | patients, patient_billing | outpatient, inpatient |
| `outpatient` | doctors' chambers and schedules, serial/token, consultation fee, prescriptions (bn/en), follow-up | patients, patient_billing | pharmacy, diagnostics |
| `inpatient` | admission, wards/beds/cabins and their state, daily bed charge, doctor rounds, nursing notes, discharge summary, final bill | patients, patient_billing | diagnostics, pharmacy |

Modules talk only through events, contracts or a module's public service (prescription → pharmacy
sale; OPD/IPD order → diagnostics test; diagnostics/IPD charges → patient billing). A link to a
module that is off simply does nothing.

## 3. Rules (never hardcoded; partner/country may differ)

Referral commission % (by test or doctor); discount limit above which maker-checker approval is
needed; selling above MRP blocked; VAT on medicines; when the bed day starts (midnight or 24 h from
admission); daily serial limit per doctor; report turnaround time; send reports by SMS; doctors see
only their own patients or all.

Bangladesh: bn/en everywhere including prescriptions; BMDC number for doctors; bKash/Nagad through
Online payments; SMS reports; accounting chart templates `hospital`, `diagnostic`, `pharmacy`.

## 4. Role templates

doctor, nurse, receptionist, billing_officer, lab_technician, pathologist, ward_incharge,
pharmacist (cashier and store_keeper exist).

## 5. Security (in addition to CLAUDE.md §4)

- Health data is the most sensitive: audit who **viewed** a patient record, not only changes.
- Rule: doctors see only their own patients, or all.
- Portal patients see only their own reports and bills (policy tests).
- Report links are signed and expire.
- Sample ↔ patient checked by barcode; a result can never land on another patient.
- Money records append-only; refunds are their own entries. Tenant and partner isolation tests in
  every module.

## 6. Usability

- Reception does everything on one screen: find patient (mobile/ID) → serial or test → payment →
  receipt.
- Pharmacy POS: search by brand or generic, or scan; near-expiry in red; works offline.
- Mobile-first, slow networks fine.

## 7. Menus (Bangla labels; dashboard first and settings last are added by core)

A person sees only the pages their role gives (FE-1).

**💊 Pharmacy (ফার্মেসি)**: বিক্রি (POS) · ওষুধের তালিকা · কেনা · মজুদ ও মেয়াদ · ফেরত ·
নিয়ন্ত্রিত ওষুধের খাতা · রিপোর্ট

**🧑‍⚕️ Patients (রোগী)**: রোগী খুঁজুন / নতুন রোগী · রোগীর ইতিহাস · Portal

**🧾 Patient billing (রোগীর বিল)**: বিল ও টাকা নেওয়া · সেবার দাম · ছাড় ও ফেরত অনুমোদন ·
কর্পোরেট ও প্যানেল · ক্যাশ বন্ধ

**🧪 Diagnostics (ডায়াগনস্টিক)**: Test order · নমুনা সংগ্রহ · ফল লেখা · যাচাই ও সই ·
রিপোর্ট দেওয়া · Test-এর তালিকা · Referral ডাক্তার

**🩺 Outpatient (বহির্বিভাগ)**: আজকের সিরিয়াল · সিরিয়াল দিন · আমার রোগী · ডাক্তার ও চেম্বার ·
প্রেসক্রিপশনের ছাঁচ

**🛏️ Inpatient (অন্তর্বিভাগ)**: ভর্তি · বেডের অবস্থা · ভর্তি রোগী · ছাড়পত্র · ওয়ার্ড ও বেড

## 8. UI-G: "How to" guide panel (for every module, built first)

- A small **ⓘ কীভাবে করবেন?** button at the inline-end corner of each page header (`PageFrame`).
- Opens a side drawer (bottom sheet on mobile): numbered steps, tips and common mistakes, where this
  page's settings live (link to its rules), optional video link.
- First visit: opens once (or the button pulses); "আর দেখাবেন না" hides it. Remembered in the
  browser only (a convenience; wrap storage in try/catch).
- `PageFrame` gets a `guide` prop with a translation key, e.g. `pharmacy.guide.sell`; texts in bn
  and en files, editable by partners/companies in the language editor (LANG-1), no code change.
- Keyboard and screen-reader accessible.

Sample (pharmacy sale page):
> **কীভাবে বিক্রি করবেন**
> ১. ওষুধের নাম লিখুন। brand বা generic নাম, যেকোনোটা দিয়ে খুঁজতে পারেন, অথবা barcode scan করুন।
> ২. কত বিক্রি করবেন তা পিস, পাতা বা বক্স হিসেবে বেছে নিন।
> ৩. সবচেয়ে আগে মেয়াদ শেষ হবে এমন ব্যাচ নিজে থেকেই বাছাই হয়। দরকার হলে বদলাতে পারেন।
> ৪. টাকা নিন: নগদ, bKash বা কার্ড। তারপর রসিদ print করুন।
> **টিপস:** লাল চিহ্ন মানে মেয়াদ ৩০ দিনের মধ্যে শেষ হবে। MRP-র বেশি দাম দেওয়া যায় না।
> **Setting:** ছাড়ের সীমা আর VAT পাবেন Rules → ফার্মেসিতে।

## 9. Build order

1. **UI-G** guide panel (shared).
2. **PH-1** pharmacy (independent, on inventory + pos), with a guide on every page.
3. **H-1** patients + patient_billing.
4. **H-2** diagnostics.
5. **H-3** outpatient.
6. **H-4** inpatient.
7. **H-5** the three sector packages, role templates, chart templates, demo data.

## 10. Open questions (defaults used if the owner does not answer)

1. Start with? → Pharmacy (after UI-G).
2. Hospital size? → small–mid clinic, 10–100 beds.
3. Lab machine interface now? → no, later.
4. Health insurance claims? → not now; corporate/panel credit only.

## 11. How to work (owner's standing preferences)

- Plan first, wait for "go"; commit, merge and push only on the owner's word.
- Before a commit run the module's tests plus the shared checks (FrontendSourceTest,
  Modules/RegistryTest, Security/ReleaseReadinessTest), not the full suite.
- After a merge with new tables/rules/permissions/posting keys, on the dev DB: `php artisan migrate
  --force`, `rules:sync`, `access:sync`, `accounting:map-postings`.
- Never wipe or reseed `onesolution_erp`; never `artisan --env=testing`.
- New modules: `make:erp-module` (adds the add-on price line); Windows is case-insensitive, never
  create a file differing only by case.
- Local app: `https://onesolution-erp.test` or `http://localhost:8080` (Apache, Redis must run).
