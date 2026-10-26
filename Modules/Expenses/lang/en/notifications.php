<?php

return array (
  'templates' => 
  array (
    'expenses_submitted' => 
    array (
      'subject' => 'Expense claim {{ number }} of {{ employee }} to approve',
      'body' => '{{ employee }} at {{ organization }} sent expense claim {{ number }} ({{ amount }}).

Open it to see the receipts and decide.',
      'action' => 'Open claims to approve',
    ),
    'expenses_decided' => 
    array (
      'subject' => 'Your expense claim {{ number }}: {{ decision }}',
      'body' => 'Your expense claim {{ number }} ({{ amount }}) at {{ organization }} is {{ decision }}.

{{ note }}',
      'action' => 'Open my expenses',
    ),
    'expenses_paid' => 
    array (
      'subject' => 'Your expense claim {{ number }} is paid',
      'body' => 'Your expense claim {{ number }} ({{ amount }}) at {{ organization }} is paid back.',
      'action' => 'Open my expenses',
    ),
  ),
  'catalog' => 
  array (
    'expenses_submitted' => 
    array (
      'name' => 'Expense claim to approve',
      'description' => 'To the people who may decide the next step of a claim.',
    ),
    'expenses_decided' => 
    array (
      'name' => 'Expense claim decided',
      'description' => 'To the employee, when their claim is approved or rejected.',
    ),
    'expenses_paid' => 
    array (
      'name' => 'Expense claim paid',
      'description' => 'To the employee, when their claim is paid back.',
    ),
  ),
);
