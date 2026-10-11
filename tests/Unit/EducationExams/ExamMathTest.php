<?php

use Modules\EducationExams\Services\ExamMath;

/*
 * EXM-1: the arithmetic of marks and grades, integers only.
 */

$gpa5 = (require dirname(__DIR__, 3).'/Modules/EducationExams/database/data/grading/bd_school_gpa5.php')['bands'];

it('turns marks into a percentage in basis points, half up', function () {
    expect(ExamMath::percentBp(6600, 7500))->toBe(8800)
        ->and(ExamMath::percentBp(3300, 10000))->toBe(3300)
        ->and(ExamMath::percentBp(1, 3))->toBe(3333)
        ->and(ExamMath::percentBp(500, 0))->toBe(0);
});

it('finds the band of a percentage on the GPA 5 scale', function () use ($gpa5) {
    expect(ExamMath::band($gpa5, 10000)['letter'])->toBe('A+')
        ->and(ExamMath::band($gpa5, 8000))->toMatchArray(['letter' => 'A+', 'point_centi' => 500])
        ->and(ExamMath::band($gpa5, 7999))->toMatchArray(['letter' => 'A', 'point_centi' => 400])
        ->and(ExamMath::band($gpa5, 3300))->toMatchArray(['letter' => 'D', 'is_pass' => true])
        ->and(ExamMath::band($gpa5, 3299))->toMatchArray(['letter' => 'F', 'is_pass' => false])
        ->and(ExamMath::band([], 5000))->toBeNull();
});

it('checks that bands make a whole scheme', function () use ($gpa5) {
    expect(ExamMath::bandsComplete($gpa5))->toBeTrue()
        ->and(ExamMath::bandsComplete([['min_percent_bp' => 4000]]))->toBeFalse()
        ->and(ExamMath::bandsComplete([['min_percent_bp' => 0], ['min_percent_bp' => 0]]))->toBeFalse()
        ->and(ExamMath::bandsComplete([['min_percent_bp' => 0], ['min_percent_bp' => 10001]]))->toBeFalse();
});

it('writes hundredths as plain text', function () {
    expect(ExamMath::centiText(8250))->toBe('82.5')
        ->and(ExamMath::centiText(10000))->toBe('100')
        ->and(ExamMath::centiText(5))->toBe('0.05');
});
