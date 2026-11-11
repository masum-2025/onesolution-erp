<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Packaging\SectorCatalog;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Modules\Accounting\Services\ChartOfAccounts;
use Modules\Diagnostics\Services\Tests;
use Modules\Inpatient\Services\Admissions;
use Modules\Inpatient\Models\Bed;
use Modules\Inpatient\Services\Beds;
use Modules\Inpatient\Services\InpatientStore;
use Modules\Inpatient\Services\Wards;
use Modules\Outpatient\Services\Chambers;
use Modules\Outpatient\Services\Doctors;
use Modules\Outpatient\Services\Schedules;
use Modules\PatientBilling\Services\Services;
use Modules\Patients\Services\Patients;
use Modules\Pharmacy\Services\Generics;
use Modules\Pharmacy\Services\Medicines;

/**
 * Local-only demo hospital (H-5): "Demo City Hospital", a hospital company in
 * the demo group with the hospital package, books on the hospital chart,
 * billing services and prices, 20 lab tests, 25 generics with 100 medicines,
 * three outdoor doctors with their chambers and weekly sessions, a general
 * ward and cabins with beds, a few patients and one admission.
 *
 * Everything goes through the modules' own services, as the screens would
 * do it. It only adds: it stops when the hospital exists. Logins (local
 * only): hospital.owner@, doctor1@ to doctor3@, nurse@, reception@, lab@,
 * pathologist@, pharmacist@ and billing@demo.test, password self::PASSWORD.
 */
class DemoHealthSeeder extends Seeder
{
    public const PASSWORD = 'One@2002';

    public const HOSPITAL = 'Demo City Hospital';

    private Organization $company;

    /** @var array<string, User> */
    private array $people = [];

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        $partner = Partner::query()->where('is_house', true)->first();
        $group = $partner === null ? null : Organization::query()->where('partner_id', $partner->id)->where('type', OrganizationType::Group)->orderBy('created_at')->first();
        if ($group === null) {
            $this->command?->warn('Demo hospital skipped: run the demo hierarchy first.');

            return;
        }
        if (Organization::query()->where('parent_id', $group->id)->get()->contains(fn (Organization $org) => $org->name === self::HOSPITAL)) {
            $this->command?->warn('Demo hospital skipped: it is there already.');

            return;
        }

        mt_srand(20261111);
        try {
            $owner = $this->hospital($group);
            $this->prices($owner);
            $this->lab($owner);
            $this->medicines($owner);
            $this->outdoor($owner);
            $this->wards($owner);
            $this->patients($owner);
        } finally {
            app(CurrentContext::class)->clear();
            app(AccessResolver::class)->forget();
        }

        foreach ($this->people as $email => $person) {
            $this->command?->info("Demo login: {$email} / ".self::PASSWORD);
        }
    }

    /** The company with the hospital package, its people and books. */
    private function hospital(Organization $group): User
    {
        $this->company = app(CreateOrganization::class)->handle(OrganizationType::Company, [
            'name' => ['en' => self::HOSPITAL, 'bn' => 'ডেমো সিটি হাসপাতাল'],
            'sector_key' => 'hospital',
        ], parent: $group);
        app(ApplySectorPackage::class)->handle($this->company->fresh());
        // On whatever the demo group's plan includes.
        foreach (app(SectorCatalog::class)->get('hospital')->modules as $module) {
            app(ModuleToggleService::class)->enable($this->company, $module, 'Demo data');
        }

        $owner = $this->person('Hospital Owner', 'hospital.owner@demo.test');
        app(AddMember::class)->handle($this->company, $owner, MembershipType::Owner);
        app(ContextResolver::class)->enterOrganization($owner, $this->company->id);
        foreach ([1 => 'Dr. Ayesha Rahman', 2 => 'Dr. Kamrul Hasan', 3 => 'Dr. Nusrat Jahan'] as $n => $name) {
            $this->staff($this->person($name, "doctor{$n}@demo.test"), ['doctor'], $owner);
        }
        foreach ([
            ['Shirin Nurse', 'nurse@demo.test', ['nurse', 'ward_incharge']],
            ['Rubel Reception', 'reception@demo.test', ['receptionist']],
            ['Lima Lab', 'lab@demo.test', ['lab_technician']],
            ['Dr. Faruk Pathologist', 'pathologist@demo.test', ['pathologist']],
            ['Sohel Pharmacist', 'pharmacist@demo.test', ['pharmacist']],
            ['Mitu Billing', 'billing@demo.test', ['billing_officer']],
        ] as [$name, $email, $templates]) {
            $this->staff($this->person($name, $email), $templates, $owner);
        }

        // Books on the hospital chart from the start of the fiscal year (July in Bangladesh).
        $now = CarbonImmutable::now();
        app(ChartOfAccounts::class)->setUp($this->company, 'hospital', ($now->month >= 7 ? $now->format('Y') : $now->subYear()->format('Y')).'-07-01', $owner);

        return $owner;
    }

    /** Billing services: doctors' fees, beds and every lab test (code, name, price in paisa). */
    private function prices(User $owner): void
    {
        $services = app(Services::class);
        $rows = [
            ['FEE-MED', 'Medicine consultation', 'মেডিসিন পরামর্শ', 'consultation', 80000],
            ['FEE-GYN', 'Gynaecology consultation', 'গাইনি পরামর্শ', 'consultation', 100000],
            ['FEE-CHD', 'Child specialist consultation', 'শিশু বিশেষজ্ঞ পরামর্শ', 'consultation', 70000],
            ['BED-GEN', 'General ward bed (a day)', 'সাধারণ ওয়ার্ডের বেড (প্রতিদিন)', 'bed', 100000],
            ['BED-CAB', 'Cabin (a day)', 'কেবিন (প্রতিদিন)', 'bed', 350000],
        ];
        foreach (self::tests() as [$code, $en, $bn, , , $price]) {
            $rows[] = [$code, $en, $bn, 'test', $price];
        }
        foreach ($rows as [$code, $en, $bn, $category, $price]) {
            $services->save($this->company, $this->company, null, ['code' => $code, 'name' => ['en' => $en, 'bn' => $bn], 'category' => $category, 'price_minor' => $price, 'is_active' => true], $owner);
        }
    }

    private function lab(User $owner): void
    {
        $tests = app(Tests::class);
        foreach (self::tests() as [$code, $en, $bn, $department, $sample]) {
            $tests->save($this->company, $this->company, null, [
                'code' => $code, 'name' => ['en' => $en, 'bn' => $bn], 'department' => $department, 'sample_type' => $sample, 'service_code' => $code, 'is_active' => true,
            ], $owner);
        }
    }

    /** 25 generics, four strengths or forms each: 100 medicines with MRPs (paisa). */
    private function medicines(User $owner): void
    {
        $generics = app(Generics::class);
        $medicines = app(Medicines::class);
        foreach (self::generics() as [$en, $bn, $controlled, $variants]) {
            $generic = $generics->save($this->company, $this->company, null, ['name' => ['en' => $en, 'bn' => $bn], 'is_controlled' => $controlled, 'in_use' => true], $owner);
            foreach ($variants as $index => [$strength, $form, $mrp]) {
                $medicines->save($this->company, $this->company, null, [
                    'brand_name' => ['en' => "{$en} {$strength}", 'bn' => "{$bn} {$strength}"],
                    'sku' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $en), 0, 6)).'-'.($index + 1).'-'.preg_replace('/[^0-9]/', '', $strength),
                    'generic_id' => $generic->getKey(), 'strength' => $strength, 'form' => $form, 'mrp_minor' => $mrp,
                    'pieces_per_strip' => in_array($form, ['tablet', 'capsule'], true) ? 10 : null,
                    'requires_prescription' => $controlled || in_array($form, ['injection'], true),
                ], $owner);
            }
        }
    }

    /** Three doctors, two chambers and their weekly sessions; each doctor signs in to see their own queue. */
    private function outdoor(User $owner): void
    {
        $chambers = app(Chambers::class);
        $rooms = [
            $chambers->save($this->company, $this->company, null, ['name' => 'Chamber 1', 'room' => '101', 'is_active' => true], $owner),
            $chambers->save($this->company, $this->company, null, ['name' => 'Chamber 2', 'room' => '102', 'is_active' => true], $owner),
        ];
        $doctors = [
            [1, ['en' => 'Dr. Ayesha Rahman', 'bn' => 'ডা. আয়েশা রহমান'], 'MBBS, FCPS (Medicine)', 'Medicine', 'A-10021', 'FEE-MED', 0, [0, 1, 2, 3], '17:00', '21:00'],
            [2, ['en' => 'Dr. Kamrul Hasan', 'bn' => 'ডা. কামরুল হাসান'], 'MBBS, MS (Obs & Gynae)', 'Gynaecology', 'A-10388', 'FEE-GYN', 1, [5, 6, 0], '10:00', '13:00'],
            [3, ['en' => 'Dr. Nusrat Jahan', 'bn' => 'ডা. নুসরাত জাহান'], 'MBBS, DCH', 'Child health', 'A-11210', 'FEE-CHD', 1, [1, 3], '16:00', '19:00'],
        ];
        foreach ($doctors as [$n, $name, $degrees, $specialty, $registration, $fee, $room, $days, $from, $to]) {
            $doctor = app(Doctors::class)->save($this->company, $this->company, null, [
                'name' => $name, 'degrees' => $degrees, 'specialty' => $specialty, 'registration_no' => $registration, 'fee_service_code' => $fee, 'is_active' => true,
            ], $owner);
            $sessions = array_map(fn (int $day) => ['chamber_id' => $rooms[$room]->getKey(), 'weekday' => $day, 'starts_at' => $from, 'ends_at' => $to], $days);
            app(Schedules::class)->save($this->company, $this->company, $doctor, $sessions, $this->people["doctor{$n}@demo.test"]->getKey(), (int) $doctor->version, $owner);
        }
    }

    private function wards(User $owner): void
    {
        $beds = app(Beds::class);
        foreach ([
            [['en' => 'General ward', 'bn' => 'সাধারণ ওয়ার্ড'], 'general', 'G-', 8, 'BED-GEN'],
            [['en' => 'Cabins', 'bn' => 'কেবিন'], 'cabin', 'C-10', 4, 'BED-CAB'],
        ] as [$name, $kind, $prefix, $count, $service]) {
            $ward = app(Wards::class)->save($this->company, $this->company, null, ['name' => $name, 'kind' => $kind, 'is_active' => true], $owner);
            for ($n = 1; $n <= $count; $n++) {
                $beds->save($this->company, $this->company, null, ['ward_id' => $ward->getKey(), 'code' => $prefix.$n, 'service_code' => $service, 'is_active' => true], null, $owner);
            }
        }
    }

    /** A few patients; the first is admitted to the general ward under the first doctor. */
    private function patients(User $owner): void
    {
        $patients = app(Patients::class);
        $made = [];
        foreach ([
            ['Rahim Uddin', 'male', 58, '01711000101'],
            ['Fatema Begum', 'female', 34, '01811000102'],
            ['Arif Hossain', 'male', 7, '01911000103'],
            ['Salma Akter', 'female', 26, '01611000104'],
            ['Jamal Mia', 'male', 71, '01511000105'],
        ] as [$name, $sex, $age, $mobile]) {
            $made[] = $patients->register($this->company, $this->company, ['name' => $name, 'sex' => $sex, 'age_years' => $age, 'mobile' => $mobile, 'confirm_new' => true], $owner);
        }
        $first = app(InpatientStore::class)->query(Bed::class, $this->company)->where('code', 'G-1')->firstOrFail();
        app(Admissions::class)->admit($this->company, $this->company, [
            'patient_id' => $made[0]->getKey(), 'bed_id' => $first->getKey(), 'doctor_user_id' => $this->people['doctor1@demo.test']->getKey(),
            'reason' => 'Chest pain and breathlessness', 'guardian_name' => 'Karim Uddin', 'guardian_mobile' => '01711000199',
        ], $owner);
    }

    private function person(string $name, string $email): User
    {
        return $this->people[$email] = User::query()->where('email', $email)->first()
            ?? User::query()->forceCreate(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
    }

    /**
     * @param  list<string>  $templates
     */
    private function staff(User $person, array $templates, User $owner): void
    {
        app(AddMember::class)->handle($this->company, $person, MembershipType::Staff, AccessScope::Descendants, actor: $owner);
        $roles = app(RoleService::class);
        $ids = [];
        foreach ($templates as $template) {
            $ids[] = (Role::query()->where('organization_id', $this->company->id)->where('template_key', $template)->first()
                ?? $roles->create($this->company, ['en' => __("access.templates.{$template}.name", [], 'en'), 'bn' => __("access.templates.{$template}.name", [], 'bn')],
                    null, null, $template, $owner, 'Demo data'))->id;
        }
        $roles->syncMembershipRoles($this->company->memberships()->where('user_id', $person->id)->sole(), $ids, $owner, 'Demo data');
    }

    /**
     * The lab's tests: code, names, department, sample, price (paisa).
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: int}>
     */
    public static function tests(): array
    {
        return [
            ['CBC', 'Complete blood count', 'রক্তের সম্পূর্ণ পরীক্ষা (CBC)', 'haematology', 'blood', 40000],
            ['ESR', 'ESR', 'ইএসআর', 'haematology', 'blood', 20000],
            ['BG', 'Blood grouping and Rh', 'রক্তের গ্রুপ', 'haematology', 'blood', 15000],
            ['RBS', 'Random blood sugar', 'র‍্যান্ডম ব্লাড সুগার', 'biochemistry', 'blood', 15000],
            ['FBS', 'Fasting blood sugar', 'খালি পেটে ব্লাড সুগার', 'biochemistry', 'blood', 15000],
            ['HBA1C', 'HbA1c', 'এইচবিএ১সি', 'biochemistry', 'blood', 120000],
            ['LIPID', 'Lipid profile', 'লিপিড প্রোফাইল', 'biochemistry', 'serum', 120000],
            ['CREAT', 'Serum creatinine', 'সিরাম ক্রিয়েটিনিন', 'biochemistry', 'serum', 50000],
            ['SGPT', 'SGPT (ALT)', 'এসজিপিটি', 'biochemistry', 'serum', 50000],
            ['BILI', 'Serum bilirubin', 'সিরাম বিলিরুবিন', 'biochemistry', 'serum', 50000],
            ['TSH', 'TSH', 'টিএসএইচ', 'immunology', 'serum', 90000],
            ['HBSAG', 'HBsAg', 'এইচবিএসএজি', 'immunology', 'serum', 60000],
            ['WIDAL', 'Widal test', 'ভিডাল টেস্ট', 'immunology', 'serum', 40000],
            ['NS1', 'Dengue NS1 antigen', 'ডেঙ্গু এনএস১', 'immunology', 'serum', 50000],
            ['CRP', 'C-reactive protein', 'সিআরপি', 'immunology', 'serum', 60000],
            ['URE', 'Urine routine examination', 'প্রস্রাবের রুটিন পরীক্ষা', 'clinical_pathology', 'urine', 20000],
            ['SRE', 'Stool routine examination', 'পায়খানার রুটিন পরীক্ষা', 'clinical_pathology', 'stool', 20000],
            ['XRCH', 'X-ray chest PA view', 'বুকের এক্স-রে', 'imaging', 'none', 50000],
            ['USGWA', 'Ultrasound whole abdomen', 'পুরো পেটের আলট্রাসাউন্ড', 'imaging', 'none', 150000],
            ['ECG', 'ECG (12 lead)', 'ইসিজি', 'cardiology', 'none', 40000],
        ];
    }

    /**
     * Common generics with four strengths or forms each: [strength, form, MRP in paisa].
     *
     * @return list<array{0: string, 1: string, 2: bool, 3: list<array{0: string, 1: string, 2: int}>}>
     */
    public static function generics(): array
    {
        $tabs = fn (array $strengths, array $mrps) => array_map(fn ($s, $m) => [$s, 'tablet', $m], $strengths, $mrps);

        return [
            ['Paracetamol', 'প্যারাসিটামল', false, [['500 mg', 'tablet', 120], ['665 mg', 'tablet', 200], ['120 mg/5 ml', 'syrup', 4000], ['80 mg/ml', 'drops', 3500]]],
            ['Omeprazole', 'ওমিপ্রাজল', false, [['20 mg', 'capsule', 500], ['40 mg', 'capsule', 800], ['40 mg', 'injection', 9000], ['20 mg/5 ml', 'suspension', 12000]]],
            ['Esomeprazole', 'ইসোমিপ্রাজল', false, [['20 mg', 'tablet', 700], ['40 mg', 'tablet', 1000], ['40 mg', 'injection', 12000], ['10 mg', 'powder', 1500]]],
            ['Pantoprazole', 'প্যান্টোপ্রাজল', false, [['20 mg', 'tablet', 500], ['40 mg', 'tablet', 700], ['40 mg', 'injection', 10000], ['20 mg', 'other', 600]]],
            ['Metformin', 'মেটফরমিন', false, $tabs(['500 mg', '850 mg', '1000 mg', '500 mg XR'], [400, 600, 800, 700])],
            ['Glimepiride', 'গ্লিমেপিরাইড', false, $tabs(['1 mg', '2 mg', '3 mg', '4 mg'], [500, 800, 1000, 1200])],
            ['Amlodipine', 'অ্যামলোডিপিন', false, $tabs(['2.5 mg', '5 mg', '10 mg', '5 mg + 20 mg'], [300, 500, 800, 1000])],
            ['Losartan', 'লোসারটান', false, $tabs(['25 mg', '50 mg', '100 mg', '50 mg + 12.5 mg'], [500, 800, 1200, 1000])],
            ['Atorvastatin', 'অ্যাটোরভাস্টাটিন', false, $tabs(['10 mg', '20 mg', '40 mg', '80 mg'], [1000, 1500, 2500, 4000])],
            ['Bisoprolol', 'বিসোপ্রোলল', false, $tabs(['1.25 mg', '2.5 mg', '5 mg', '10 mg'], [400, 600, 800, 1200])],
            ['Cetirizine', 'সেটিরিজিন', false, [['5 mg', 'tablet', 200], ['10 mg', 'tablet', 300], ['5 mg/5 ml', 'syrup', 3500], ['2.5 mg/ml', 'drops', 3000]]],
            ['Fexofenadine', 'ফেক্সোফেনাডিন', false, [['60 mg', 'tablet', 600], ['120 mg', 'tablet', 900], ['180 mg', 'tablet', 1200], ['30 mg/5 ml', 'suspension', 7000]]],
            ['Montelukast', 'মন্টিলুকাস্ট', false, [['4 mg', 'tablet', 1000], ['5 mg', 'tablet', 1200], ['10 mg', 'tablet', 1600], ['4 mg', 'powder', 1200]]],
            ['Salbutamol', 'সালবিউটামল', false, [['2 mg', 'tablet', 100], ['4 mg', 'tablet', 150], ['2 mg/5 ml', 'syrup', 2500], ['100 mcg', 'inhaler', 25000]]],
            ['Azithromycin', 'অ্যাজিথ্রোমাইসিন', false, [['250 mg', 'tablet', 2500], ['500 mg', 'tablet', 4000], ['200 mg/5 ml', 'suspension', 12000], ['500 mg', 'injection', 30000]]],
            ['Cefixime', 'সেফিক্সিম', false, [['200 mg', 'capsule', 3500], ['400 mg', 'capsule', 6000], ['100 mg/5 ml', 'suspension', 15000], ['200 mg', 'tablet', 3500]]],
            ['Amoxicillin', 'অ্যামোক্সিসিলিন', false, [['250 mg', 'capsule', 500], ['500 mg', 'capsule', 800], ['125 mg/5 ml', 'suspension', 6000], ['500 mg', 'injection', 5000]]],
            ['Ciprofloxacin', 'সিপ্রোফ্লক্সাসিন', false, [['250 mg', 'tablet', 1000], ['500 mg', 'tablet', 1500], ['750 mg', 'tablet', 2000], ['0.3%', 'drops', 4500]]],
            ['Metronidazole', 'মেট্রোনিডাজল', false, [['200 mg', 'tablet', 100], ['400 mg', 'tablet', 150], ['200 mg/5 ml', 'suspension', 3500], ['500 mg/100 ml', 'injection', 8000]]],
            ['Domperidone', 'ডমপেরিডন', false, [['10 mg', 'tablet', 300], ['5 mg/5 ml', 'suspension', 3500], ['5 mg/ml', 'drops', 3000], ['30 mg', 'suppository', 1500]]],
            ['Diclofenac', 'ডাইক্লোফেনাক', false, [['50 mg', 'tablet', 100], ['100 mg SR', 'tablet', 300], ['75 mg/3 ml', 'injection', 2000], ['1%', 'cream', 8000]]],
            ['Ketorolac', 'কেটোরোলাক', false, [['10 mg', 'tablet', 1200], ['30 mg/ml', 'injection', 3000], ['60 mg/2 ml', 'injection', 5000], ['0.5%', 'drops', 9000]]],
            ['Calcium + Vitamin D3', 'ক্যালসিয়াম + ভিটামিন ডি৩', false, $tabs(['500 mg + 200 IU', '600 mg + 400 IU', '500 mg + 400 IU', '1000 mg + 800 IU'], [800, 1000, 900, 1500])],
            ['Oral rehydration salts', 'খাবার স্যালাইন', false, [['10.25 g', 'powder', 600], ['20.5 g', 'powder', 1000], ['500 ml', 'other', 5000], ['5.125 g', 'powder', 400]]],
            // A controlled medicine (narcotic register): sold only with a prescription and written in the register.
            ['Pethidine', 'পেথিডিন', true, [['50 mg/ml', 'injection', 5000], ['100 mg/2 ml', 'injection', 8000], ['50 mg', 'tablet', 1500], ['25 mg', 'tablet', 1000]]],
        ];
    }
}
