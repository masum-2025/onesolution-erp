<?php

return array (
  'errors' => 
  array (
    'not_company_unit' => 'Expenses belong to a company. Open a company or one of its branches.',
    'not_linked' => 'Your login is not linked to an employee. Ask HR to link it.',
    'employee_not_found' => 'This employee was not found here.',
    'category_not_found' => 'This kind of expense was not found. Choose one from the list.',
    'category_closed' => 'This kind of expense is not in use any more. Choose another one.',
    'claim_not_found' => 'This claim was not found.',
    'receipt_not_found' => 'This receipt was not found.',
    'not_draft' => 'Only a draft can be changed. This claim was already sent.',
    'empty_claim' => 'Add at least one expense with an amount before sending.',
    'receipt_needed' => 'Line :line needs a receipt (its amount is above the limit). Add a picture or PDF of it.',
    'cannot_decide' => 'You cannot decide this claim now (your own, already decided by you, or not your step).',
    'not_payable' => 'This claim cannot be changed in this way now. Reload it to see where it stands.',
    'payroll_off' => 'Payroll is not on for this company. Pay in cash or by bank.',
    'version_conflict' => 'Someone changed this in the meantime. Reload and try again.',
  ),
  'validation' => 
  array (
    'code_taken' => 'This code is already used by another kind of expense.',
    'line_not_found' => 'This line is not on the claim.',
    'receipt_size' => 'The file is too big. At most :max KB.',
    'receipt_kind' => 'Only pictures (JPEG, PNG, WebP) and PDF files can be receipts.',
    'future' => 'An expense cannot be in the future.',
    'too_old' => 'Expenses older than :days days cannot be claimed.',
    'amount' => 'Write the amount spent.',
    'distance' => 'Write the kilometres driven.',
    'no_mileage_rate' => 'No rate per kilometre is set. Ask the finance office.',
    'period_past' => 'Choose this month or a later one.',
  ),
  'status' => 
  array (
    'draft' => 'Draft',
    'submitted' => 'Waiting for approval',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
    'paid' => 'Paid',
    'withdrawn' => 'Taken back',
  ),
  'narration' => 
  array (
    'claim' => 'Expense claim :number',
    'payment' => 'Expense claim :number paid back',
  ),
  'posting_keys' => 
  array (
    'travel' => 'Expenses: travel',
    'meals' => 'Expenses: meals',
    'office' => 'Expenses: office supplies',
    'communication' => 'Expenses: phone and internet',
    'other' => 'Expenses: other',
    'claims_payable' => 'Expense claims owed to employees',
    'cash_account' => 'Expense claims paid in cash from',
    'bank_account' => 'Expense claims paid by bank from',
  ),
  'categories_added' => '{0} No kinds were missing.|{1} One kind added.|[2,*] :count kinds added.',
);
