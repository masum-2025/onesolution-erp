<?php

// Readable audit actions: "accounting.journal_posted" -> "journal_posted" (AuditQuery::label).

return [
    'books_set_up' => 'Books set up',
    'account_created' => 'Account added',
    'account_updated' => 'Account changed',
    'fiscal_year_created' => 'Fiscal year added',
    'period_closed' => 'Accounting period closed',
    'period_reopened' => 'Accounting period reopened',
    'posting_account_set' => 'Posting account chosen',
    'journal_drafted' => 'Journal entry written',
    'journal_updated' => 'Journal entry changed',
    'journal_deleted' => 'Draft journal entry removed',
    'journal_submitted' => 'Journal entry sent for approval',
    'journal_withdrawn' => 'Journal entry taken back from approval',
    'journal_rejected' => 'Journal entry rejected',
    'journal_posted' => 'Journal entry posted',
    'journal_reversed' => 'Journal entry reversed',
];
