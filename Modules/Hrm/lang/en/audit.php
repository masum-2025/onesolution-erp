<?php

// Readable audit actions: "hrm.employee_hired" -> "employee_hired" (AuditQuery::label).

return [
    'employee_hired' => 'Employee hired',
    'employee_updated' => 'Employee details changed',
    'employee_confirmed' => 'Employee confirmed after probation',
    'employee_transferred' => 'Employee transferred',
    'employee_promoted' => 'Employee promoted',
    'employee_notice_given' => 'Notice given',
    'employee_exited' => 'Employee left',
    'employee_rehired' => 'Employee rehired',
    'sensitive_viewed' => 'Full national or tax id viewed',
    'position_created' => 'Position created',
    'position_updated' => 'Position changed',
    'document_added' => 'Employee document added',
    'document_downloaded' => 'Employee document opened',
    'document_removed' => 'Employee document removed',
    'custom_field_created' => 'Extra employee field added',
    'custom_field_updated' => 'Extra employee field changed',
    'import_checked' => 'Employee file checked',
    'import_started' => 'Employee import started',
    'import_finished' => 'Employee import finished',
    'import_cancelled' => 'Employee import cancelled',
];
