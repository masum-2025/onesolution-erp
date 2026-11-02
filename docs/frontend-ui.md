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
- On paper: the organization's name and the print date head the page; mark filters, pagers and
  action buttons `print:hidden`.

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
permission `{module}.export` (Payroll) offers export only to people holding it. Only the rows
the page itself may show are exported (same API, same permissions).

## Fields

- `MoneyInput`: `v-model` in minor units (null while empty or not an amount); reads Bangla or
  Latin digits; shows the currency.
- `DateInput`: `v-model` "YYYY-MM-DD"; `not-future` / `not-past` use the reader's own today.
- `StatusBadge`: `:status` + `:tones` map, the word in the slot (never colour alone).

## Rules the tests keep

`tests/Feature/Frontend/FrontendSourceTest.php`: no module keeps its own Bangla digit table or
number reader, and no module builds XLSX, DOCX or CSV files itself.

## Moving a module over (step B)

1. Swap the page's outer `<div>` + `PageHeader` for `PageFrame`; add `export` on lists.
2. Replace `<input inputmode="decimal">` money fields with `MoneyInput`, date inputs with
   `DateInput`.
3. Import from `@/ui`. Build, run Vitest, and look at the page (screen and print) before and after.
