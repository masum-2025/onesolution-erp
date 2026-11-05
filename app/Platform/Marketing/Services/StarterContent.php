<?php

namespace App\Platform\Marketing\Services;

use App\Platform\Branding\BrandResolver;
use App\Platform\Tenancy\Models\Partner;

/**
 * What a new website starts with, in English and Bangla. The house
 * partner's tells about the platform itself (every claim is something the
 * product does today; prices always come from the plan catalogue). Another
 * partner's is a short, general start under its own product name, to be
 * written over in the console. Everything here is ordinary content: the
 * owner changes or removes any of it.
 */
class StarterContent
{
    public function __construct(private BrandResolver $brands) {}

    /**
     * @return array{template: string, locales: list<string>, default_locale: string, settings: array<string, mixed>, pages: list<array{slug: string, title: array, seo?: array, in_menu?: bool, blocks: list<array{0: string, 1: array}>}>}
     */
    public function for(Partner $partner): array
    {
        return $partner->is_house ? $this->house() : $this->partner((string) ($this->brands->for($partner)['name'] ?? $partner->name));
    }

    private static function t(string $en, string $bn): array
    {
        return ['en' => $en, 'bn' => $bn];
    }

    private function house(): array
    {
        $t = self::t(...);

        return [
            'template' => 'aurora',
            'locales' => ['en', 'bn'],
            'default_locale' => 'en',
            'settings' => [
                'header' => [
                    'menu' => [
                        ['label' => $t('Product', 'প্রোডাক্ট'), 'url' => '/#product'],
                        ['label' => $t('Solutions', 'সমাধান'), 'url' => '/#solutions'],
                        ['label' => $t('Pricing', 'মূল্য'), 'url' => '/pricing'],
                        ['label' => $t('Partners', 'পার্টনার'), 'url' => '/partners'],
                        ['label' => $t('Contact', 'যোগাযোগ'), 'url' => '/contact'],
                    ],
                    'cta_label' => $t('Start free', 'ফ্রি শুরু করুন'), 'cta_url' => 'app:/signup',
                    'signin_label' => $t('Sign in', 'সাইন ইন'), 'signin_url' => 'app:/login',
                ],
                'notice' => [
                    'enabled' => true,
                    'tag' => $t('New', 'নতুন'),
                    'text' => $t('Payslips and report cards now print in Bangla', 'পে-স্লিপ আর রিপোর্ট কার্ড এখন বাংলায় প্রিন্ট হয়'),
                    'link_label' => $t('Read the update', 'আপডেট পড়ুন'), 'url' => '/#news',
                    'extra' => $t('Offer: 2 months free on yearly plans until 31 Dec', 'অফার: ৩১ ডিসেম্বর পর্যন্ত বাৎসরিক প্ল্যানে ২ মাস ফ্রি'),
                    'until' => '2026-12-31',
                ],
                'footer' => [
                    'about' => $t('One ERP for schools, factories and growing businesses. Made in Bangladesh.', 'স্কুল, কারখানা আর বেড়ে ওঠা ব্যবসার জন্য একটি ইআরপি। বাংলাদেশে তৈরি।'),
                    'columns' => [
                        ['title' => $t('Product', 'প্রোডাক্ট'), 'links' => [
                            ['label' => $t('Modules', 'মডিউল'), 'url' => '/#product'],
                            ['label' => $t('Pricing', 'মূল্য'), 'url' => '/pricing'],
                            ['label' => $t('What\'s new', 'নতুন কী'), 'url' => '/#news'],
                        ]],
                        ['title' => $t('Solutions', 'সমাধান'), 'links' => [
                            ['label' => $t('Schools', 'স্কুল'), 'url' => '/#solutions'],
                            ['label' => $t('Factories', 'কারখানা'), 'url' => '/#solutions'],
                            ['label' => $t('Retail', 'দোকান'), 'url' => '/#solutions'],
                        ]],
                        ['title' => $t('Company', 'প্রতিষ্ঠান'), 'links' => [
                            ['label' => $t('Partners', 'পার্টনার'), 'url' => '/partners'],
                            ['label' => $t('Contact', 'যোগাযোগ'), 'url' => '/contact'],
                        ]],
                        ['title' => $t('Account', 'অ্যাকাউন্ট'), 'links' => [
                            ['label' => $t('Sign in', 'সাইন ইন'), 'url' => 'app:/login'],
                            ['label' => $t('Create an account', 'অ্যাকাউন্ট খুলুন'), 'url' => 'app:/signup'],
                        ]],
                    ],
                ],
                'seo' => [
                    'title' => $t('One Solutions — ERP for schools, factories and growing businesses', 'ওয়ান সলিউশনস — স্কুল, কারখানা ও ব্যবসার জন্য ইআরপি'),
                    'description' => $t(
                        'Cloud ERP in Bangla and English: HR, attendance, payroll, accounting, inventory, POS, CRM and complete school management. Works offline. Start free.',
                        'বাংলা ও ইংরেজিতে ক্লাউড ইআরপি: কর্মী, হাজিরা, বেতন, হিসাব, স্টক, পিওএস, সিআরএম আর পুরো স্কুল ব্যবস্থাপনা। ইন্টারনেট ছাড়াও চলে। ফ্রি শুরু করুন।'
                    ),
                ],
            ],
            'pages' => [
                ['slug' => '', 'title' => $t('Home', 'হোম'), 'in_menu' => false, 'blocks' => $this->houseHome()],
                ['slug' => 'pricing', 'title' => $t('Pricing', 'মূল্য'), 'seo' => [
                    'title' => $t('Pricing — One Solutions', 'মূল্য — ওয়ান সলিউশনস'),
                    'description' => $t('Start free, then pay monthly or yearly. Add single modules from the add-on store.', 'ফ্রি শুরু করুন, তারপর মাসিক বা বাৎসরিক। দরকার হলে শুধু একটি মডিউল আলাদা নিন।'),
                ], 'blocks' => [
                    ['pricing', $this->pricing()],
                    ['faq', $this->faq()],
                    ['cta', $this->cta()],
                ]],
                ['slug' => 'partners', 'title' => $t('Partners', 'পার্টনার'), 'seo' => [
                    'title' => $t('Partner program — sell One Solutions under your brand', 'পার্টনার প্রোগ্রাম — নিজের ব্র্যান্ডে বিক্রি করুন'),
                    'description' => $t('Resellers, agencies and IT firms offer a complete ERP under their own name, logo, colours and domain.', 'রিসেলার, এজেন্সি আর আইটি প্রতিষ্ঠান নিজের নাম, লোগো, রং আর ডোমেইনে পূর্ণ ইআরপি দিন।'),
                ], 'blocks' => [
                    ['partner', $this->partnerBand()],
                    ['features', [
                        'eyebrow' => $t('Why partners choose us', 'কেন পার্টনাররা আমাদের বেছে নেন'),
                        'title' => $t('Everything a software company needs, without building it', 'নিজে না বানিয়েও একটি সফটওয়্যার কোম্পানির যা যা লাগে'),
                        'items' => [
                            ['icon' => 'globe', 'color' => 'blue', 'title' => $t('Your own domain', 'নিজের ডোমেইন'), 'text' => $t('Clients sign in at your address, with your logo and colours on every screen and e-mail.', 'গ্রাহক আপনার ঠিকানায় সাইন ইন করবে; প্রতিটি স্ক্রিন আর ই-মেইলে থাকবে আপনার লোগো ও রং।')],
                            ['icon' => 'chart', 'color' => 'green', 'title' => $t('Your own prices', 'নিজের দাম'), 'text' => $t('Make your own plans and add-on prices, and see what each client pays.', 'নিজের প্ল্যান আর অ্যাড-অনের দাম ঠিক করুন; কোন গ্রাহক কত দেয় দেখুন।')],
                            ['icon' => 'lock', 'color' => 'red', 'title' => $t('Client data kept apart', 'গ্রাহকের তথ্য আলাদা'), 'text' => $t('You manage accounts; a client\'s business data opens only with their approval, for a limited time.', 'আপনি অ্যাকাউন্ট সামলান; গ্রাহকের ব্যবসার তথ্য খোলে শুধু তার অনুমতিতে, নির্দিষ্ট সময়ের জন্য।')],
                            ['icon' => 'gear', 'color' => 'violet', 'title' => $t('We run the servers', 'সার্ভার আমরা চালাই'), 'text' => $t('Updates, backups and security are ours, so you can focus on selling and support.', 'আপডেট, ব্যাকআপ আর নিরাপত্তা আমাদের দায়িত্ব; আপনি বিক্রি আর সাপোর্টে মন দিন।')],
                        ],
                    ]],
                    ['cta', $this->cta()],
                ]],
                ['slug' => 'contact', 'title' => $t('Contact', 'যোগাযোগ'), 'seo' => [
                    'title' => $t('Contact One Solutions', 'ওয়ান সলিউশনসের সাথে যোগাযোগ'),
                    'description' => $t('Ask for a live demo or help with moving from your old software.', 'লাইভ ডেমো চান, বা পুরনো সফটওয়্যার থেকে সরতে সাহায্য নিন।'),
                ], 'blocks' => [
                    ['text', [
                        'eyebrow' => $t('Contact', 'যোগাযোগ'),
                        'title' => $t('Let\'s talk about your organization', 'আপনার প্রতিষ্ঠান নিয়ে কথা বলি'),
                        'body' => $t(
                            "Tell us what you run, how many people work there and which software you use today.\n\nWe will show you the parts that matter to you in a live demo and help you move your data.",
                            "আপনি কী চালান, কতজন কাজ করেন আর এখন কোন সফটওয়্যার ব্যবহার করেন, আমাদের জানান।\n\nলাইভ ডেমোতে আপনার দরকারি অংশগুলো দেখাব, আর আপনার তথ্য সরাতে সাহায্য করব।"
                        ),
                    ]],
                    ['cta', $this->cta()],
                ]],
            ],
        ];
    }

    /** @return list<array{0: string, 1: array}> */
    private function houseHome(): array
    {
        $t = self::t(...);

        return [
            ['hero_slider', [
                'effect' => 'fade', 'seconds' => 7, 'autoplay' => true,
                'slides' => [
                    [
                        'eyebrow' => $t('Made in Bangladesh · Built for the world', 'বাংলাদেশে তৈরি · বিশ্বের জন্য'),
                        'title' => $t('One ERP to run your', 'এক সফটওয়্যারে চালান আপনার'),
                        'rotating' => [$t('school', 'স্কুল'), $t('factory', 'কারখানা'), $t('shop', 'দোকান'), $t('whole group', 'পুরো প্রতিষ্ঠান')],
                        'text' => $t(
                            'People, attendance, payroll, accounts, stock, sales and complete school management. Everything in one place, in Bangla and English, and it keeps working when the internet doesn\'t.',
                            'কর্মী, হাজিরা, বেতন, হিসাব, স্টক, বিক্রি আর পুরো স্কুল ব্যবস্থাপনা — সব এক জায়গায়, বাংলা ও ইংরেজিতে। ইন্টারনেট না থাকলেও কাজ থামে না।'
                        ),
                        'primary_label' => $t('Start free, no card needed', 'ফ্রি শুরু করুন, কার্ড লাগবে না'), 'primary_url' => 'app:/signup',
                        'secondary_label' => $t('Book a live demo', 'লাইভ ডেমো দেখুন'), 'secondary_url' => '/contact',
                        'ticks' => [$t('Free plan forever', 'ফ্রি প্ল্যান সবসময়'), $t('Bangla and English', 'বাংলা ও ইংরেজি'), $t('Your data stays yours', 'আপনার তথ্য আপনারই')],
                        'visual' => 'dashboard',
                    ],
                    [
                        'eyebrow' => $t('For schools, colleges and madrasas', 'স্কুল, কলেজ ও মাদ্রাসার জন্য'),
                        'title' => $t('Results, fees and attendance', 'ফলাফল, বেতন আর হাজিরা'),
                        'highlight' => $t('in one tap', 'এক ট্যাপে'),
                        'text' => $t(
                            'Publish results with GPA and position, collect fees online, and let every guardian see their child\'s day on a phone, in Bangla.',
                            'জিপিএ আর মেধাক্রমসহ ফলাফল প্রকাশ করুন, অনলাইনে বেতন নিন, আর প্রত্যেক অভিভাবক ফোনেই বাংলায় সন্তানের দিনের খবর দেখুক।'
                        ),
                        'primary_label' => $t('See school management', 'স্কুল ব্যবস্থাপনা দেখুন'), 'primary_url' => '/#solutions',
                        'secondary_label' => $t('Book a demo', 'ডেমো দেখুন'), 'secondary_url' => '/contact',
                        'visual' => 'report_card',
                    ],
                    [
                        'eyebrow' => $t('For resellers and IT firms', 'রিসেলার ও আইটি প্রতিষ্ঠানের জন্য'),
                        'title' => $t('Your brand.', 'আপনার ব্র্যান্ড।'),
                        'highlight' => $t('Our engine.', 'আমাদের ইঞ্জিন।'),
                        'text' => $t(
                            'Put your name, logo, colours and domain on a complete ERP. Set your own prices; we run the servers, backups and updates.',
                            'আপনার নাম, লোগো, রং আর ডোমেইনে দিন একটি পূর্ণ ইআরপি। দাম ঠিক করবেন আপনি; সার্ভার, ব্যাকআপ আর আপডেট আমরা চালাই।'
                        ),
                        'primary_label' => $t('Become a partner', 'পার্টনার হোন'), 'primary_url' => '/partners',
                        'secondary_label' => $t('Talk to us', 'কথা বলুন'), 'secondary_url' => '/contact',
                        'visual' => 'brands',
                    ],
                ],
            ]],
            ['stats', ['items' => [
                ['auto' => 'modules', 'label' => $t('modules on one platform', 'মডিউল এক প্ল্যাটফর্মে')],
                ['auto' => 'sectors', 'label' => $t('sectors ready on day one', 'সেক্টর প্রথম দিন থেকেই প্রস্তুত')],
                ['auto' => 'languages', 'label' => $t('languages: বাংলা and English', 'ভাষা: বাংলা ও ইংরেজি')],
                ['word' => $t('Offline', 'অফলাইন'), 'label' => $t('keep working, it syncs later', 'কাজ চলবে, পরে মিলিয়ে নেবে')],
            ]]],
            ['ticker', ['source' => 'modules']],
            ['offer', [
                'tag' => $t('Year-end offer', 'বছর শেষের অফার'),
                'title' => $t('2 months free on every yearly plan', 'যেকোনো বাৎসরিক প্ল্যানে ২ মাস ফ্রি'),
                'text' => $t('Pay for ten months, use twelve. Ends on 31 December 2026.', 'দশ মাসের দাম দিন, বারো মাস চালান। ৩১ ডিসেম্বর ২০২৬ পর্যন্ত।'),
                'ends_at' => '2026-12-31T23:59',
                'button_label' => $t('See yearly prices', 'বাৎসরিক দাম দেখুন'), 'button_url' => '/pricing',
            ]],
            ['cards', [
                'eyebrow' => $t('Solutions', 'সমাধান'),
                'title' => $t('Ready for the way your sector works', 'আপনার সেক্টরের কাজের ধরনে প্রস্তুত'),
                'intro' => $t('Choose your sector and get the right modules, roles and settings from the start. Change anything later.', 'সেক্টর বেছে নিন, শুরু থেকেই পাবেন ঠিক মডিউল, ভূমিকা আর সেটিং। পরে যেকোনো কিছু বদলাতে পারবেন।'),
                'items' => [
                    ['icon' => 'school', 'color' => 'blue', 'title' => $t('Schools & colleges', 'স্কুল ও কলেজ'), 'text' => $t('Admission, fees, attendance, exams, results, report cards and a school website.', 'ভর্তি, বেতন, হাজিরা, পরীক্ষা, ফলাফল, রিপোর্ট কার্ড আর স্কুলের ওয়েবসাইট।')],
                    ['icon' => 'university', 'color' => 'green', 'title' => $t('Universities', 'বিশ্ববিদ্যালয়'), 'text' => $t('Course registration, credit-based GPA, period attendance and student portals.', 'কোর্স রেজিস্ট্রেশন, ক্রেডিটভিত্তিক জিপিএ, পিরিয়ডভিত্তিক হাজিরা আর শিক্ষার্থী পোর্টাল।')],
                    ['icon' => 'mosque', 'color' => 'red', 'title' => $t('Madrasas & coaching', 'মাদ্রাসা ও কোচিং'), 'text' => $t('Batches, monthly fees, guardian notices and results, made simple.', 'ব্যাচ, মাসিক বেতন, অভিভাবককে নোটিশ আর ফলাফল — সহজভাবে।')],
                    ['icon' => 'factory', 'color' => 'blue', 'title' => $t('Factories', 'কারখানা'), 'text' => $t('Workers, shifts, overtime, payroll, stock and production on the floor.', 'শ্রমিক, শিফট, ওভারটাইম, বেতন, স্টক আর উৎপাদন।')],
                    ['icon' => 'shop', 'color' => 'green', 'title' => $t('Retail & shops', 'দোকান ও খুচরা বিক্রি'), 'text' => $t('Point of sale, stock, customers, loyalty points and VAT-ready invoices.', 'পিওএস, স্টক, গ্রাহক, লয়্যালটি পয়েন্ট আর ভ্যাটসহ ইনভয়েস।')],
                    ['icon' => 'briefcase', 'color' => 'red', 'title' => $t('Any business', 'যেকোনো ব্যবসা'), 'text' => $t('HR, attendance, payroll, accounting, CRM and projects for growing teams.', 'বেড়ে ওঠা দলের জন্য এইচআর, হাজিরা, বেতন, হিসাব, সিআরএম আর প্রজেক্ট।')],
                    ['icon' => 'buildings', 'color' => 'blue', 'title' => $t('Groups of companies', 'গ্রুপ অব কোম্পানিজ'), 'text' => $t('Many companies and branches, one login, rules set once at the top.', 'অনেক কোম্পানি আর শাখা, এক লগইন; নিয়ম একবার উপরে ঠিক করলেই হয়।')],
                    ['icon' => 'person', 'color' => 'green', 'title' => $t('Freelancers', 'ফ্রিল্যান্সার'), 'text' => $t('Free accounts, customers and stock for one person. Grow into a team anytime.', 'একজনের জন্য ফ্রি হিসাব, গ্রাহক আর স্টক। যেকোনো সময় দলে বড় হন।')],
                ],
            ]],
            ['product', [
                'eyebrow' => $t('Product', 'প্রোডাক্ট'),
                'title' => $t('Every module you need. Turn on only what you use.', 'যত মডিউল দরকার সব আছে। চালু করুন শুধু যেটা লাগে।'),
                'intro' => $t('Pay for what you switch on. Modules share one set of people, accounts and permissions, so nothing is typed twice.', 'যা চালু করবেন, শুধু তার দাম। সব মডিউল একই কর্মী, হিসাব আর অনুমতি ব্যবহার করে, তাই কিছুই দুবার লিখতে হয় না।'),
                'style' => 'bento',
                'orbit_title' => $t('One core. Every module plugs in.', 'এক কেন্দ্র, সব মডিউল যুক্ত।'),
                'orbit_text' => $t(
                    'People, money, stock, students and customers share one set of records, so a salary posts to the books and a fee shows on the guardian\'s phone without anyone typing it twice.',
                    'কর্মী, টাকা, স্টক, শিক্ষার্থী আর গ্রাহক — সবার রেকর্ড এক জায়গায়। তাই বেতন নিজে থেকেই হিসাবে ওঠে, আর বেতন জমার খবর অভিভাবকের ফোনে যায়, কাউকে দুবার লিখতে হয় না।'
                ),
                'groups' => [
                    ['name' => $t('People', 'কর্মী'), 'title' => $t('Hire, pay and look after your team', 'নিয়োগ, বেতন আর দলের যত্ন'), 'text' => $t('From joining letter to final settlement, with attendance and leave in between.', 'যোগদানপত্র থেকে চূড়ান্ত নিষ্পত্তি — মাঝে হাজিরা আর ছুটি সবই।'), 'color' => 'blue', 'visual' => 'punch', 'modules' => ['hrm', 'attendance', 'leave', 'payroll', 'appraisals', 'recruitment', 'letters']],
                    ['name' => $t('Finance', 'হিসাব'), 'title' => $t('Books that balance themselves', 'হিসাব নিজেই মেলে'), 'text' => $t('Every fee, sale and salary posts to the ledger on its own.', 'প্রতিটি বেতন, বিক্রি আর আদায় নিজে থেকেই খতিয়ানে ওঠে।'), 'color' => 'green', 'visual' => 'line', 'modules' => ['accounting', 'expenses', 'online_payments', 'multi_currency']],
                    ['name' => $t('Education', 'শিক্ষা'), 'title' => $t('Admission to result card', 'ভর্তি থেকে রিপোর্ট কার্ড'), 'color' => 'violet', 'visual' => 'grade', 'modules' => ['education', 'education_fees', 'student_attendance', 'education_exams', 'education_lectures', 'edu_website', 'course_registration']],
                    ['name' => $t('Operations', 'অপারেশন'), 'title' => $t('Stock you can trust', 'নির্ভরযোগ্য স্টক'), 'color' => 'red', 'visual' => 'ring', 'modules' => ['inventory', 'pos', 'factory_erp', 'fleet', 'projects', 'energy_monitoring']],
                    ['name' => $t('Customers', 'গ্রাহক'), 'title' => $t('Every lead, every deal, one view', 'প্রতিটি লিড আর ডিল এক নজরে'), 'color' => 'sky', 'visual' => 'pipe', 'modules' => ['crm', 'client_portal', 'business_network']],
                    ['name' => $t('Platform', 'প্ল্যাটফর্ম'), 'title' => $t('Works offline. Syncs when you are back.', 'অফলাইনে চলে, অনলাইনে ফিরলে মিলে যায়।'), 'color' => 'amber', 'visual' => 'sync', 'modules' => ['offline_mode', 'ai_assistant', 'custom_reports', 'api_integration', 'advanced_audit', 'compliance']],
                ],
            ]],
            ['features', [
                'eyebrow' => $t('Built for real work', 'আসল কাজের জন্য তৈরি'),
                'title' => $t('Works where your team works', 'আপনার দল যেখানে, সেখানেই চলে'),
                'items' => [
                    ['icon' => 'bolt', 'color' => 'blue', 'title' => $t('Offline first', 'অফলাইনেও চলে'), 'text' => $t('Take attendance and sell at the counter without internet. Everything syncs safely when you are back online.', 'ইন্টারনেট ছাড়াই হাজিরা নিন আর কাউন্টারে বিক্রি করুন। অনলাইনে ফিরলে সব নিরাপদে মিলে যায়।')],
                    ['icon' => 'phone', 'color' => 'green', 'title' => $t('Light on any phone', 'যেকোনো ফোনে হালকা'), 'text' => $t('Small pages that open fast on a slow mobile network.', 'ছোট পেজ, ধীর মোবাইল নেটওয়ার্কেও দ্রুত খোলে।')],
                    ['icon' => 'lock', 'color' => 'red', 'title' => $t('Security first', 'নিরাপত্তা আগে'), 'text' => $t('Each client\'s data kept apart, two-step sign-in with passkeys, and a full audit trail.', 'প্রত্যেক গ্রাহকের তথ্য আলাদা, পাসকিসহ দুই ধাপে সাইন ইন, আর প্রতিটি পরিবর্তনের রেকর্ড।')],
                    ['icon' => 'landmark', 'color' => 'violet', 'title' => $t('Local rules built in', 'দেশের নিয়ম আগে থেকেই'), 'text' => $t('Bangladesh VAT, holidays and payroll rules out of the box. Other countries are set up as data, not code.', 'বাংলাদেশের ভ্যাট, ছুটি আর বেতনের নিয়ম আগে থেকেই আছে। অন্য দেশের নিয়মও যোগ হয় তথ্য হিসেবে।')],
                ],
                'panel_title' => $t('Portals for everyone you serve', 'যাদের সেবা দেন, সবার জন্য পোর্টাল'),
                'panel' => [
                    ['label' => $t('Guardians', 'অভিভাবক'), 'value' => $t('results · fees · attendance', 'ফলাফল · বেতন · হাজিরা')],
                    ['label' => $t('Employees', 'কর্মী'), 'value' => $t('payslips · leave · punch in', 'পে-স্লিপ · ছুটি · হাজিরা')],
                    ['label' => $t('Customers', 'গ্রাহক'), 'value' => $t('invoices · pay online', 'ইনভয়েস · অনলাইনে পরিশোধ')],
                    ['label' => $t('Students', 'শিক্ষার্থী'), 'value' => $t('lectures · results', 'লেকচার · ফলাফল')],
                ],
            ]],
            ['partner', $this->partnerBand()],
            ['pricing', $this->pricing()],
            ['gallery', [
                'eyebrow' => $t('Gallery', 'গ্যালারি'),
                'title' => $t('See it in action', 'কাজ করতে দেখুন'),
                'intro' => $t('Screens from the software, in English and Bangla.', 'সফটওয়্যারের স্ক্রিন, ইংরেজি আর বাংলায়।'),
                'items' => [
                    ['caption' => $t('Payroll · salary sheet', 'বেতন · স্যালারি শিট'), 'color' => 'blue'],
                    ['caption' => $t('Attendance on a phone', 'ফোনে হাজিরা'), 'color' => 'green'],
                    ['caption' => $t('Report card in Bangla', 'বাংলায় রিপোর্ট কার্ড'), 'color' => 'violet'],
                    ['caption' => $t('Point of sale', 'পিওএস'), 'color' => 'red'],
                    ['caption' => $t('Accounts dashboard', 'হিসাবের ড্যাশবোর্ড'), 'color' => 'navy'],
                ],
            ]],
            ['news', [
                'eyebrow' => $t('News & notices', 'খবর ও নোটিশ'),
                'title' => $t('What\'s new', 'নতুন কী এল'),
                'items' => [
                    ['date' => '2026-11-02', 'title' => $t('Payslips and report cards in Bangla', 'বাংলায় পে-স্লিপ আর রিপোর্ট কার্ড'), 'text' => $t('Print every document in Bangla or English, chosen per person.', 'প্রতিটি কাগজ বাংলা বা ইংরেজিতে প্রিন্ট করুন, যার যেটা দরকার।')],
                    ['date' => '2026-10-28', 'title' => $t('Increment arrears, paid automatically', 'বকেয়া ইনক্রিমেন্ট নিজে থেকেই পরিশোধ'), 'text' => $t('Back-dated increments are added to the next salary, even for people who left.', 'পেছনের তারিখের ইনক্রিমেন্ট পরের বেতনে যোগ হয়, যারা চলে গেছেন তাদের জন্যও।')],
                    ['date' => '2026-10-20', 'title' => $t('The add-on store is open', 'অ্যাড-অন স্টোর চালু'), 'text' => $t('Switch on just the module you need and pay only for it.', 'শুধু দরকারি মডিউলটি চালু করুন, দাম দিন শুধু সেটার।')],
                ],
            ]],
            ['faq', $this->faq()],
            ['cta', $this->cta()],
        ];
    }

    private function partnerBand(): array
    {
        $t = self::t(...);

        return [
            'eyebrow' => $t('Partner program', 'পার্টনার প্রোগ্রাম'),
            'title' => $t('Sell it as your own brand', 'নিজের ব্র্যান্ডে বিক্রি করুন'),
            'text' => $t(
                'Resellers, agencies and consultants can offer One Solutions under their own name, logo, colours, e-mails and domain, and set their own prices.',
                'রিসেলার, এজেন্সি আর কনসালট্যান্টরা নিজের নাম, লোগো, রং, ই-মেইল আর ডোমেইনে ওয়ান সলিউশনস দিতে পারেন, দামও ঠিক করবেন নিজে।'
            ),
            'button_label' => $t('Become a partner', 'পার্টনার হোন'), 'button_url' => '/contact',
            'steps' => [
                $t('Add your logo, colours and domain', 'আপনার লোগো, রং আর ডোমেইন দিন'),
                $t('Create plans and prices for your clients', 'গ্রাহকের জন্য প্ল্যান আর দাম ঠিক করুন'),
                $t('Onboard clients; we run the servers', 'গ্রাহক যোগ করুন; সার্ভার আমরা চালাই'),
            ],
        ];
    }

    private function pricing(): array
    {
        $t = self::t(...);

        return [
            'eyebrow' => $t('Pricing', 'মূল্য'),
            'title' => $t('Simple prices that grow with you', 'সহজ দাম, আপনার সাথে বাড়ে'),
            'intro' => $t('Start free. Upgrade when you are ready. Add single modules from the add-on store.', 'ফ্রি শুরু করুন। প্রস্তুত হলে আপগ্রেড করুন। দরকারে শুধু একটি মডিউল আলাদা নিন।'),
            'plans' => ['personal_free', 'starter', 'business', 'enterprise'],
            'highlight' => 'business',
            'currency' => 'BDT',
            'note' => $t('Prices in BDT, VAT extra where it applies.', 'দাম টাকায়, প্রযোজ্য ক্ষেত্রে ভ্যাট আলাদা।'),
            'features' => [
                'personal_free' => [$t('Accounting', 'হিসাব'), $t('Customers', 'গ্রাহক'), $t('Stock', 'স্টক'), $t('Bangla & English', 'বাংলা ও ইংরেজি')],
                'starter' => [$t('School or business ready', 'স্কুল বা ব্যবসার জন্য প্রস্তুত'), $t('People, attendance and payroll', 'কর্মী, হাজিরা আর বেতন'), $t('Offline mode', 'অফলাইন মোড'), $t('Online payments', 'অনলাইন পেমেন্ট')],
                'business' => [$t('Every module', 'সব মডিউল'), $t('More branches and users', 'আরও শাখা ও ব্যবহারকারী'), $t('AI assistant', 'এআই সহকারী'), $t('Custom reports', 'নিজের রিপোর্ট')],
                'enterprise' => [$t('Groups of companies', 'গ্রুপ অব কোম্পানিজ'), $t('No fixed limits', 'নির্দিষ্ট সীমা নেই'), $t('Own database option', 'আলাদা ডাটাবেস'), $t('Your own domain', 'নিজের ডোমেইন')],
            ],
        ];
    }

    private function faq(): array
    {
        $t = self::t(...);

        return [
            'eyebrow' => $t('FAQ', 'প্রশ্নোত্তর'),
            'title' => $t('Questions people ask', 'সবাই যা জানতে চান'),
            'items' => [
                ['question' => $t('Can I use it in Bangla?', 'বাংলায় ব্যবহার করা যাবে?'), 'answer' => $t('Yes. Every screen works in Bangla and English, and each person picks their own language. Names and labels can be kept in both.', 'হ্যাঁ। প্রতিটি স্ক্রিন বাংলা ও ইংরেজিতে চলে, আর প্রত্যেকে নিজের ভাষা বেছে নিতে পারেন। নাম আর লেবেল দুই ভাষাতেই রাখা যায়।')],
                ['question' => $t('What happens when the internet goes down?', 'ইন্টারনেট চলে গেলে কী হবে?'), 'answer' => $t('Attendance, sales and other daily work keep going on the device. Changes are saved encrypted and sent to the server when you are back online. Nothing is lost or counted twice.', 'হাজিরা, বিক্রি আর প্রতিদিনের কাজ ডিভাইসেই চলতে থাকে। পরিবর্তনগুলো এনক্রিপ্ট করে রাখা হয়, অনলাইনে ফিরলে সার্ভারে যায়। কিছু হারায় না, দুবারও গোনা হয় না।')],
                ['question' => $t('Is my data safe and private?', 'আমার তথ্য কি নিরাপদ?'), 'answer' => $t('Each client\'s data is kept apart from every other client\'s. Sign-in can use two steps or passkeys. Every important change is written in an audit log, and backups are encrypted.', 'প্রত্যেক গ্রাহকের তথ্য অন্যদের থেকে আলাদা রাখা হয়। সাইন ইনে দুই ধাপ বা পাসকি ব্যবহার করা যায়। প্রতিটি গুরুত্বপূর্ণ পরিবর্তনের রেকর্ড থাকে, আর ব্যাকআপ এনক্রিপ্ট করা।')],
                ['question' => $t('Can I move from my old software or Excel?', 'পুরনো সফটওয়্যার বা এক্সেল থেকে সরানো যাবে?'), 'answer' => $t('Yes. Import employees, students, customers and opening balances from a spreadsheet.', 'হ্যাঁ। কর্মী, শিক্ষার্থী, গ্রাহক আর শুরুর ব্যালেন্স স্প্রেডশিট থেকে আনা যায়।')],
                ['question' => $t('Can parents, students and employees log in?', 'অভিভাবক, শিক্ষার্থী আর কর্মীরা কি লগইন করতে পারবে?'), 'answer' => $t('Yes. Each gets a simple portal and sees only their own records: results, fees, payslips, leave and more.', 'হ্যাঁ। প্রত্যেকে একটি সহজ পোর্টাল পায় আর শুধু নিজের তথ্য দেখে: ফলাফল, বেতন, পে-স্লিপ, ছুটি ইত্যাদি।')],
            ],
        ];
    }

    private function cta(): array
    {
        $t = self::t(...);

        return [
            'title' => $t('Run everything from one place', 'সব কিছু চালান এক জায়গা থেকে'),
            'text' => $t('Create your free account in two minutes. No card, no setup fee.', 'দুই মিনিটে ফ্রি অ্যাকাউন্ট খুলুন। কার্ড লাগবে না, সেটআপ ফিও নেই।'),
            'primary_label' => $t('Start free', 'ফ্রি শুরু করুন'), 'primary_url' => 'app:/signup',
            'secondary_label' => $t('Book a demo', 'ডেমো দেখুন'), 'secondary_url' => '/contact',
        ];
    }

    /** A partner's short start: its own product name, no claims about prices or numbers. */
    private function partner(string $name): array
    {
        $t = self::t(...);

        return [
            'template' => 'corporate',
            'locales' => ['en', 'bn'],
            'default_locale' => 'en',
            'settings' => [
                'header' => [
                    'menu' => [
                        ['label' => $t('Solutions', 'সমাধান'), 'url' => '/#solutions'],
                        ['label' => $t('Contact', 'যোগাযোগ'), 'url' => '/#contact'],
                    ],
                    'cta_label' => $t('Sign in', 'সাইন ইন'), 'cta_url' => 'app:/login',
                ],
                'seo' => ['title' => $t($name, $name)],
            ],
            'pages' => [
                ['slug' => '', 'title' => $t('Home', 'হোম'), 'in_menu' => false, 'blocks' => [
                    ['hero_slider', ['effect' => 'fade', 'seconds' => 7, 'autoplay' => false, 'slides' => [[
                        'title' => $t("{$name}:", "{$name}:"),
                        'highlight' => $t('your business in one place', 'আপনার ব্যবসা এক জায়গায়'),
                        'text' => $t('People, attendance, payroll, accounts and stock, in Bangla and English.', 'কর্মী, হাজিরা, বেতন, হিসাব আর স্টক — বাংলা ও ইংরেজিতে।'),
                        'primary_label' => $t('Sign in', 'সাইন ইন'), 'primary_url' => 'app:/login',
                        'visual' => 'dashboard',
                    ]]]],
                    ['cards', [
                        'title' => $t('What we offer', 'আমরা যা দিই'),
                        'items' => [
                            ['icon' => 'users', 'color' => 'blue', 'title' => $t('People', 'কর্মী'), 'text' => $t('Employees, attendance, leave and payroll.', 'কর্মী, হাজিরা, ছুটি আর বেতন।')],
                            ['icon' => 'chart', 'color' => 'green', 'title' => $t('Finance', 'হিসাব'), 'text' => $t('Accounts, invoices and reports.', 'হিসাব, ইনভয়েস আর রিপোর্ট।')],
                            ['icon' => 'box', 'color' => 'red', 'title' => $t('Stock', 'স্টক'), 'text' => $t('Items, stores and sales.', 'পণ্য, গুদাম আর বিক্রি।')],
                        ],
                    ]],
                    ['cta', [
                        'title' => $t('Talk to us', 'আমাদের সাথে কথা বলুন'),
                        'primary_label' => $t('Sign in', 'সাইন ইন'), 'primary_url' => 'app:/login',
                    ]],
                ]],
            ],
        ];
    }
}
