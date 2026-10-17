<?php

/*
| A module for make:erp-module (php artisan make:erp-module database/module-definitions/fleet.php).
| Every label in English and Bangla. Field types: text, long_text, translatable, integer, quantity,
| money, date, boolean, enum (options), belongs_to (entity), employee (needs hrm).
*/

return [
    'key' => 'fleet',
    'name' => ['en' => 'Fleet', 'bn' => 'গাড়িবহর'],
    'description' => ['en' => 'The company\'s vehicles and their trips: who drove, how far, and what the fuel cost.', 'bn' => 'কোম্পানির গাড়ি আর তাদের ট্রিপ: কে চালালেন, কত দূর, জ্বালানিতে কত খরচ।'],
    'requires' => ['hrm'],
    'icon' => 'package',
    'entities' => [
        'vehicle' => [
            'label' => ['en' => 'Vehicle', 'bn' => 'গাড়ি'],
            'plural' => ['en' => 'Vehicles', 'bn' => 'গাড়ি'],
            'title' => 'registration_no',
            'search' => ['registration_no', 'model'],
            'filters' => ['kind', 'in_use'],
            'columns' => ['registration_no', 'model', 'kind', 'capacity', 'in_use'],
            'fields' => [
                'registration_no' => ['type' => 'text', 'max' => 30, 'required' => true, 'unique' => true, 'label' => ['en' => 'Registration no.', 'bn' => 'রেজিস্ট্রেশন নম্বর']],
                'model' => ['type' => 'text', 'max' => 80, 'label' => ['en' => 'Make and model', 'bn' => 'ব্র্যান্ড ও মডেল']],
                'kind' => ['type' => 'enum', 'required' => true, 'label' => ['en' => 'Kind', 'bn' => 'ধরন'], 'options' => [
                    'truck' => ['en' => 'Truck', 'bn' => 'ট্রাক'],
                    'van' => ['en' => 'Covered van', 'bn' => 'কাভার্ড ভ্যান'],
                    'car' => ['en' => 'Car', 'bn' => 'গাড়ি'],
                    'motorcycle' => ['en' => 'Motorcycle', 'bn' => 'মোটরসাইকেল'],
                ]],
                'capacity' => ['type' => 'quantity', 'label' => ['en' => 'Capacity (tonnes)', 'bn' => 'ধারণক্ষমতা (টন)']],
                'bought_on' => ['type' => 'date', 'label' => ['en' => 'Bought on', 'bn' => 'কেনার তারিখ']],
                'price' => ['type' => 'money', 'label' => ['en' => 'Price', 'bn' => 'দাম']],
                'in_use' => ['type' => 'boolean', 'label' => ['en' => 'In use', 'bn' => 'চালু']],
                'notes' => ['type' => 'long_text', 'label' => ['en' => 'Notes', 'bn' => 'নোট']],
            ],
        ],
        'trip' => [
            'label' => ['en' => 'Trip', 'bn' => 'ট্রিপ'],
            'plural' => ['en' => 'Trips', 'bn' => 'ট্রিপ'],
            'title' => 'purpose',
            'search' => ['purpose', 'destination'],
            'filters' => ['vehicle_id', 'driver_id'],
            'columns' => ['purpose', 'vehicle_id', 'driver_id', 'trip_on', 'distance', 'fuel_cost'],
            'fields' => [
                'purpose' => ['type' => 'text', 'max' => 120, 'required' => true, 'label' => ['en' => 'Purpose', 'bn' => 'উদ্দেশ্য']],
                'vehicle_id' => ['type' => 'belongs_to', 'entity' => 'vehicle', 'required' => true, 'label' => ['en' => 'Vehicle', 'bn' => 'গাড়ি']],
                'driver_id' => ['type' => 'employee', 'label' => ['en' => 'Driver', 'bn' => 'চালক']],
                'trip_on' => ['type' => 'date', 'required' => true, 'label' => ['en' => 'Date', 'bn' => 'তারিখ']],
                'destination' => ['type' => 'text', 'max' => 120, 'label' => ['en' => 'Destination', 'bn' => 'গন্তব্য']],
                'distance' => ['type' => 'quantity', 'label' => ['en' => 'Distance (km)', 'bn' => 'দূরত্ব (কিমি)']],
                'fuel_cost' => ['type' => 'money', 'label' => ['en' => 'Fuel cost', 'bn' => 'জ্বালানি খরচ']],
            ],
        ],
    ],
    'rules' => [
        [
            'name' => 'service_every_km',
            'type' => 'integer',
            'default' => 5000,
            'min' => 500,
            'max' => 100000,
            'label' => ['en' => 'Service every (km)', 'bn' => 'কত কিমি পরপর সার্ভিস'],
            'description' => ['en' => 'A vehicle is due for service after this many kilometres of trips.', 'bn' => 'ট্রিপে এত কিলোমিটার চলার পর গাড়ির সার্ভিস লাগে।'],
        ],
    ],
];
