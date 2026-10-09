<?php

return [
    'errors' => [
        'forbidden' => 'You cannot change languages or wording here. Ask an administrator.',
        'own_wording_off' => 'Own wording is not available here: the Multi-language module is off, or your provider keeps one wording for all clients.',
        'wrong_level' => 'Wording is set for a group or a company. Open the company to change it.',
        'unknown_language' => 'That language does not exist.',
        'language_not_offered' => 'That language is not offered yet. Choose a published language.',
        'bad_code' => 'Use a language code such as "hi", "ar" or "pt-BR".',
        'language_exists' => 'This language is already there.',
        'file_language' => 'This language comes with the app. Its name and status cannot be changed here, but its wording can.',
        'below_minimum' => 'Only :percent% is translated. At least :needed% is needed before people can choose it.',
        'only_draft_removable' => 'Only a draft language can be removed. Switch this one off instead; its texts are kept.',
        'unknown_key' => 'The text ":key" does not exist in the app.',
        'markup' => 'Texts cannot contain HTML tags. Remove anything between < and >.',
        'unknown_placeholders' => 'These placeholders are not in the original text: :names. Use only the ones the original has.',
    ],
    'messages' => [
        'saved' => 'Wording saved.',
        'reset' => 'Back to the inherited wording.',
        'imported' => ':count texts imported.',
        'language_added' => 'Language added as a draft. Translate it, then publish it.',
        'language_saved' => 'Language saved.',
        'language_removed' => 'Language removed.',
    ],
];
