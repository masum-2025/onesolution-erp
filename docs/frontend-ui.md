# Shared frontend UI

Module screens are built from one shared set of pieces. Change a piece once and every module
follows; a module never draws its own header, sidebar, footer, money field or export.

## Where things live

| What | Where | Modules |
| --- | --- | --- |
| App frame: header, sidebar, footer, banners | `resources/js/layouts/` (AppShell, AppHeader, SidebarNav, AppFooter) | wraps every page already; never touched |
| Page pieces, form fields, dialogs | `resources/js/components/` | import from `@/ui` |
| Helpers: typed numbers, today, op ids | `resources/js/lib/` (numbers.js, dates.js, billing.js) | import from `@/ui` (or the lib) |
| Export and print | `resources/js/lib/pageExport.js` + `ExportMenu.vue` | through `PageFrame`'s `export` prop |

```js
import { PageFrame, AppButton, AppField, MoneyInput, DateInput, StatusBadge, amountToMinor } from '@/ui';
```

## PageFrame: a page in one line

```vue
<PageFrame :title="t('x.title')" :description="t('x.text')" width="full"
           :resource="list" :empty="{ when: !rows.length, icon: Inbox, title: t('x.empty'), text: t('x.empty_text') }"
           :export="{ module: 'x', page: 'x.list', columns, rows }">
    <template #actions><AppButton …>New</AppButton></template>
    …the page's own content…
</PageFrame>
```

- `width`: `md` (a form or one record), `lg` (default), `xl`, `full`.
- `resource`: a `useResource()` result; PageFrame shows loading, error (with retry) and empty.
  Pages whose states sit inside a card (with filters above) leave it out and keep their own.
- `export`: `{ module, page, columns, rows }`, or `fetchAll(maxRows)` instead of `rows` for a
  list the API serves page by page (`fetchPages` in `lib/pageExport.js` reads every page).
  `columns`: `{ key, label, type?: 'text' | 'number' | 'money' | 'date', currency?, value?: (row) => … }`.
- On paper: the organization's name and the print date head the page, and the header's buttons
  stay off it; mark filters and pagers `print:hidden`. A page that lays out its own paper (label
  sheets, a shipment's papers) adds `own-paper`: no header, name or date on it.

A record's page (its title comes with its data):

```vue
<PageFrame :title="employee ? employee.full_name : ''" :resource="record" :not-found="{ icon: UserX, title: t('hrm.profile.not_found') }">
    <template #before><AppButton … :icon="ArrowLeft">{{ t('hrm.profile.back') }}</AppButton></template>
    <template #actions>…</template>
    <template v-if="employee">…</template>
</PageFrame>
```

- The header shows once the title is there; `#before` holds the back link above it.
- `not-found`: shown for a 404 instead of the error. Pages that load in their own way (several
  calls, a form) leave out `resource` and keep their states; the title waits for the record.

## Export and print

| Format | How | Notes |
| --- | --- | --- |
| PDF | the browser's print, "Save as PDF" | the only way Bangla letters join correctly |
| Excel (XLSX) | `write-excel-file` (loaded on click) | money and numbers as numbers, dates as dates |
| CSV | built in `lib/pageExport.js` | byte-order mark for Bangla in Excel; formulas made plain text |
| Word (DOCX) | `docx` (loaded on click) | title, date, the table |
| Print | `window.print()` | app frame hidden; organization and date shown |

The server decides what a page may offer (`GET /api/page-exports?module=`) and records every
export before the file is built (`POST /api/page-exports`, audited as `page_exported`, 30 a
minute per person). Rules: `ui.export_formats`, `ui.print_enabled`, `ui.export_modules_off`,
`ui.export_max_rows` (platform, partner, plan, group, company). A module that declares the
permission `{module}.export` (Payroll, HRM) offers export only to people holding it. Only the rows
the page itself may show are exported (same API, same permissions; masked ids stay masked).

Not exported on purpose: record and form pages, portal and "my" pages, pages with their own
paper (receipts, slips, marksheets, report cards, letters, labels), the teacher feedback results
(answers under the round's minimum stay hidden), and lists that already have their own server
export (CRM contacts, the student attendance register).

## Fields

- `MoneyInput`: `v-model` in minor units (null while empty or not an amount); reads Bangla or
  Latin digits; shows the currency. `v-model:invalid` is true while something typed is not an
  amount, so an optional amount is never saved as "none" by mistake.
  Fields with their own stricter reader keep it: Accounting (signed amounts, allocations), fee
  collection (never zero or minus), the POS till, and lines of documents and journals.
- `DateInput`: `v-model` "YYYY-MM-DD"; `not-future` / `not-past` use the reader's own today.
- `StatusBadge`: `:status` + `:tones` map, the word in the slot (never colour alone).

## Rules the tests keep

`tests/Feature/Frontend/FrontendSourceTest.php`: no module keeps its own Bangla digit table or
number reader, no module builds XLSX, DOCX or CSV files itself, and every module page is drawn
in `PageFrame` (none names `PageHeader`). `tests/js/sharedUi.test.js` covers the frame and the
money field.

## A new module page

1. Wrap it in `PageFrame`; add `export` on a list (labels under the module's `export.*` keys).
2. Money fields `MoneyInput`, date fields `DateInput`; import from `@/ui`.
3. Build, run Vitest, and look at the page (screen and print).
