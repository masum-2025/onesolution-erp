<?php

// Audit log labels for "projects.*" actions (AuditQuery::label).

return [
    'template_added' => 'Project template added',
    'template_changed' => 'Project template changed',
    'project_opened' => 'Project opened',
    'project_changed' => 'Project changed',
    'project_start' => 'Project started',
    'project_hold' => 'Project put on hold',
    'project_resume' => 'Project resumed',
    'project_finish' => 'Project finished',
    'project_cancel' => 'Project cancelled',
    'project_reopen' => 'Project reopened',
    'task_added' => 'Task added',
    'task_changed' => 'Task changed',
    'task_moved' => 'Task status changed',
    'task_assigned' => 'Task people changed',
    'tasks_ordered' => 'Tasks reordered',
    'quote_linked' => 'Quote made for the project',
    'invoice_linked' => 'Invoice made for the project',
    'client_to_crm' => 'Project client added to CRM',
    'request_received' => 'Renewal request received from a connected client',
    'request_cancelled' => 'Renewal request cancelled by the client',
    'request_accepted' => 'Renewal request accepted: project opened',
    'request_declined' => 'Renewal request declined',
    'result_sent' => 'Audit result sent to the client',
    'quote_sent' => 'Quotation sent to the connected client',
    'invoice_sent' => 'Invoice sent to the connected client',
    'quote_accepted' => 'Quotation accepted by the client',
    'quote_declined' => 'Quotation declined by the client',
];
