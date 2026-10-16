<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * The education home (UX-3): menus in each kind of institution's own words
 * (and without what it does not use), the education family the home shows,
 * and the exam widgets and work: papers coming up (rule upcoming_days),
 * marks not handed in (all, or a teacher's own), with module off,
 * permission and isolation.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-01 04:00:00', 'UTC'));
    config(['tenancy.throttle.sensitive' => 1000]);
    $this->w = tenancyWorld();
    foreach ([$this->w->g1, $this->w->g2, $this->w->g3] as $group) {
        toggles()->enable($group, 'education', 'Test setup');
    }
    $this->owner = createMember($this->w->c1);
    $this->token = orgToken($this->owner, $this->w->c1);
    $this->api = fn (string $path, $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id."/education/{$path}";
    $this->exams = fn (string $path = '', $unit = null) => '/api/organizations/'.($unit ?? $this->w->c1)->id.'/exams'.($path === '' ? '' : "/{$path}");
    $this->as = fn (?string $token = null) => $this->asToken($token ?? $this->token);
    $this->children = fn (?string $token = null) => collect(collect(($this->as)($token)->getJson('/api/menu')->assertOk()->json('data'))->firstWhere('module', 'education')['children'])->pluck('label', 'key')->all();
});

it('names the education menu in each kind of institution\'s own words and leaves out what it does not use', function () {
    $sector = fn (?string $key) => $this->w->c1->forceFill(['sector_key' => $key])->save();

    $sector('university');
    expect(($this->children)())->toMatchArray(['sections' => 'Semesters and sections', 'promotions' => 'Move up a semester', 'admissions' => 'Admissions (applications)'])
        ->and(($this->children)())->toHaveKey('documents');

    $sector('school');
    expect(($this->children)())->toMatchArray(['sections' => 'Classes and sections', 'promotions' => 'Move up a class', 'admissions' => 'Admissions']);

    // A coaching centre moves no one up and issues no cards or certificates.
    $sector('coaching');
    expect(($this->children)())->toMatchArray(['sections' => 'Courses and batches'])
        ->and(($this->children)())->not->toHaveKey('promotions')
        ->and(($this->children)())->not->toHaveKey('documents');

    // In Bangla too; a sector set on the group reaches its companies.
    $sector(null);
    $this->w->g1->forceFill(['sector_key' => 'college'])->save();
    $bn = collect(collect(($this->as)()->withHeader('X-Locale', 'bn')->getJson('/api/menu')->json('data'))->firstWhere('module', 'education')['children'])->pluck('label', 'key');
    expect($bn['sections'])->toBe('বর্ষ ও গ্রুপ')->and($bn['promotions'])->toBe('পরের বর্ষে তোলা');
});

it('puts the education family first, in one Education section, its home the only dashboard', function () {
    foreach (['education_fees', 'education_exams', 'course_registration'] as $module) {
        toggles()->enable($this->w->g1, $module, 'Test setup');
    }
    $this->w->c1->forceFill(['sector_key' => 'university'])->save();
    $menu = collect(($this->as)()->getJson('/api/menu')->assertOk()->json('data'))->keyBy('module');

    foreach (['education', 'education_fees', 'education_exams', 'course_registration'] as $module) {
        expect($menu[$module])->toMatchArray(['section' => 'education', 'section_label' => 'Education']);
    }
    expect($menu['education']['label'])->toBe('Students and semesters')
        ->and(collect($menu['education']['children'])->pluck('key')->first())->toBe('overview')
        ->and($menu['education_exams']['label'])->toBe('Exams and results')
        // Their main work comes first: the section leads the sidebar.
        ->and($menu->first()['section'])->toBe('education');
    foreach (['education', 'education_fees', 'education_exams', 'course_registration'] as $module) {
        $keys = collect($menu[$module]['children'])->pluck('key');
        expect($keys)->not->toContain('dashboard')->and($keys->last())->toBe('settings');
    }
    // Off the menu, a module's own dashboard still opens.
    ($this->as)()->getJson('/api/modules/education_fees/dashboard')->assertOk();

    // In Bangla; a company of another kind keeps its menu as it was.
    $bn = collect(($this->as)()->withHeader('X-Locale', 'bn')->getJson('/api/menu')->json('data'))->keyBy('module');
    expect($bn['education']['section_label'])->toBe('শিক্ষা')->and($bn['course_registration']['label'])->toBe('কোর্স নিবন্ধন');
    toggles()->enable($this->w->c2, 'hrm', 'Test setup');
    $other = createMember($this->w->c2);
    expect(collect($this->asToken(orgToken($other, $this->w->c2))->getJson('/api/menu')->json('data'))->firstWhere('module', 'hrm')['section'])->toBe('people');
});

it('tells the home which modules of the education family are on here', function () {
    eduSchool($this);
    expect(($this->as)()->getJson(($this->api)('overview'))->assertOk()->json('data.modules'))->toBe(['education']);

    toggles()->enable($this->w->g1, 'education_fees', 'Test setup');
    toggles()->enable($this->w->g1, 'education_exams', 'Test setup');
    // In menu order: fees before exams.
    expect(($this->as)()->getJson(($this->api)('overview'))->json('data.modules'))->toBe(['education', 'education_fees', 'education_exams']);
});

describe('exam widgets', function () {
    beforeEach(function () {
        foreach ([$this->w->g1, $this->w->g2] as $group) {
            toggles()->enable($group, 'education_exams', 'Test setup');
        }
        $this->school = eduSchool($this);
        $subjects = collect(($this->as)()->getJson(($this->api)('structure/subjects'))->json('data'))->keyBy('code');
        $curriculum = ($this->as)()->postJson(($this->api)('structure/curricula'), ['program_id' => $this->school->program['id'], 'name' => '2026', 'effective_from_year' => 2026, 'status' => 'active'])->assertCreated()->json('data');
        foreach (['BAN1', 'ENG1', 'MATH'] as $code) {
            ($this->as)()->postJson(($this->api)('structure/curriculum_items'), ['curriculum_id' => $curriculum['id'], 'level_id' => $this->school->class6['id'], 'subject_id' => $subjects[$code]['id'], 'kind' => 'compulsory'])->assertCreated();
        }
        newStudent($this, $this->school, ['name' => 'Rahim', 'guardians' => []])->assertCreated();

        // Today is 1 March: Bangla was sat yesterday, English is in 4 days, Maths in 24.
        $gpa5 = collect(($this->as)()->getJson(($this->exams)('setup'))->json('data.schemes'))->firstWhere('code', 'bd_school_gpa5');
        $this->exam = ($this->as)()->postJson(($this->exams)(), [
            'name' => ['en' => 'Term 1', 'bn' => 'প্রথম সাময়িক'], 'session_id' => $this->school->session['id'], 'kind' => 'term',
            'starts_on' => '2026-02-25', 'ends_on' => '2026-03-31', 'grading_scheme_id' => $gpa5['id'],
        ])->assertCreated()->json('data');
        $papers = collect(($this->as)()->postJson(($this->exams)("{$this->exam['id']}/papers/from-curriculum"), ['level_id' => $this->school->class6['id'], 'full_marks_centi' => 10000, 'pass_marks_centi' => 3300])->json('data'))->keyBy('subject.code');
        foreach (['BAN1' => '2026-02-28', 'ENG1' => '2026-03-05', 'MATH' => '2026-03-25'] as $code => $day) {
            ($this->as)()->patchJson(($this->exams)("{$this->exam['id']}/papers/{$papers[$code]['id']}"), ['sits_on' => $day, 'base_version' => $papers[$code]['version']])->assertOk();
        }
        ($this->as)()->postJson(($this->exams)("{$this->exam['id']}/status"), ['status' => 'marking', 'base_version' => $this->exam['version']])->assertOk();

        $this->widget = fn (string $key, ?string $token = null) => ($this->as)($token)->getJson("/api/modules/education_exams/dashboard/{$key}");
        $this->teacher = function (string $name, bool $classTeacher) {
            toggles()->enable($this->w->g1, 'hrm', 'Test setup');
            $user = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view', 'education_exams.view', 'education_exams.enter_marks'], "Teacher {$name}"));
            $employee = hireVia($this, $this->token, $this->w->c1, ['full_name' => $name, 'joined_on' => '2026-01-01'])->assertCreated()->json('data.id');
            DB::table('hrm_employees')->where('id', $employee)->update(['user_id' => $user->id]);
            if ($classTeacher) {
                $section = ($this->as)()->getJson(($this->api)("structure/sections?session_id={$this->school->session['id']}"))->json('data.0');
                ($this->as)()->patchJson(($this->api)("structure/sections/{$this->school->section['id']}"), ['base_version' => $section['version'], 'class_teacher_id' => $employee])->assertOk();
            }

            return orgToken($user, $this->w->c1);
        };
    });

    it('lists the papers of the coming days, as many days as the rule says', function () {
        $items = ($this->widget)('upcoming')->assertOk()->json('data.items');
        expect($items)->toHaveCount(1)
            ->and($items[0])->toMatchArray(['date' => '2026-03-05', 'meta' => 'Term 1', 'path' => "/exams/{$this->exam['id']}"])
            ->and($items[0]['label'])->toContain('English');

        orgRule($this->w->c1, 'education_exams.upcoming_days', 30);
        expect(collect(($this->widget)('upcoming')->json('data.items'))->pluck('date')->all())->toBe(['2026-03-05', '2026-03-25']);
    });

    it('counts marks not handed in: every sheet for the office, a teacher\'s own for them, in their bell too', function () {
        ($this->widget)('marks_due')->assertOk()->assertJsonPath('data.value', 1);

        $classTeacher = ($this->teacher)('Rina', true);
        ($this->widget)('marks_due', $classTeacher)->assertOk()->assertJsonPath('data.value', 1);
        expect(collect(($this->as)($classTeacher)->getJson('/api/attention')->json('data'))->firstWhere('key', 'education_exams.marks_due'))
            ->toMatchArray(['count' => 1, 'path' => '/exams/my-sheets']);
        // Only their own papers come up for them.
        expect(($this->widget)('upcoming', $classTeacher)->json('data.items'))->toHaveCount(1);

        $other = ($this->teacher)('Kamal', false);
        ($this->widget)('marks_due', $other)->assertOk()->assertJsonPath('data.value', 0);
        expect(($this->widget)('upcoming', $other)->json('data.items'))->toBe([]);
        expect(collect(($this->as)($other)->getJson('/api/attention')->json('data'))->firstWhere('key', 'education_exams.marks_due'))->toBeNull();
    });

    it('keeps to its own institution, its permission and the module being on', function () {
        $c2 = createMember($this->w->c2);
        expect(($this->widget)('upcoming', orgToken($c2, $this->w->c2))->assertOk()->json('data.items'))->toBe([]);
        ($this->widget)('marks_due', orgToken($c2, $this->w->c2))->assertOk()->assertJsonPath('data.value', 0);

        $clerk = staffWithRoles($this->w->c1, makeRole($this->w->c1, ['education.view'], 'Clerk'));
        ($this->widget)('upcoming', orgToken($clerk, $this->w->c1))->assertForbidden();

        toggles()->disable($this->w->g1, 'education_exams', 'Test', confirm: true);
        ($this->widget)('upcoming')->assertForbidden();
    });
});
