<?php

/*
| The kinds of defect a company starts with (it adds, renames and switches
| off its own). Names in English and Bangla; other languages are added by
| the company.
*/

return [
    ['code' => 'SKIP', 'name' => ['en' => 'Skip stitch', 'bn' => 'সেলাই বাদ পড়া']],
    ['code' => 'BROKEN', 'name' => ['en' => 'Broken stitch', 'bn' => 'সেলাই ছেঁড়া']],
    ['code' => 'OPEN', 'name' => ['en' => 'Open seam', 'bn' => 'সেলাই খোলা']],
    ['code' => 'SPOT', 'name' => ['en' => 'Oil or dirty spot', 'bn' => 'তেল বা ময়লার দাগ']],
    ['code' => 'MEASURE', 'name' => ['en' => 'Out of measurement', 'bn' => 'মাপে ভুল']],
];
