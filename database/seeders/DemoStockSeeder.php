<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Packaging\Actions\ApplySectorPackage;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
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
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Accounting\Models\TaxCode;
use Modules\Accounting\Services\Books;
use Modules\Accounting\Services\ChartOfAccounts;
use Modules\Accounting\Services\Parties;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\Warehouse;
use Modules\Inventory\Services\Catalog;
use Modules\Inventory\Services\Counts;
use Modules\Inventory\Services\Documents;
use Modules\Inventory\Services\Inventories;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\SaleLine;
use Modules\Pos\Models\Session;
use Modules\Pos\Services\Counters;
use Modules\Pos\Services\Pricing;
use Modules\Pos\Services\Sales;
use Modules\Pos\Services\Sessions;
use Modules\Pos\Services\Tills;
use Throwable;

/**
 * Local-only demo shop for Inventory and POS (INV/POS-3): "Demo Super Shop",
 * a retail company in the demo group with two branches, books on the retail
 * chart, about thirty items (Bangla and English names, barcodes, VAT,
 * batches with expiry), stock received from suppliers (two bills made, one
 * left to bill), transfers to the branches (one still on the way), an
 * adjustment waiting for approval, an open stock count, three counters with
 * two weeks of shifts, sales and returns (one cash difference to review) and
 * a shift open today.
 *
 * Everything goes through the modules' own services, so stock, books, audit
 * and numbers are what the screens would make. It only adds: it stops when
 * the shop exists. Logins (local only): shop.owner@, store@, cashier@ and
 * supervisor@demo.test, password self::PASSWORD.
 */
class DemoStockSeeder extends Seeder
{
    public const PASSWORD = 'One@2002';

    private const SHOP = 'Demo Super Shop';

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
            $this->command?->warn('Demo shop skipped: run the demo hierarchy first.');

            return;
        }
        if (Organization::query()->where('parent_id', $group->id)->get()->contains(fn (Organization $org) => $org->name === self::SHOP)) {
            $this->command?->warn('Demo shop skipped: it is there already.');

            return;
        }

        mt_srand(20261006);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        try {
            Carbon::setTestNow($today->subDays(21)->setTime(4, 0));
            [$branches, $warehouses] = $this->shop($group);
            $items = $this->stock($branches, $warehouses, $today);
            $this->counters($branches, $warehouses, $items, $today);
        } finally {
            Carbon::setTestNow();
            app(CurrentContext::class)->clear();
            app(AccessResolver::class)->forget();
        }

        foreach ($this->people as $email => $person) {
            $this->command?->info("Demo login: {$email} / ".self::PASSWORD);
        }
    }

    /**
     * The company, its branches, people, books and warehouses.
     *
     * @return array{0: array<string, Organization>, 1: array<string, array<string, mixed>>}
     */
    private function shop(Organization $group): array
    {
        $create = app(CreateOrganization::class);
        $this->company = $create->handle(OrganizationType::Company, [
            'name' => ['en' => self::SHOP, 'bn' => 'ডেমো সুপার শপ'],
            'sector_key' => 'retail',
        ], parent: $group);
        $branches = [
            'DHN' => $create->handle(OrganizationType::Branch, ['name' => ['en' => 'Dhanmondi', 'bn' => 'ধানমন্ডি']], parent: $this->company),
            'UTR' => $create->handle(OrganizationType::Branch, ['name' => ['en' => 'Uttara', 'bn' => 'উত্তরা']], parent: $this->company),
        ];

        // The retail package: modules, settings and the shop's roles.
        app(ApplySectorPackage::class)->handle($this->company->fresh());
        foreach (['accounting', 'inventory', 'pos'] as $module) {
            app(ModuleToggleService::class)->enable($this->company, $module, 'Demo data');
        }

        $owner = $this->person('Shop Owner', 'shop.owner@demo.test');
        app(AddMember::class)->handle($this->company, $owner, MembershipType::Owner);
        app(ContextResolver::class)->enterOrganization($owner, $this->company->id);
        $this->staff($this->company, $this->person('Rafiq Store Keeper', 'store@demo.test'), ['store_keeper', 'accountant'], $owner);
        $this->staff($this->company, $this->person('Shathi Cashier', 'cashier@demo.test'), ['cashier'], $owner);
        $this->staff($this->company, $this->person('Kamal Supervisor', 'supervisor@demo.test'), ['shop_supervisor', 'finance_approver'], $owner);

        // Books on the retail chart from the start of the fiscal year (July in Bangladesh).
        app(ChartOfAccounts::class)->setUp($this->company, 'retail', CarbonImmutable::now()->month >= 7 ? CarbonImmutable::now()->format('Y').'-07-01' : CarbonImmutable::now()->subYear()->format('Y').'-07-01', $owner);
        // Adjustments above 1,000 taka wait for someone else to approve.
        app(RuleService::class)->set(app(RuleTargets::class)->organization($this->company), 'inventory.adjustment_approval_above', RuleMode::Set,
            ['amount' => 100000, 'currency' => 'BDT'], 'Demo data', trusted: true);

        $catalog = app(Catalog::class);
        $keeper = $this->people['store@demo.test'];
        $warehouses = [];
        foreach ([
            'CENTRAL' => [['en' => 'Central warehouse', 'bn' => 'কেন্দ্রীয় গুদাম'], $this->company->id],
            'DHN' => [['en' => 'Dhanmondi shop floor', 'bn' => 'ধানমন্ডি দোকান'], $branches['DHN']->id],
            'UTR' => [['en' => 'Uttara shop floor', 'bn' => 'উত্তরা দোকান'], $branches['UTR']->id],
        ] as $code => [$name, $unit]) {
            $warehouses[$code] = $catalog->create($this->company, 'warehouse', ['code' => $code, 'name' => $name, 'unit_id' => $unit], $keeper)->toArray();
        }

        return [$branches, $warehouses];
    }

    /**
     * Items, suppliers, receipts (two billed), transfers, an adjustment
     * waiting for approval and an open count.
     *
     * @param  array<string, Organization>  $branches
     * @param  array<string, array<string, mixed>>  $warehouses
     * @return array<string, Item>
     */
    private function stock(array $branches, array $warehouses, CarbonImmutable $today): array
    {
        $catalog = app(Catalog::class);
        $keeper = $this->people['store@demo.test'];
        $unit = fn (string $code, array $name, int $decimals) => $catalog->create($this->company, 'unit', ['code' => $code, 'name' => $name, 'decimals' => $decimals], $keeper);
        $units = [
            'PCS' => $unit('PCS', ['en' => 'Pieces', 'bn' => 'পিস'], 0),
            'KG' => $unit('KG', ['en' => 'Kilogram', 'bn' => 'কেজি'], 3),
            'PACK' => $unit('PACK', ['en' => 'Pack', 'bn' => 'প্যাকেট'], 0),
        ];
        $categories = [];
        foreach ([
            'GROC' => ['en' => 'Groceries', 'bn' => 'মুদি'], 'DAIRY' => ['en' => 'Dairy', 'bn' => 'দুগ্ধজাত'], 'CARE' => ['en' => 'Personal care', 'bn' => 'ব্যক্তিগত যত্ন'],
            'SNACK' => ['en' => 'Snacks', 'bn' => 'নাস্তা'], 'BEV' => ['en' => 'Beverages', 'bn' => 'পানীয়'],
        ] as $code => $name) {
            $categories[$code] = $catalog->create($this->company, 'category', ['code' => $code, 'name' => $name], $keeper)->id;
        }
        $vat = app(Books::class)->query(TaxCode::class, $this->company)->pluck('id', 'code');

        // [sku, en, bn, unit, category, cost, price, VAT code, batches, reorder level]
        $rows = [
            ['RICE-MINI', 'Miniket rice', 'মিনিকেট চাল', 'KG', 'GROC', 6800, 7800, null, false, 40000],
            ['RICE-NAZ', 'Nazirshail rice', 'নাজিরশাইল চাল', 'KG', 'GROC', 7500, 8600, null, false, 30000],
            ['DAL-MSR', 'Red lentils', 'মসুর ডাল', 'KG', 'GROC', 11000, 13000, null, false, 20000],
            ['OIL-SOY1', 'Soybean oil 1 L', 'সয়াবিন তেল ১ লিটার', 'PCS', 'GROC', 17500, 19000, 'VAT5', false, 24000],
            ['SUGAR', 'Sugar', 'চিনি', 'KG', 'GROC', 12000, 13500, null, false, 20000],
            ['SALT-1', 'Iodised salt 1 kg', 'আয়োডিনযুক্ত লবণ ১ কেজি', 'PCS', 'GROC', 3800, 4500, null, false, 20000],
            ['ATTA-2', 'Atta flour 2 kg', 'আটা ২ কেজি', 'PCS', 'GROC', 11500, 13000, null, false, 15000],
            ['ONION', 'Onion', 'পেঁয়াজ', 'KG', 'GROC', 9000, 11000, null, false, 25000],
            ['POTATO', 'Potato', 'আলু', 'KG', 'GROC', 4500, 5500, null, false, 30000],
            ['EGG-12', 'Eggs, one dozen', 'ডিম, এক ডজন', 'PCS', 'GROC', 13000, 15000, null, false, 15000],
            ['MILK-P500', 'Milk powder 500 g', 'গুঁড়া দুধ ৫০০ গ্রাম', 'PCS', 'DAIRY', 36000, 42000, 'VAT5', true, 10000],
            ['MILK-L1', 'Liquid milk 1 L', 'তরল দুধ ১ লিটার', 'PCS', 'DAIRY', 8500, 10000, null, true, 20000],
            ['YOGURT', 'Sweet yogurt 500 g', 'মিষ্টি দই ৫০০ গ্রাম', 'PCS', 'DAIRY', 9000, 11000, null, true, 10000],
            ['BUTTER', 'Butter 200 g', 'মাখন ২০০ গ্রাম', 'PCS', 'DAIRY', 22000, 26000, 'VAT5', true, 6000],
            ['SOAP-100', 'Bath soap 100 g', 'গোসলের সাবান ১০০ গ্রাম', 'PCS', 'CARE', 4500, 5500, 'VAT15', false, 30000],
            ['SHAMPOO', 'Shampoo 180 ml', 'শ্যাম্পু ১৮০ মিলি', 'PCS', 'CARE', 22000, 26000, 'VAT15', false, 10000],
            ['PASTE-100', 'Toothpaste 100 g', 'টুথপেস্ট ১০০ গ্রাম', 'PCS', 'CARE', 9500, 11500, 'VAT15', false, 15000],
            ['DETERG-1', 'Detergent powder 1 kg', 'ডিটারজেন্ট পাউডার ১ কেজি', 'PCS', 'CARE', 15000, 18000, 'VAT15', false, 12000],
            ['HANDWASH', 'Hand wash 250 ml', 'হ্যান্ড ওয়াশ ২৫০ মিলি', 'PCS', 'CARE', 12000, 14500, 'VAT15', false, 10000],
            ['BISCUIT', 'Biscuits', 'বিস্কুট', 'PACK', 'SNACK', 2500, 3000, 'VAT5', false, 40000],
            ['CHANACHUR', 'Chanachur 250 g', 'চানাচুর ২৫০ গ্রাম', 'PACK', 'SNACK', 5500, 6500, 'VAT5', false, 20000],
            ['NOODLES-8', 'Instant noodles, 8 pack', 'নুডলস, ৮ প্যাক', 'PACK', 'SNACK', 15000, 18000, 'VAT5', false, 12000],
            ['CHIPS', 'Potato chips', 'আলুর চিপস', 'PACK', 'SNACK', 2000, 2500, 'VAT5', false, 30000],
            ['CHOCO', 'Chocolate bar', 'চকলেট বার', 'PCS', 'SNACK', 4500, 5500, 'VAT15', false, 20000],
            ['WATER-15', 'Drinking water 1.5 L', 'খাবার পানি ১.৫ লিটার', 'PCS', 'BEV', 2500, 3000, 'VAT15', false, 40000],
            ['SODA-1', 'Soft drink 1 L', 'কোমল পানীয় ১ লিটার', 'PCS', 'BEV', 6500, 7500, 'VAT15', false, 20000],
            ['TEA-400', 'Tea leaves 400 g', 'চা পাতা ৪০০ গ্রাম', 'PACK', 'BEV', 21000, 24500, 'VAT5', false, 8000],
            ['COFFEE-50', 'Instant coffee 50 g', 'ইনস্ট্যান্ট কফি ৫০ গ্রাম', 'PCS', 'BEV', 26000, 30000, 'VAT15', false, 5000],
            ['JUICE-1', 'Mango juice 1 L', 'আমের জুস ১ লিটার', 'PCS', 'BEV', 11000, 13000, 'VAT15', false, 12000],
        ];
        $items = [];
        $costs = [];
        foreach ($rows as $index => [$sku, $en, $bn, $unitCode, $category, $cost, $price, $code, $batches, $reorder]) {
            $items[$sku] = $catalog->create($this->company, 'item', [
                'sku' => $sku, 'barcode' => self::ean13('8941'.str_pad((string) (2026000 + $index), 8, '0', STR_PAD_LEFT)),
                'name' => ['en' => $en, 'bn' => $bn], 'unit_id' => $units[$unitCode]->id, 'category_id' => $categories[$category],
                'kind' => 'stock', 'track_batches' => $batches, 'sale_price_minor' => $price, 'tax_code_id' => $code === null ? null : ($vat[$code] ?? null),
                'reorder_level_milli' => $reorder, 'reorder_quantity_milli' => $reorder * 3,
            ], $keeper);
            $costs[$sku] = $cost;
        }
        $items['BAG'] = $catalog->create($this->company, 'item', ['sku' => 'BAG', 'name' => ['en' => 'Carry bag', 'bn' => 'ক্যারি ব্যাগ'], 'unit_id' => $units['PCS']->id,
            'kind' => 'non_stock', 'sale_price_minor' => 500], $keeper);

        // Suppliers in the books.
        $parties = app(Parties::class);
        $suppliers = [];
        foreach (['Meghna Traders' => 'GROC', 'Padma Dairy Distributors' => 'DAIRY', 'Dhaka Consumer Goods Ltd' => 'OTHER'] as $name => $key) {
            $suppliers[$key] = ['name' => $name, 'id' => $parties->create($this->company, ['name' => $name, 'is_vendor' => true, 'payment_terms_days' => 30], $keeper)->getKey()];
        }

        $documents = app(Documents::class);
        // Whole kilograms, pieces or packs, in thousandths.
        $quantity = fn (string $sku, int $pieces) => $pieces * 1000;
        $receive = function (string $supplier, string $reference, array $skus, int $pieces, CarbonImmutable $on, bool $bill) use ($documents, $items, $costs, $suppliers, $warehouses, $keeper, $quantity, $rows) {
            $batched = array_column($rows, 8, 0);
            $lines = [];
            foreach ($skus as $sku) {
                $line = ['item_id' => $items[$sku]->id, 'quantity_milli' => $quantity($sku, $pieces), 'unit_cost_minor' => $costs[$sku]];
                if ($batched[$sku]) {
                    // Two batches: one going off within the month (the expiry list), one later.
                    $lines[] = [...$line, 'quantity_milli' => $quantity($sku, intdiv($pieces, 3)), 'batch_number' => $sku.'-'.$on->format('md').'A', 'expires_on' => $on->addDays(30)->toDateString()];
                    $line = [...$line, 'quantity_milli' => $quantity($sku, $pieces - intdiv($pieces, 3)), 'batch_number' => $sku.'-'.$on->format('md').'B', 'expires_on' => $on->addDays(150)->toDateString()];
                }
                $lines[] = $line;
            }
            $receipt = $documents->create($this->company, ['type' => 'receipt', 'warehouse_id' => $warehouses['CENTRAL']['id'], 'document_date' => $on->toDateString(),
                'counterparty' => $suppliers[$supplier]['name'], 'reference' => $reference, 'lines' => $lines], $keeper);
            $receipt = $documents->post($this->company, $receipt, $receipt->version, $keeper);
            if ($bill) {
                $documents->bill($this->company, $receipt, $receipt->version, $suppliers[$supplier]['id'], null, $keeper);
            }
        };
        $groups = [];
        foreach ($rows as $row) {
            $groups[in_array($row[4], ['GROC', 'DAIRY'], true) ? $row[4] : 'OTHER'][] = $row[0];
        }

        $day = $today->subDays(21);
        Carbon::setTestNow($day->setTime(4, 0));
        $receive('GROC', 'MT-5521', $groups['GROC'], 180, $day, true);
        $receive('DAIRY', 'PD-0917', $groups['DAIRY'], 90, $day, true);
        $receive('OTHER', 'DCG-33810', $groups['OTHER'], 150, $day, false);

        // Half to each shop floor, the rest kept in the central warehouse.
        $move = function (string $to, array $skus, int $pieces, CarbonImmutable $on, bool $arrive) use ($documents, $items, $warehouses, $keeper, $quantity) {
            $transfer = $documents->create($this->company, ['type' => 'transfer', 'warehouse_id' => $warehouses['CENTRAL']['id'], 'to_warehouse_id' => $warehouses[$to]['id'],
                'document_date' => $on->toDateString(), 'lines' => array_map(fn ($sku) => ['item_id' => $items[$sku]->id, 'quantity_milli' => $quantity($sku, $pieces)], $skus)], $keeper);
            $transfer = $documents->dispatch($this->company, $transfer, $transfer->version, $keeper);
            if ($arrive) {
                $documents->receive($this->company, $transfer, $transfer->version, [], $keeper);
            }
        };
        $all = array_column($rows, 0);
        Carbon::setTestNow($today->subDays(20)->setTime(4, 0));
        $move('DHN', $all, 45, $today->subDays(20), true);
        $move('UTR', $all, 40, $today->subDays(20), true);

        // A top-up of groceries ten days ago, billed later (left to bill).
        Carbon::setTestNow($today->subDays(10)->setTime(4, 0));
        $receive('GROC', 'MT-5604', $groups['GROC'], 120, $today->subDays(10), false);
        $move('DHN', $groups['GROC'], 30, $today->subDays(10), true);

        // Broken eggs at Dhanmondi (small, posted at once) and water damage at Uttara (large, waits for approval).
        Carbon::setTestNow($today->subDays(3)->setTime(5, 0));
        $small = $documents->create($this->company, ['type' => 'adjustment', 'warehouse_id' => $warehouses['DHN']['id'], 'document_date' => $today->subDays(3)->toDateString(),
            'reason' => 'Eggs broken while shelving', 'lines' => [['item_id' => $items['EGG-12']->id, 'quantity_milli' => -2000]]], $keeper);
        $documents->post($this->company, $small, $small->version, $keeper);
        Carbon::setTestNow($today->subDays(1)->setTime(5, 0));
        $large = $documents->create($this->company, ['type' => 'adjustment', 'warehouse_id' => $warehouses['UTR']['id'], 'document_date' => $today->subDays(1)->toDateString(),
            'reason' => 'Rain water leaked into the rice sacks', 'lines' => [['item_id' => $items['RICE-NAZ']->id, 'quantity_milli' => -12500], ['item_id' => $items['RICE-MINI']->id, 'quantity_milli' => -8000]]], $keeper);
        $documents->post($this->company, $large, $large->version, $keeper);
        // On the way to Uttara, and a count opened at the central warehouse.
        $move('UTR', $groups['OTHER'], 20, $today->subDays(1), false);
        Carbon::setTestNow($today->setTime(3, 0));
        app(Counts::class)->open($this->company, app(Inventories::class)->query(Warehouse::class, $this->company)->findOrFail($warehouses['CENTRAL']['id']), $categories['CARE'], $today, $keeper);

        return $items;
    }

    /**
     * Three counters with two weeks of shifts, sales, a few returns, one
     * cash difference waiting for the supervisor, and a shift open today.
     *
     * @param  array<string, Organization>  $branches
     * @param  array<string, array<string, mixed>>  $warehouses
     * @param  array<string, Item>  $items
     */
    private function counters(array $branches, array $warehouses, array $items, CarbonImmutable $today): void
    {
        $owner = $this->people['shop.owner@demo.test'];
        $cashier = $this->people['cashier@demo.test'];
        $supervisor = $this->people['supervisor@demo.test'];
        $counters = app(Counters::class);
        $registers = [];
        foreach ([
            'DHN1' => [['en' => 'Dhanmondi counter 1', 'bn' => 'ধানমন্ডি কাউন্টার ১'], 'DHN'],
            'DHN2' => [['en' => 'Dhanmondi counter 2', 'bn' => 'ধানমন্ডি কাউন্টার ২'], 'DHN'],
            'UTR1' => [['en' => 'Uttara counter', 'bn' => 'উত্তরা কাউন্টার'], 'UTR'],
        ] as $code => [$name, $branch]) {
            $registers[$code] = $counters->save($this->company, null, null, ['code' => $code, 'name' => $name, 'unit_id' => $branches[$branch]->id,
                'warehouse_id' => $warehouses[$branch]['id'], 'payment_methods' => ['cash', 'card', 'mobile']], $owner);
        }

        $includeTax = (bool) app(RuleResolver::class)->get('pos.prices_include_tax', app(RuleContextFactory::class)->forOrganization($this->company));
        $sessions = app(Sessions::class);
        $customers = ['Nasrin Akter', 'Rahim Uddin', 'Farzana Haque', 'Tanvir Ahmed', 'Sumaiya Islam', null, null, null, null];
        for ($ago = 14; $ago >= 0; $ago--) {
            $day = $today->subDays($ago);
            foreach ($registers as $code => $register) {
                if ($ago === 0 && $code !== 'DHN1') {
                    continue;
                }
                Carbon::setTestNow($day->setTime(3, mt_rand(0, 30)));
                $session = $sessions->open($this->company, $register, 300000, $cashier);
                $catalogue = collect($counters->catalogue($this->company, $register)['items'])->keyBy('id');
                $sold = [];
                $count = $ago === 0 ? 6 : mt_rand(7, 14);
                for ($n = 0; $n < $count; $n++) {
                    Carbon::setTestNow($day->setTime(4 + intdiv($n * 10, $count), mt_rand(0, 59)));
                    $sale = $this->sell($register, $catalogue, $customers, $includeTax, $cashier);
                    if ($sale !== null) {
                        $sold[] = $sale;
                    }
                }
                // Now and then a customer brings something back the same day.
                if ($sold !== [] && mt_rand(1, 6) === 1) {
                    $this->giveBack($register, $sold[array_rand($sold)], $supervisor);
                }
                if ($ago === 0) {
                    continue;
                }
                Carbon::setTestNow($day->setTime(14, mt_rand(0, 30)));
                $session = $sessions->current($this->company, $register);
                $expected = $sessions->expectedCash($this->company, $session);
                $short = $ago === 4 && $code === 'DHN2';
                $session = $sessions->close($this->company, $session, $session->version, $short ? $expected - 50000 : $expected, $short ? 'Counted twice, 500 short' : null, $cashier);
                if ($session->status === Session::PENDING && ! $short) {
                    $sessions->review($this->company, $session, $session->version, 'Checked against the slips', $supervisor);
                }
            }
        }
    }

    /**
     * One sale of one to four items, paid in cash (often with change), by
     * card or mobile wallet, sometimes with a small discount.
     *
     * @param  Collection<string, array<string, mixed>>  $catalogue
     * @param  list<string|null>  $customers
     */
    private function sell(Register $register, $catalogue, array $customers, bool $includeTax, User $cashier): ?Sale
    {
        $lines = [];
        foreach ($catalogue->random(mt_rand(1, 4)) as $item) {
            $quantity = in_array($item['sku'], ['RICE-MINI', 'RICE-NAZ', 'DAL-MSR', 'SUGAR', 'ONION', 'POTATO'], true) ? mt_rand(1, 10) * 500 : mt_rand(1, 3) * 1000;
            if ($item['on_hand_milli'] !== null && $item['on_hand_milli'] < $quantity + 5000) {
                continue;
            }
            $gross = intdiv($quantity * $item['sale_price_minor'] + 500, 1000);
            $lines[] = ['item_id' => $item['id'], 'quantity_milli' => $quantity, 'discount_minor' => mt_rand(1, 8) === 1 ? intdiv($gross, 20) : 0,
                'unit_price_minor' => $item['sale_price_minor'], 'tax_rate_bp' => $item['tax_rate_bp']];
        }
        if ($lines === []) {
            return null;
        }
        $total = Pricing::sale($lines, $includeTax)['total'];
        $payments = match (mt_rand(1, 10)) {
            1, 2 => [['method' => 'card', 'amount_minor' => $total, 'reference' => (string) mt_rand(1000, 9999)]],
            3, 4, 5 => [['method' => 'mobile', 'amount_minor' => $total, 'reference' => 'BK'.mt_rand(100000, 999999)]],
            default => [['method' => 'cash', 'amount_minor' => intdiv($total + 49999, 50000) * 50000]],
        };
        try {
            return app(Sales::class)->sell($this->company, $register, [
                'op_id' => (string) Str::ulid(), 'customer_name' => $customers[array_rand($customers)],
                'lines' => array_map(fn (array $line) => ['item_id' => $line['item_id'], 'quantity_milli' => $line['quantity_milli'], 'discount_minor' => $line['discount_minor']], $lines),
                'payments' => $payments,
            ], $cashier);
        } catch (Throwable $exception) {
            // Out of stock on the floor: that customer walks away.
            return null;
        }
    }

    private function giveBack(Register $register, Sale $sale, User $supervisor): void
    {
        $line = app(Tills::class)->query(SaleLine::class, $this->company)->where('sale_id', $sale->getKey())->orderBy('line_no')->first();
        if ($line === null) {
            return;
        }
        try {
            app(Sales::class)->giveBack($this->company, $sale, $register, ['op_id' => (string) Str::ulid(), 'reason' => 'Customer changed their mind',
                'lines' => [['line_id' => $line->id, 'quantity_milli' => min($line->quantity_milli, 1000)]]], $supervisor);
        } catch (Throwable) {
            // A return the rules refuse is simply not made.
        }
    }

    private function person(string $name, string $email): User
    {
        return $this->people[$email] = User::query()->where('email', $email)->first()
            ?? User::query()->forceCreate(['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'email_verified_at' => now()]);
    }

    /**
     * @param  list<string>  $templates
     */
    private function staff(Organization $unit, User $person, array $templates, User $owner): void
    {
        app(AddMember::class)->handle($unit, $person, MembershipType::Staff, AccessScope::Descendants, actor: $owner);
        $roles = app(RoleService::class);
        $ids = [];
        foreach ($templates as $template) {
            $ids[] = (Role::query()->where('organization_id', $this->company->id)->where('template_key', $template)->first()
                ?? $roles->create($this->company, ['en' => __("access.templates.{$template}.name", [], 'en'), 'bn' => __("access.templates.{$template}.name", [], 'bn')],
                    null, null, $template, $owner, 'Demo data'))->id;
        }
        $roles->syncMembershipRoles($unit->memberships()->where('user_id', $person->id)->sole(), $ids, $owner, 'Demo data');
    }

    /** Twelve digits and the EAN-13 check digit. */
    public static function ean13(string $twelve): string
    {
        $sum = 0;
        foreach (str_split($twelve) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return $twelve.((10 - $sum % 10) % 10);
    }
}
