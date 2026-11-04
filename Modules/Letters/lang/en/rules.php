<?php

return [
    'salary_fields_enabled' => [
        'label' => 'Salary in letters',
        'description' => 'Whether letters may show pay (basic, gross, the breakdown). Off: no design can use salary fields and salary letters cannot be issued.',
    ],
    'number_format' => [
        'label' => 'Letter number format',
        'description' => 'Pattern for letter numbers, e.g. {CODE}/{YYYY}/{SEQ:4} gives APT/2027/0001. {CODE} is the design\'s code, {YY} the short year; numbers restart each year per code.',
    ],
    'employees_see_own' => [
        'label' => 'Employees see their letters',
        'description' => 'Employees see the letters shared with them in the app and the portal, and are told by e-mail when one is issued.',
    ],
];
