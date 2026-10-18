<?php

// ZKTeco messages and errors.
return [
    'errors' => [
        'not_company_unit' => 'Open a company or one of its branches: a group has no staff of its own.',
        'device_not_found' => 'That device does not exist here.',
        'user_not_found' => 'That user is not on this device.',
        'version_conflict' => 'Someone changed this device a moment ago. It is fresh now; make your change again.',
        'unreachable' => 'The device could not be read: :reason',
        'has_logs' => 'Punches came from this device, so it cannot be removed. Switch it off instead.',
    ],
    'reasons' => [
        'no_answer' => 'it did not answer. Check that it is on, its network cable, and its IP address and port (Menu > Comm. > Ethernet).',
        'wrong_comm_key' => 'its comm key is different. Type the one set on the device (Menu > Comm. > Comm Key).',
        'refused' => 'it refused the connection. Try again in a minute, or restart the device.',
        'read_failed' => 'it stopped answering while sending its logs. Try again.',
    ],
    'validation' => [
        'host' => 'Give the device\'s IP address on the office network, such as 192.168.1.201.',
        'host_taken' => 'A device with this IP address and port is already added.',
        'unit' => 'Choose this branch or one below it.',
        'employee' => 'Choose an employee of this company.',
    ],
];
