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

it('works out a subject: parts with their own pass marks, absent, the failing band', function () use ($gpa5) {
    $parts = fn (int $cq, int $mcq, bool $absent = false) => [
        ['full_marks_centi' => 7000, 'pass_marks_centi' => 2300, 'marks_centi' => $absent ? null : $cq, 'absent' => $absent],
        ['full_marks_centi' => 3000, 'pass_marks_centi' => 1000, 'marks_centi' => $absent ? null : $mcq, 'absent' => $absent],
    ];
    expect(ExamMath::subject($parts(6000, 2500), 3300, $gpa5))->toMatchArray(['marks_centi' => 8500, 'percent_bp' => 8500, 'letter' => 'A+', 'point_centi' => 500, 'passed' => true])
        // 65 % overall, but MCQ below its pass mark: F.
        ->and(ExamMath::subject($parts(6000, 500), 3300, $gpa5))->toMatchArray(['marks_centi' => 6500, 'letter' => 'F', 'point_centi' => 0, 'passed' => false])
        ->and(ExamMath::subject($parts(0, 0, true), 3300, $gpa5))->toMatchArray(['absent' => true, 'passed' => false, 'letter' => 'F']);
});

it('works out the grade point: average, 4th subject bonus above 2, fail on any subject, credits', function () {
    $subject = fn (string $kind, int $point, bool $passed = true, int $credits = 300) => ['kind' => $kind, 'point_centi' => $point, 'credits_centi' => $credits, 'passed' => $passed];
    // (5 + 4 + 3.5) / 3 = 4.17; an optional A (4.00) adds 2.00 / 3 -> 4.83.
    expect(ExamMath::gpa([$subject('compulsory', 500), $subject('compulsory', 400), $subject('compulsory', 350)], 500, 200, false))->toBe(['gpa_centi' => 417, 'passed' => true, 'failed' => 0])
        ->and(ExamMath::gpa([$subject('compulsory', 500), $subject('compulsory', 400), $subject('compulsory', 350), $subject('optional', 400)], 500, 200, false)['gpa_centi'])->toBe(483)
        // Never above the top.
        ->and(ExamMath::gpa([$subject('compulsory', 500), $subject('optional', 500)], 500, 200, false)['gpa_centi'])->toBe(500)
        ->and(ExamMath::gpa([$subject('compulsory', 500), $subject('compulsory', 0, false)], 500, 200, false))->toBe(['gpa_centi' => 0, 'passed' => false, 'failed' => 1])
        // Credits: (4.00 × 3 + 3.00 × 1.5) / 4.5 = 3.67.
        ->and(ExamMath::gpa([$subject('compulsory', 400), $subject('compulsory', 300, true, 150)], 400, null, true)['gpa_centi'])->toBe(367);
    expect(ExamMath::letterFor((require dirname(__DIR__, 3).'/Modules/EducationExams/database/data/grading/bd_school_gpa5.php')['bands'], 417, true))->toBe('A');
});

it('weighs exams and gives shared merit positions', function () {
    expect(ExamMath::weightedPercent([['percent_bp' => 6000, 'weight_bp' => 3000], ['percent_bp' => 8000, 'weight_bp' => 7000]]))->toBe(7400)
        ->and(ExamMath::weightedPercent([]))->toBe(0);
    expect(ExamMath::ranks([
        'a' => ['passed' => true, 'gpa_centi' => 450, 'marks_centi' => 400],
        'b' => ['passed' => true, 'gpa_centi' => 500, 'marks_centi' => 450],
        'c' => ['passed' => true, 'gpa_centi' => 450, 'marks_centi' => 400],
        'd' => ['passed' => false, 'gpa_centi' => 0, 'marks_centi' => 500],
    ]))->toBe(['b' => 1, 'a' => 2, 'c' => 2, 'd' => 4]);
});
