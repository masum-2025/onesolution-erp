<?php

/*
| Final inspection sampling: ISO 2859-1 single sampling, normal inspection,
| general inspection level II, as garment buyers' inspection charts give it.
| Rows: the largest lot size of the row (null = any larger), the sample size,
| and per AQL the most defects (major) a passing sample may have; one more
| fails it. A buyer with another chart: add its AQL column here and to the
| rule factory_erp.final_aql.
*/

return [
    'levels' => [
        [8, 2, ['2.5' => 0, '4.0' => 0]],
        [15, 3, ['2.5' => 0, '4.0' => 0]],
        [25, 5, ['2.5' => 0, '4.0' => 0]],
        [50, 8, ['2.5' => 0, '4.0' => 1]],
        [90, 13, ['2.5' => 1, '4.0' => 1]],
        [150, 20, ['2.5' => 1, '4.0' => 2]],
        [280, 32, ['2.5' => 2, '4.0' => 3]],
        [500, 50, ['2.5' => 3, '4.0' => 5]],
        [1200, 80, ['2.5' => 5, '4.0' => 7]],
        [3200, 125, ['2.5' => 7, '4.0' => 10]],
        [10000, 200, ['2.5' => 10, '4.0' => 14]],
        [35000, 315, ['2.5' => 14, '4.0' => 21]],
        [150000, 500, ['2.5' => 21, '4.0' => 21]],
        [500000, 800, ['2.5' => 21, '4.0' => 21]],
        [null, 1250, ['2.5' => 21, '4.0' => 21]],
    ],
];
