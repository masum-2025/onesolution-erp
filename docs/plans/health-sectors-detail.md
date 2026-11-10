# বিস্তারিত plan: UI-G, PH-1, H-1…H-5 (health sectors)

মূল plan: `docs/plans/health-sectors.md`। এই ফাইল প্রতিটা ধাপের table, endpoint, rule,
permission, event, menu, পর্দা, test আর "কীভাবে করবেন?" লেখা। "go" ছাড়া code নয় (CLAUDE.md §2)।

সাধারণ নিয়ম (সব module-এ):
- সব table-এ `id` ULID, `organization_id` (`BelongsToOrganization`), `created_at/updated_at` UTC;
  টাকা `*_minor` (integer) + `currency`; পরিমাণ `quantity_milli` (Inventory-র মতো)।
- নাম/লেবেল translatable JSON (`{"bn": "...", "en": "..."}`)।
- টাকার record append-only; ভুল শোধরাতে উল্টো entry (void/refund)।
- অফলাইনে লেখা যায় এমন record-এ `op_id` + `base_version`।
- route: `auth:sanctum`, `org`, `module:{key}` (+ নির্ভর করা module), লেখায় throttle।
- প্রতিটা module-এ Pest: happy path, validation, permission denied, module off (403), rule hierarchy,
  tenant + partner isolation। নতুন module `make:erp-module` দিয়ে।

---

## UI-G — "ⓘ কীভাবে করবেন?" guide panel (shared, core)

**কে ব্যবহার করে:** সব ব্যবহারকারী, সব sector। Module নয়, core UI (`@/ui`)। কোনো table নেই।

**ফাইল**
```
resources/js/components/GuideButton.vue    header-এর inline-end কোণে বোতাম
resources/js/components/GuideDrawer.vue    side drawer (মোবাইলে bottom sheet)
resources/js/components/PageFrame.vue      নতুন prop: guide (translation key)
resources/js/lib/guideSeen.js              "আর দেখাবেন না" — localStorage, try/catch
resources/js/locales/{bn,en}/core.json     core.guide.* (বোতাম, শিরোনাম, "আর দেখাবেন না")
docs/frontend-ui.md                         guide-এর নিয়ম যোগ
```

**Key-এর আকার** (LANG-1 language editor-এ প্রতিটা আলাদা string, তাই array নয়):
```
<module>.guide.<page>.title
<module>.guide.<page>.steps.1 … steps.9
<module>.guide.<page>.tips.1 …          (ঐচ্ছিক)
<module>.guide.<page>.mistakes.1 …      (ঐচ্ছিক: সাধারণ ভুল)
<module>.guide.<page>.settings          (ঐচ্ছিক: setting কোথায়)
```
`PageFrame` prop: `guide="pharmacy.guide.sell"`, ঐচ্ছিক `guide-settings="/settings/rules?module=pharmacy"`,
`guide-video` (partner চাইলে link)। Key না থাকলে বোতাম দেখায় না (ভাঙা লেখা কখনো নয়)।

**আচরণ:** প্রথমবার পাতায় এলে বোতাম একবার pulse করে (drawer নিজে খোলে না — কাজে বাধা নয়);
"আর দেখাবেন না" চাপলে pulse বন্ধ। Esc বন্ধ করে, focus বোতামে ফেরে, `role="dialog"`,
`aria-expanded`, focus trap; CSS logical properties (RTL)। Print-এ লুকানো। Lazy: drawer
প্রথম ক্লিকে load হয়, first-load bundle বাড়ে না।

**Security:** শুধু translation লেখা, `v-html` নয় (text escape); video link শুধু `https://`।
**Test:** Vitest — key থাকলে বোতাম, না থাকলে নেই; খোলা/বন্ধ/Esc; storage throw করলেও চলে।
PHP `FrontendSourceTest` — health module-এর প্রতিটা `PageFrame`-এ `guide` আছে, আর bn+en দুটোতেই
`title` আর `steps.1` আছে (`GuideKeysTest`)। প্রথম ব্যবহার: POS-এর বিক্রির পাতা (নমুনা)।

**নির্দেশনা (POS বিক্রি, নমুনা)**
- bn: কীভাবে বিক্রি করবেন — ১. ক্যাশ খুলুন (দিনের শুরুর টাকা লিখুন)। ২. পণ্যের নাম লিখুন বা barcode
  scan করুন। ৩. পরিমাণ ঠিক করুন, দরকার হলে ছাড় দিন। ৪. টাকা নিন, রসিদ print করুন।
  টিপস: ছাড়ের সীমার বেশি দিলে supervisor-এর অনুমোদন লাগে।
- en: How to sell — 1. Open the till (enter the opening cash). 2. Type the product name or scan its
  barcode. 3. Set the quantity; add a discount if needed. 4. Take payment and print the receipt.
  Tip: a discount above the limit needs a supervisor.

---

## PH-1 — Pharmacy (`Modules/Pharmacy`, key `pharmacy`)

**ব্যবসা:** ওষুধের দোকান/চেইন; ভূমিকা pharmacist, cashier, store_keeper, মালিক। Sector `pharmacy`,
`hospital`। দেশভেদে আলাদা: MRP নিয়ম, VAT, নিয়ন্ত্রিত ওষুধের তালিকা, DGDA নম্বর (BD)।
**নির্ভর:** requires `inventory`, `pos`; `patients` চালু থাকলে প্রেসক্রিপশন/রোগী যুক্ত হয়।
বিক্রি হয় Pos-এর public `Sales` service দিয়ে — তাই shift, cash close, Accounting posting সব
Pos-এর যা আছে তাই; Pharmacy নিজে কোনো টাকার table রাখে না। মজুদ/ব্যাচ/মেয়াদ Inventory-র।

**Table**
| Table | Column |
|---|---|
| `ph_generics` | id, organization_id, name (json), drug_class (string 60, null), is_controlled (bool), controlled_schedule (string 20, null), is_active |
| `ph_manufacturers` | id, organization_id, name (json), country (char 2), is_active |
| `ph_medicines` | id, organization_id, item_id (inv_items, unique), generic_id, manufacturer_id, brand_name (json), strength (string 40, "500 mg"), form (string 20: tablet/capsule/syrup/injection/…), dgda_no (string 40, null), mrp_minor (per piece), currency, pieces_per_strip (int, null), strips_per_box (int, null), requires_prescription (bool), is_active, version |
| `ph_sale_links` | id, organization_id, pos_sale_id, patient_id (null), prescription_ref (string 60, null), prescriber_name (string 120, null), prescriber_bmdc (string 20, null), op_id (unique) |
| `ph_controlled_entries` | id, organization_id, medicine_id, batch_id, direction (in/out), quantity_milli, balance_after_milli, source_type, source_id, patient_name (string 120), prescriber_bmdc, recorded_by, recorded_at — **append-only** |
| `ph_supplier_returns` | id, organization_id, number, supplier (string 150), status (draft/submitted/approved/sent), inv_document_id (null), reason, created_by, approved_by |
| `ph_supplier_return_lines` | id, return_id, medicine_id, batch_id, quantity_milli, expires_on |

**Rule**
| Key | ধরন / default | স্তর |
|---|---|---|
| `pharmacy.block_above_mrp` | bool, true, country_specific, partner/country lock করতে পারে | platform→company |
| `pharmacy.block_expired_sale` | bool, true | platform→company |
| `pharmacy.near_expiry_days` | int, 30 (লাল দাগ) | →branch |
| `pharmacy.return_before_expiry_days` | int, 90 (supplier-এ ফেরতের তালিকা) | →branch |
| `pharmacy.max_discount_percent` | decimal, 5 | →branch |
| `pharmacy.discount_approval_above_percent` | decimal, 10, sensitive (maker-checker) | →company |
| `pharmacy.controlled_requires_prescription` | bool, true, country_specific | platform→company |
| `pharmacy.sell_loose_pieces` | bool, true (পাতা ভেঙে পিস) | →branch |
| `pharmacy.number_prefixes` | json, `{"return":"SR"}` | →company |

VAT: Inventory item-এর `tax_code_id` + Pos `pos.prices_include_tax` (নতুন rule নয়)।

**Permission:** `pharmacy.view`, `pharmacy.sell`, `pharmacy.manage` (ওষুধের তালিকা, কেনা),
`pharmacy.controlled` (খাতা দেখা/লেখা), `pharmacy.approve` (বড় ছাড়, supplier ফেরত)।

**Event:** `pharmacy.sale_made` (ids, টাকা মাত্র), `pharmacy.controlled_dispensed`,
`pharmacy.expiry_near` (attention panel)। শোনে: `outpatient.prescription_written` (H-3 পরে —
"প্রেসক্রিপশন থেকে বিক্রি" তালিকায় দেখায়)।

**Endpoint** (`/api/organizations/{org}/pharmacy`, `module:inventory,pos,pharmacy`)
```
GET  medicines?search=&generic=&form=        brand বা generic দিয়ে খোঁজা
GET  medicines/{id}  · POST medicines · PATCH medicines/{id}
GET  medicines/{id}/substitutes              একই generic+strength+form, মজুদসহ
GET  generics · POST generics · PATCH generics/{id}
GET  manufacturers · POST · PATCH
GET  counter/{register}/catalogue            অফলাইন cache-এর জন্য (MRP, pack, ব্যাচ, মেয়াদ)
POST sales                                   throttle:pos-sales; op_id; MRP/মেয়াদ/নিয়ন্ত্রিত যাচাই → Pos Sales
GET  stock/expiry?within=                    মেয়াদ শেষের পথে
GET  supplier-returns · POST · POST {id}/submit · POST {id}/approve
GET  controlled-register?medicine=&from=&to= · POST controlled-register/adjust (approve লাগে)
GET  reports?kind=sales|generic|expiry|controlled   throttle
POST medicines/import                        CSV (ধরন/আকার যাচাই, সর্বোচ্চ ৫,০০০ সারি), throttle
```
**কেনা (Purchase):** নতুন code নয় — Inventory-র receipt document, Pharmacy পাতায় ওষুধ-বান্ধব
ফর্ম (ব্যাচ নম্বর আর মেয়াদ বাধ্যতামূলক, MRP ছাপা দাম)।

**Menu 💊 ফার্মেসি:** বিক্রি (POS) `/pharmacy/sell` (sell) · ওষুধের তালিকা `/pharmacy/medicines` (view) ·
কেনা `/pharmacy/purchases` (manage) · মজুদ ও মেয়াদ `/pharmacy/stock` (view) · ফেরত
`/pharmacy/returns` (view) · নিয়ন্ত্রিত ওষুধের খাতা `/pharmacy/controlled` (controlled) · রিপোর্ট
`/pharmacy/reports` (approve)। Setting: Generic ও কোম্পানি।

**Offline:** `PharmacySaleSync` (Pos-এর SaleSync-এর মতো); সার্ভারে sync-এর সময় একই যাচাই;
নিয়ন্ত্রিত ওষুধ অফলাইনে বিক্রি নয় (তালিকায় তালা চিহ্ন)।
**Security:** দাম/মজুদ server থেকে, request থেকে নয়; MRP-র বেশি → 422 স্পষ্ট বার্তা; নিয়ন্ত্রিত
খাতা append-only + audit; supplier ফেরত maker-checker; import ফাইল public-এর বাইরে।
**Test:** উপরের সাধারণ তালিকা + MRP-র বেশি আটকায়, মেয়াদোত্তীর্ণ আটকায়, পাতা/বক্স → পিস হিসাব,
generic substitute, FEFO ব্যাচ বাছাই, নিয়ন্ত্রিত ওষুধে প্রেসক্রিপশন বাধ্যতামূলক, অফলাইন sync একবারই
(op_id), Pos বন্ধ → 403, patients বন্ধ থাকলে রোগী ছাড়াই বিক্রি চলে।

**পর্দা ও নির্দেশনা**

1. **বিক্রি (POS)**
   - bn: ১. ওষুধের নাম লিখুন — brand বা generic যেকোনো নামে খুঁজুন, অথবা barcode scan করুন।
     ২. পিস, পাতা বা বক্স বেছে পরিমাণ দিন। ৩. যে ব্যাচের মেয়াদ সবার আগে শেষ, সেটি নিজে থেকে
     বাছাই হয়; দরকারে বদলান। ৪. ওষুধ না থাকলে "বিকল্প" চাপুন — একই generic-এর অন্য brand দেখাবে।
     ৫. টাকা নিন (নগদ, bKash, কার্ড), রসিদ print করুন। টিপস: লাল দাগ = মেয়াদ শিগগির শেষ।
     MRP-র বেশি দাম নেওয়া যায় না। ভুল: নিয়ন্ত্রিত ওষুধে প্রেসক্রিপশনের তথ্য না দিলে বিক্রি হবে না।
     Setting: ছাড়ের সীমা Rules → ফার্মেসি।
   - en: 1. Type the medicine — search by brand or generic name, or scan the barcode. 2. Pick piece,
     strip or box and enter the quantity. 3. The batch that expires first is chosen for you; change it if
     needed. 4. Out of stock? Tap "Substitutes" to see other brands of the same generic. 5. Take payment
     (cash, bKash, card) and print the receipt. Tip: red = expires soon. You cannot charge above MRP.
     Mistake: a controlled medicine needs the prescription details. Settings: Rules → Pharmacy.
2. **ওষুধের তালিকা**
   - bn: ১. "নতুন ওষুধ" চাপুন। ২. brand নাম, generic, শক্তি (যেমন 500 mg) আর ধরন দিন।
     ৩. MRP (এক পিসের দাম) আর পাতায় কত পিস, বক্সে কত পাতা লিখুন। ৪. DGDA নম্বর থাকলে দিন।
     টিপস: অনেক ওষুধ একসাথে আনতে CSV import ব্যবহার করুন।
   - en: 1. Tap "New medicine". 2. Enter the brand, generic, strength (e.g. 500 mg) and form.
     3. Enter the MRP of one piece, pieces per strip and strips per box. 4. Add the DGDA number if you
     have it. Tip: use CSV import to add many medicines at once.
3. **কেনা**
   - bn: ১. সরবরাহকারী বেছে নিন। ২. প্রতিটা ওষুধের ব্যাচ নম্বর, মেয়াদ, পরিমাণ আর কেনা দাম দিন।
     ৩. "জমা দিন" — মজুদ বাড়বে। ভুল: মেয়াদ ছাড়া ব্যাচ রাখা যায় না।
   - en: 1. Choose the supplier. 2. For each medicine enter batch number, expiry, quantity and cost.
     3. Tap "Submit" — stock goes up. Mistake: a batch without an expiry date is not accepted.
4. **মজুদ ও মেয়াদ**
   - bn: ১. উপরে বেছে নিন: সব, কম মজুদ, মেয়াদ শেষের পথে, মেয়াদোত্তীর্ণ। ২. লাল সারির ওষুধ আগে
     বিক্রি করুন বা সরবরাহকারীকে ফেরত দিন। ৩. "ফেরতের তালিকায় যোগ" চাপলে ফেরত খসড়া তৈরি হয়।
   - en: 1. Pick at the top: all, low stock, expiring soon, expired. 2. Sell red rows first or return
     them to the supplier. 3. "Add to return" starts a supplier-return draft.
5. **ফেরত (সরবরাহকারীকে)**
   - bn: ১. খসড়া খুলুন বা নতুন করুন। ২. ওষুধ, ব্যাচ আর পরিমাণ দিন, কারণ লিখুন। ৩. "জমা দিন" —
     অনুমোদনের পর মজুদ কমবে। টিপস: ক্রেতার ফেরত POS-এর "বিক্রি" থেকে হয়।
   - en: 1. Open a draft or start a new one. 2. Add medicine, batch, quantity and a reason.
     3. Submit — stock goes down once approved. Tip: customer returns are made from POS sales.
6. **নিয়ন্ত্রিত ওষুধের খাতা**
   - bn: ১. ওষুধ আর তারিখ বেছে নিন। ২. প্রতিটা আসা-যাওয়া, রোগীর নাম আর ডাক্তারের BMDC নম্বর
     দেখুন। ৩. Print চাপুন — পরিদর্শনের জন্য খাতা তৈরি। টিপস: এখানে কিছু মোছা যায় না; ভুল হলে
     "সমন্বয়" করুন, অনুমোদন লাগবে।
   - en: 1. Choose the medicine and dates. 2. See every in/out with patient name and doctor's BMDC
     number. 3. Print it for inspection. Tip: nothing here can be deleted; fix mistakes with an
     approved adjustment.
7. **রিপোর্ট**
   - bn: ১. রিপোর্টের ধরন আর তারিখ বেছে নিন। ২. Export বা Print চাপুন।
   - en: 1. Choose the report and dates. 2. Export or print.
8. **Generic ও কোম্পানি (setting)**
   - bn: ১. Generic নাম যোগ করুন; নিয়ন্ত্রিত হলে চিহ্ন দিন। ২. প্রস্তুতকারক কোম্পানি যোগ করুন।
   - en: 1. Add generic names; mark controlled ones. 2. Add manufacturers.

---

## H-1 — Patients (`patients`) + Patient billing (`patient_billing`)

**ব্যবসা:** reception, billing_officer, doctor, nurse; sector `diagnostic`, `hospital` (pharmacy-তে
ঐচ্ছিক)। দেশভেদে: পরিচয়পত্র (NID/জন্মনিবন্ধন), মোবাইল নম্বরের ধরন, VAT।

### patients
| Table | Column |
|---|---|
| `pt_patients` | id, organization_id, branch_id, mrn (রোগী নম্বর, unique), name (string 150), name_bn (null), sex, date_of_birth (null), age_years (জন্মতারিখ না জানলে), mobile (string 20, index), id_type (nid/birth_reg/passport/none), id_number (encrypted), guardian_name, guardian_relation, blood_group, allergies (text, encrypted), address (string 300), identity_id (portal-এর জন্য, null), version |
| `pt_views` | id, organization_id, patient_id, user_id, purpose (string 30), viewed_at — **কে দেখেছে** (append-only) |
| `pt_timeline` | id, organization_id, patient_id, source_module, source_type, source_id, title (json), happened_at — অন্য module event দিয়ে লেখে |

Rule: `patients.mrn_prefix` (`P`), `patients.require_id_number` (false), `patients.duplicate_check`
(mobile+name), `patients.doctor_sees` (`own`/`all`, sensitive), `patients.portal_enabled` (true).
Permission: `patients.view`, `patients.register`, `patients.manage` (সম্পাদনা, merge), `patients.audit`.
Event: `patients.registered`, `patients.merged`। Contract `PatientDirectory` (find, exists, label) —
অন্য module শুধু এটা দিয়ে রোগী খোঁজে।
Endpoint: `GET patients?search=` (মোবাইল/নম্বর/নাম, throttle) · `POST patients` · `GET/PATCH
patients/{id}` (দেখলেই `pt_views`) · `GET patients/{id}/timeline` · `GET patients/{id}/card` (QR) ·
`POST patients/{id}/merge` (approve) · `GET patients/{id}/views` (audit) · portal: `GET
portal/me/records`।
Menu 🧑‍⚕️ রোগী: রোগী খুঁজুন / নতুন রোগী · রোগীর ইতিহাস · Portal।

### patient_billing
| Table | Column |
|---|---|
| `pb_services` | id, organization_id, code, name (json), category (consultation/test/bed/procedure/other), price_minor, currency, tax_code_id, source_module (null), is_active |
| `pb_panels` | id, organization_id, name, kind (corporate/panel), credit_limit_minor, discount_percent, is_active |
| `pb_bills` | id, organization_id, branch_id, number, patient_id, panel_id (null), status (open/final/void), subtotal/discount/tax/total/paid/due `_minor`, currency, op_id, version, created_by |
| `pb_bill_lines` | id, bill_id, service_id, source_module, source_type, source_id, quantity_milli, unit_price_minor, discount_minor, tax_minor, total_minor — append-only |
| `pb_payments` | id, organization_id, bill_id (null = advance), patient_id, kind (payment/advance/refund), method (cash/card/mobile/panel), amount_minor, reference, op_id, received_by, cash_day_id |
| `pb_discount_requests` | id, bill_id, percent/amount_minor, reason, status, requested_by, decided_by (maker-checker) |
| `pb_cash_days` | id, organization_id, branch_id, user_id, opened_at, closed_at, expected_minor, counted_minor, variance_minor, status |

Rule: `patient_billing.discount_approval_above_percent` (10, sensitive), `patient_billing.allow_due`
(true), `patient_billing.cash_variance_allowed` (money, null), `patient_billing.number_prefixes`
(`{"bill":"B","receipt":"MR"}`)। Permission: `patient_billing.view`, `.collect`, `.approve`, `.manage`
(দাম, panel), `.close_day`। Event: `patient_billing.bill_finalized`, `.payment_received`,
`.refund_made`, `.day_closed`। Contract `PatientCharges::add(patient, sourceModule, sourceType,
sourceId, serviceCode, qty)` — diagnostics/opd/ipd এটা ডাকে। Posting key: `pb.cash`, `pb.card`,
`pb.mobile`, `pb.receivable`, `pb.panel_receivable`, `pb.advance` (liability), `pb.income`,
`pb.discount`, `pb.tax_output`, `pb.cash_variance`।
Endpoint: `GET/POST services` · `GET/POST/PATCH panels` · `GET bills?patient=&status=` · `POST bills`
· `POST bills/{id}/lines` · `POST bills/{id}/finalize` · `POST payments` (op_id, throttle) · `POST
bills/{id}/refund` · `POST bills/{id}/discount-requests` · `POST discount-requests/{id}/decide` ·
`POST cash-days/open` · `POST cash-days/{id}/close` · `GET bills/{id}/receipt` (signed URL)।
Online payments চালু থাকলে bKash/Nagad link।
Menu 🧾 রোগীর বিল: বিল ও টাকা নেওয়া · সেবার দাম · ছাড় ও ফেরত অনুমোদন · কর্পোরেট ও প্যানেল · ক্যাশ বন্ধ।

**Test (H-1):** সাধারণ তালিকা + দেখলে `pt_views`-এ লেখা, doctor_sees=own হলে অন্যের রোগী 403,
portal রোগী শুধু নিজের বিল/রিপোর্ট, NID/allergy encrypted, duplicate সতর্কতা, বড় ছাড়ে
অনুমোদন, refund আলাদা entry, cash day variance, patients বন্ধ → billing-ও 403।

**নির্দেশনা**
1. **রোগী খুঁজুন / নতুন রোগী** — bn: ১. মোবাইল নম্বর বা রোগী নম্বর লিখুন। ২. পেলে নাম চাপুন;
   না পেলে "নতুন রোগী"। ৩. নাম, বয়স বা জন্মতারিখ, লিঙ্গ আর মোবাইল দিন — বাকি পরে দিলেও চলে।
   ৪. কার্ড print করুন (QR)। ভুল: একই মোবাইলে রোগী থাকলে সতর্কতা দেখায় — আগে মিলিয়ে নিন।
   en: 1. Enter the mobile or patient number. 2. Found? Tap the name; if not, "New patient". 3. Name,
   age or birth date, sex and mobile are enough; add the rest later. 4. Print the QR card. Mistake: a
   warning shows when the mobile already exists — check before adding.
2. **রোগীর ইতিহাস** — bn: ১. রোগী খুলুন। ২. সব ভিজিট, রিপোর্ট, প্রেসক্রিপশন আর বিল তারিখ অনুযায়ী
   দেখুন। টিপস: আপনি এই পাতা খুললে সেটা লেখা থাকে (গোপনীয়তার জন্য)। en: 1. Open the patient.
   2. See all visits, reports, prescriptions and bills by date. Tip: opening this page is recorded
   (for privacy).
3. **Portal** — bn: ১. রোগীর মোবাইলে আমন্ত্রণ পাঠান। ২. রোগী নিজের রিপোর্ট আর বিল দেখবেন, অন্য কিছু
   নয়। en: 1. Send an invite to the patient's mobile. 2. Patients see only their own reports and bills.
4. **বিল ও টাকা নেওয়া** — bn: ১. রোগী খুঁজুন। ২. সেবা যোগ করুন (অন্য বিভাগ থেকে আসা চার্জ নিজে
   থেকে আসে)। ৩. ছাড় দিলে কারণ লিখুন। ৪. টাকা নিন — পুরো, আংশিক বা অগ্রিম। ৫. রসিদ print করুন।
   ভুল: বিল মোছা যায় না; ভুল হলে ফেরত দিন। en: 1. Find the patient. 2. Add services (charges from
   other departments come in by themselves). 3. Give a reason for any discount. 4. Take full, part or
   advance payment. 5. Print the receipt. Mistake: bills cannot be deleted; use a refund.
5. **সেবার দাম** — bn: ১. "নতুন সেবা" চাপুন, নাম, ধরন আর দাম দিন। ২. দাম বদলালে নতুন বিলে
   লাগবে, পুরোনো বিল বদলায় না। en: 1. Tap "New service"; enter name, type and price. 2. A new price
   applies to new bills only.
6. **ছাড় ও ফেরত অনুমোদন** — bn: ১. অপেক্ষমাণ অনুরোধ খুলুন। ২. কারণ দেখে অনুমোদন বা বাতিল করুন।
   টিপস: নিজের অনুরোধ নিজে অনুমোদন করা যায় না। en: 1. Open a pending request. 2. Read the reason,
   then approve or reject. Tip: you cannot approve your own request.
7. **কর্পোরেট ও প্যানেল** — bn: ১. প্রতিষ্ঠানের নাম, বাকির সীমা আর ছাড় দিন। ২. বিলে প্যানেল বেছে
   নিলে বাকি প্রতিষ্ঠানের নামে যায়। en: 1. Enter the company, credit limit and discount. 2. Choosing
   a panel on a bill puts the due on that company.
8. **ক্যাশ বন্ধ** — bn: ১. দিনের শেষে হাতে থাকা টাকা গুনে লিখুন। ২. পার্থক্য সীমার মধ্যে হলে বন্ধ
   হয়, বেশি হলে supervisor দেখবেন। en: 1. At day end count and enter your cash. 2. Within the limit
   it closes; beyond, a supervisor reviews.

---

## H-2 — Diagnostics (`diagnostics`)

requires `patients`, `patient_billing`; outpatient/inpatient চালু থাকলে তাদের order আসে।
| Table | Column |
|---|---|
| `dx_tests` | id, organization_id, code, name (json), department (pathology/radiology/…), sample_type, service_id (pb_services), turnaround_hours (null = rule), is_panel, is_active |
| `dx_parameters` | id, test_id, name (json), unit, result_type (number/text/choice), choices (json), sort |
| `dx_ranges` | id, parameter_id, sex (any/m/f), age_from_days, age_to_days, low, high, text |
| `dx_referrers` | id, organization_id, name, bmdc_no, mobile, commission_percent (null = rule), is_active |
| `dx_orders` | id, organization_id, branch_id, number, patient_id, referrer_id, source_module, source_id, bill_id, status (ordered/collected/resulted/verified/delivered/cancelled), op_id, version |
| `dx_order_tests` | id, order_id, test_id, status, sample_id |
| `dx_samples` | id, organization_id, barcode (unique), order_id, patient_id, sample_type, collected_by, collected_at, rejected_reason |
| `dx_results` | id, order_test_id, parameter_id, value, flag (low/high/critical/null), entered_by, entered_at, version |
| `dx_verifications` | id, order_test_id, verified_by, signature_ref, verified_at, amended_from (null) — append-only |
| `dx_report_deliveries` | id, order_id, channel (print/pdf/sms/portal), sent_by, sent_at |
| `dx_commissions` | id, referrer_id, order_id, amount_minor, currency, status (due/paid) — append-only |

Rule: `diagnostics.referral_commission_percent` (0, sensitive), `diagnostics.report_turnaround_hours`
(24), `diagnostics.send_report_sms` (false), `diagnostics.report_link_hours` (72),
`diagnostics.require_verification` (true, lock করা যায়), `diagnostics.barcode_prefix`।
Permission: `diagnostics.view`, `.order`, `.collect`, `.result`, `.verify`, `.deliver`, `.manage`।
Event: `diagnostics.ordered`, `.sample_collected`, `.result_verified`, `.report_ready`,
`.commission_due`। শোনে `outpatient.tests_advised`, `inpatient.tests_ordered`।
Endpoint: tests/parameters/ranges CRUD · referrers CRUD · `POST orders` (→ PatientCharges) · `POST
orders/{id}/samples` (barcode) · `GET samples/{barcode}` · `PUT order-tests/{id}/results` · `POST
order-tests/{id}/verify` · `POST orders/{id}/deliver` · `GET orders/{id}/report.pdf` (signed, মেয়াদসহ)
· `GET referrers/{id}/commissions` · `GET worklist?stage=`।
Menu 🧪 ডায়াগনস্টিক: Test order · নমুনা সংগ্রহ · ফল লেখা · যাচাই ও সই · রিপোর্ট দেওয়া · Test-এর তালিকা ·
Referral ডাক্তার।
Test: সাধারণ + barcode না মিললে ফল লেখা যায় না, বয়স/লিঙ্গ অনুযায়ী flag, যাচাই ছাড়া রিপোর্ট নয়,
যাচাইয়ের পর বদল = নতুন সংশোধন, signed link মেয়াদ শেষে 403, portal রোগী শুধু নিজের, commission rule।

**নির্দেশনা**
1. **Test order** — bn: ১. রোগী খুঁজুন। ২. test বেছে নিন (নাম বা code)। ৩. referral ডাক্তার থাকলে
   দিন। ৪. "Order ও বিল" — বিল আর টাকা নেওয়ার পাতায় যায়। en: 1. Find the patient. 2. Pick tests by
   name or code. 3. Add the referring doctor if any. 4. "Order and bill" opens payment.
2. **নমুনা সংগ্রহ** — bn: ১. তালিকা থেকে রোগী বেছে নিন। ২. barcode label print করে টিউবে লাগান।
   ৩. "সংগ্রহ হয়েছে" চাপুন। ভুল: label লাগানোর আগে রোগীর নাম মিলিয়ে নিন। en: 1. Pick the patient.
   2. Print the barcode label and stick it on the tube. 3. Tap "Collected". Mistake: check the name
   before labelling.
3. **ফল লেখা** — bn: ১. টিউবের barcode scan করুন। ২. প্রতিটা ঘরে ফল লিখুন — সীমার বাইরে হলে লাল
   বা হলুদ দেখাবে। ৩. "জমা দিন" — যাচাইয়ে যাবে। en: 1. Scan the tube barcode. 2. Enter each value —
   out of range shows red or amber. 3. Submit for verification.
4. **যাচাই ও সই** — bn: ১. অপেক্ষমাণ রিপোর্ট খুলুন। ২. ফল দেখে "যাচাই ও সই" বা "ফেরত পাঠান"।
   টিপস: সইয়ের পর বদলালে নতুন সংশোধন হয়, পুরোনোটাও থাকে। en: 1. Open a pending report. 2. Check,
   then "Verify and sign" or "Send back". Tip: changes after signing make a new amendment.
5. **রিপোর্ট দেওয়া** — bn: ১. রোগী বা রিপোর্ট নম্বর খুঁজুন। ২. Print, PDF বা SMS link। টিপস:
   বাকি থাকলে সতর্কতা দেখায়। en: 1. Find the patient or report. 2. Print, PDF or SMS a link. Tip: a
   warning shows when the bill is due.
6. **Test-এর তালিকা** — bn: ১. test, দাম আর নমুনার ধরন দিন। ২. প্রতিটা ঘর আর বয়স/লিঙ্গভিত্তিক
   স্বাভাবিক সীমা দিন। en: 1. Enter the test, price and sample type. 2. Add each parameter and its
   normal ranges by age and sex.
7. **Referral ডাক্তার** — bn: ১. নাম, BMDC আর commission % দিন (খালি = সাধারণ নিয়ম)। ২. মাস শেষে
   পাওনা দেখে পরিশোধ লিখুন। en: 1. Enter name, BMDC and commission % (blank = default rule).
   2. At month end review dues and record payments.

---

## H-3 — Outpatient (`outpatient`)

requires `patients`, `patient_billing`; pharmacy/diagnostics চালু থাকলে প্রেসক্রিপশন সেখানে যায়।
| Table | Column |
|---|---|
| `opd_doctors` | id, organization_id, user_id (null), name, name_bn, bmdc_no, degrees, specialty (json), fee_service_id, follow_up_service_id, is_active |
| `opd_chambers` | id, organization_id, branch_id, name, room |
| `opd_schedules` | id, doctor_id, chamber_id, weekday, starts_at, ends_at, serial_limit (null = rule) |
| `opd_serials` | id, organization_id, doctor_id, schedule_id, date, serial_no, patient_id, kind (new/follow_up), status (booked/arrived/in/done/absent/cancelled), bill_id, op_id |
| `opd_consultations` | id, serial_id, patient_id, doctor_id, complaints, findings (json: BP, pulse, weight…), diagnosis, advice, follow_up_on, version |
| `opd_prescription_items` | id, consultation_id, medicine_text, medicine_id (null), dose, duration, instructions (bn/en) |
| `opd_advised_tests` | id, consultation_id, test_id (null), test_text |
| `opd_templates` | id, organization_id, doctor_id, name, body (json) |

Rule: `outpatient.daily_serial_limit` (40), `outpatient.follow_up_free_days` (7),
`outpatient.serial_sms` (false), `outpatient.prescription_language` (`bn`/`en`/`both`)।
ডাক্তার কার রোগী দেখবেন: `patients.doctor_sees`। Permission: `outpatient.view`, `.book`,
`.consult` (ডাক্তার), `.manage`। Event: `outpatient.serial_booked`, `.consultation_done`,
`.prescription_written`, `.tests_advised`।
Endpoint: doctors/chambers/schedules CRUD · `GET serials?date=&doctor=` · `POST serials` (limit,
op_id) · `POST serials/{id}/status` · `GET my-patients` · `POST serials/{id}/consultation` ·
`GET consultations/{id}/prescription.pdf` · templates CRUD · `GET queue/{chamber}` (display screen, শুধু
serial নম্বর, নাম নয়)।
Menu 🩺 বহির্বিভাগ: আজকের সিরিয়াল · সিরিয়াল দিন · আমার রোগী · ডাক্তার ও চেম্বার · প্রেসক্রিপশনের ছাঁচ।
Test: সাধারণ + serial limit, follow-up ফ্রি দিন, অন্য ডাক্তারের রোগী (own) 403, প্রেসক্রিপশন bn/en,
pharmacy বন্ধ থাকলে event কিছু করে না, queue screen-এ নাম নেই।

**নির্দেশনা**
1. **আজকের সিরিয়াল** — bn: ১. ডাক্তার বেছে নিন। ২. রোগী এলে "এসেছেন", ডাকলে "ভিতরে"। ৩. না এলে
   "অনুপস্থিত"। en: 1. Pick the doctor. 2. Mark "Arrived", then "In" when called. 3. "Absent" if
   they don't come.
2. **সিরিয়াল দিন** — bn: ১. রোগী খুঁজুন বা নতুন করুন। ২. ডাক্তার আর তারিখ বেছে নিন — খালি সিরিয়াল
   দেখাবে। ৩. ফি নিন, সিরিয়াল slip দিন। টিপস: ফলো-আপ নির্দিষ্ট দিনের মধ্যে হলে ফি লাগে না।
   en: 1. Find or add the patient. 2. Pick doctor and date — free serials show. 3. Take the fee and
   give the slip. Tip: follow-ups within the set days are free.
3. **আমার রোগী (ডাক্তার)** — bn: ১. পরের রোগী খুলুন। ২. সমস্যা, পরীক্ষার ফল আর রোগ নির্ণয় লিখুন।
   ৩. ওষুধ লিখুন — নাম লিখলেই তালিকা আসে; ছাঁচ থেকেও নিতে পারেন। ৪. দরকারি test দিন।
   ৫. "শেষ ও print"। en: 1. Open the next patient. 2. Write complaints, findings and diagnosis.
   3. Add medicines — suggestions appear as you type; or use a template. 4. Advise tests.
   5. "Finish and print".
4. **ডাক্তার ও চেম্বার** — bn: ১. ডাক্তারের নাম, BMDC নম্বর আর ফি দিন। ২. চেম্বার আর সপ্তাহের সময়সূচি
   দিন। en: 1. Enter the doctor's name, BMDC number and fee. 2. Add the chamber and weekly schedule.
5. **প্রেসক্রিপশনের ছাঁচ** — bn: ১. প্রায়ই লেখা ওষুধ আর উপদেশ একটা নামে রাখুন। ২. রোগী দেখার সময়
   এক ক্লিকে বসান। en: 1. Save frequent medicines and advice under a name. 2. Insert them in one tap
   while consulting.

---

## H-4 — Inpatient (`inpatient`)

requires `patients`, `patient_billing`; diagnostics/pharmacy চালু থাকলে order আর ওষুধ চার্জ আসে।
| Table | Column |
|---|---|
| `ipd_wards` | id, organization_id, branch_id, name (json), kind (general/cabin/icu/…) |
| `ipd_beds` | id, ward_id, code, bed_service_id, status (free/occupied/cleaning/blocked), version |
| `ipd_admissions` | id, organization_id, number, patient_id, doctor_id (opd_doctors নয় — user/contract), bed_id, admitted_at, reason, guardian_mobile, panel_id, status (admitted/discharged/cancelled), discharged_at, op_id, version |
| `ipd_bed_moves` | id, admission_id, from_bed_id, to_bed_id, moved_at, by — append-only |
| `ipd_rounds` | id, admission_id, doctor_user_id, note, orders (json), at |
| `ipd_nursing_notes` | id, admission_id, vitals (json), note, medicine_given (json), by, at |
| `ipd_bed_charges` | id, admission_id, date, bed_id, amount_minor, bill_line_ref — append-only, (admission, date) unique |
| `ipd_discharges` | id, admission_id, summary (diagnosis, treatment, advice, follow_up), signed_by, signed_at |

Rule: `inpatient.bed_day_starts` (`midnight`/`admission_time`, country/partner বদলাতে পারে),
`inpatient.min_advance` (money, null), `inpatient.discharge_requires_clearance` (true)।
Permission: `inpatient.view`, `.admit`, `.nurse`, `.round` (ডাক্তার), `.discharge`, `.manage`।
Event: `inpatient.admitted`, `.bed_moved`, `.tests_ordered`, `.discharged`। Scheduled job
(module বন্ধ থাকলে চলে না): প্রতিদিন bed charge → PatientCharges (একবারই, unique)।
Endpoint: wards/beds CRUD · `GET beds/board` · `POST admissions` · `POST admissions/{id}/move` · `POST
.../rounds` · `POST .../nursing-notes` · `POST .../discharge` (clearance check) · `GET
.../discharge.pdf` · `GET admissions?status=`।
Menu 🛏️ অন্তর্বিভাগ: ভর্তি · বেডের অবস্থা · ভর্তি রোগী · ছাড়পত্র · ওয়ার্ড ও বেড।
Test: সাধারণ + একই বেডে দুজন নয় (version), bed charge দিনে একবার আর rule অনুযায়ী দিন শুরু, বাকি থাকলে
ছাড়পত্র আটকায়, own-doctor নিয়ম, module বন্ধ → job চলে না।

**নির্দেশনা**
1. **ভর্তি** — bn: ১. রোগী খুঁজুন। ২. ডাক্তার, ওয়ার্ড আর খালি বেড বেছে নিন। ৩. অগ্রিম টাকা নিন।
   ৪. "ভর্তি করুন"। en: 1. Find the patient. 2. Pick doctor, ward and a free bed. 3. Take the
   advance. 4. "Admit".
2. **বেডের অবস্থা** — bn: সবুজ = খালি, লাল = রোগী আছে, হলুদ = পরিষ্কার হচ্ছে। বেড চাপলে রোগী
   খুলবে। en: Green = free, red = occupied, amber = cleaning. Tap a bed to open the patient.
3. **ভর্তি রোগী** — bn: ১. রোগী খুলুন। ২. ডাক্তার round-এর নোট, নার্স vitals আর দেওয়া ওষুধ লিখুন।
   ৩. test দরকার হলে "Test order"। ৪. বেড বদলাতে "বেড বদল"। en: 1. Open the patient. 2. Doctors add
   round notes; nurses add vitals and medicines given. 3. "Order tests" if needed. 4. "Move bed" to
   transfer.
4. **ছাড়পত্র** — bn: ১. রোগ নির্ণয়, চিকিৎসা আর উপদেশ লিখুন। ২. চূড়ান্ত বিল দেখুন, বাকি নিন।
   ৩. সই করে print করুন। ভুল: বাকি থাকলে ছাড়পত্র হবে না (setting-এ বদলানো যায়)। en: 1. Write
   diagnosis, treatment and advice. 2. Review the final bill; collect the due. 3. Sign and print.
   Mistake: discharge is blocked while money is due (a setting).
5. **ওয়ার্ড ও বেড** — bn: ১. ওয়ার্ড বা কেবিন যোগ করুন। ২. বেড নম্বর আর প্রতিদিনের ভাড়া দিন।
   en: 1. Add wards or cabins. 2. Add beds with their daily charge.

---

## H-5 — Sector packages, role templates, chart templates, demo

- `sector-packages.php`: `pharmacy`, `diagnostic`, `hospital` (মূল plan §1), শুরুর rule মান।
- Role template: doctor, nurse, receptionist, billing_officer, lab_technician, pathologist,
  ward_incharge, pharmacist — প্রতিটায় শুধু দরকারি permission আর menu।
- Accounting chart template `pharmacy`, `diagnostic`, `hospital` + posting map।
- Demo data (আলাদা demo seeder, dev DB নয়): ১০০ ওষুধ, ২০ test, ৩ ডাক্তার, ২ ওয়ার্ড।
- Test: প্রতিটা sector নিলে ঠিক module চালু হয়, role-এ ঠিক menu, chart template posting।
- Sector চেনার পাতায় নির্দেশনা — bn: "আপনার প্রতিষ্ঠানের ধরন বেছে নিন; দরকারি module নিজে থেকে চালু
  হবে, পরে যেকোনোটা চালু/বন্ধ করতে পারবেন।" en: "Choose your business type; the needed modules turn
  on by themselves, and you can turn any on or off later."

---

## নির্ভরতা ও বন্ধ থাকলে কী হয়

```
inventory ─┐
pos ───────┴─> pharmacy ······> (patients: ঐচ্ছিক)
patients ──> patient_billing ──┬─> diagnostics ···> (outpatient, inpatient: ঐচ্ছিক)
                               ├─> outpatient  ···> (pharmacy, diagnostics: ঐচ্ছিক)
                               └─> inpatient   ···> (diagnostics, pharmacy: ঐচ্ছিক)
```
| বন্ধ | ফল |
|---|---|
| inventory বা pos | pharmacy চালু করা যায় না; চালু থাকলে route 403, menu লুকায় |
| pharmacy | প্রেসক্রিপশন তৈরি হয়, শুধু "ফার্মেসিতে পাঠান" দেখায় না |
| patients | billing, diagnostics, outpatient, inpatient চালু করা যায় না; pharmacy রোগী ছাড়া বিক্রি করে |
| patient_billing | diagnostics/opd/ipd চালু করা যায় না |
| diagnostics | ডাক্তার test লিখতে পারেন (লেখা হিসেবে), order তৈরি হয় না |
| outpatient / inpatient | তাদের order আসে না; diagnostics নিজে order নেয় |
| accounting | বিল/বিক্রি চলে, posting হয় না (পরে চালু করলে map থেকে) |
| online_payments | শুধু নগদ/কার্ড/ম্যানুয়াল bKash reference |
| client_portal | রোগী portal নেই; SMS link (rule চালু থাকলে) চলে |

বন্ধ করলে কোনো data মোছে না; job আর menu থামে।

## খোলা প্রশ্ন (উত্তর না দিলে default)
1. PH-1-এ বিক্রি Pharmacy-র নিজের পাতা থেকে, Pos-এর public `Sales` service দিয়ে (Pos-এ শুধু একটা
   ছোট hook: বাইরের module-এর line যাচাই)। → default: হ্যাঁ।
2. UI-G-তে নমুনা guide শুধু POS বিক্রির পাতায়; বাকি পুরোনো পাতায় পরে। → default: হ্যাঁ।
3. নিয়ন্ত্রিত ওষুধের তালিকা আমরা seed করব না; মালিক/partner নিজে চিহ্ন দেবেন। → default: হ্যাঁ।
