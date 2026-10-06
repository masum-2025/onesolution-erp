<?php

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessSeeder;
use Database\Seeders\CountriesSeeder;
use Database\Seeders\DemoHierarchySeeder;
use Database\Seeders\DemoModulesSeeder;
use Database\Seeders\DemoStockSeeder;
use Database\Seeders\HousePartnerSeeder;
use Database\Seeders\PackagingSeeder;
use Database\Seeders\RulesSeeder;
use Illuminate\Support\Facades\Hash;
use Modules\Accounting\Services\Reports;
use Modules\Inventory\Models\Document;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Models\StockCount;
use Modules\Inventory\Services\Inventories;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\Session;
use Modules\Pos\Services\Tills;

/*
 * The local demo shop (INV/POS-3): made through the modules' own services,
 * so stock, books and counters agree; run twice, it adds nothing.
 */

it('builds the demo shop with stock, bills, counters and two weeks of sales, once', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 06:00:00', 'UTC'));
    $this->seed([HousePartnerSeeder::class, RulesSeeder::class, CountriesSeeder::class, AccessSeeder::class, PackagingSeeder::class, DemoHierarchySeeder::class, DemoModulesSeeder::class]);
    $this->seed(DemoStockSeeder::class);

    $shop = Organization::query()->get()->first(fn (Organization $org) => $org->name === 'Demo Super Shop');
    expect($shop)->not->toBeNull()->and($shop->sector_key)->toBe('retail');
    $stock = app(Inventories::class);
    $tills = app(Tills::class);
    $documents = $stock->query(Document::class, $shop);

    expect($stock->query(Item::class, $shop)->count())->toBe(30)
        ->and((clone $documents)->where('type', 'receipt')->whereNotNull('bill_id')->count())->toBe(2)
        ->and((clone $documents)->where('type', 'receipt')->whereNull('bill_id')->count())->toBe(2)
        ->and((clone $documents)->where('status', Document::PENDING)->count())->toBe(1)
        ->and((clone $documents)->where('status', Document::IN_TRANSIT)->count())->toBe(1)
        ->and($stock->query(StockCount::class, $shop)->where('status', StockCount::COUNTING)->count())->toBe(1)
        ->and($tills->query(Register::class, $shop)->count())->toBe(3)
        ->and($tills->query(Session::class, $shop)->where('status', Session::OPEN)->count())->toBe(1)
        ->and($tills->query(Session::class, $shop)->where('status', Session::PENDING)->count())->toBe(1)
        ->and($tills->query(Sale::class, $shop)->where('kind', 'sale')->count())->toBeGreaterThan(200);

    // The books balance, and the cashier can sign in with the demo password.
    $trial = app(Reports::class)->trialBalance($shop, '2026-10-06', null);
    expect($trial['total_debit_minor'])->toBe($trial['total_credit_minor'])->and($trial['total_debit_minor'])->toBeGreaterThan(0);
    expect(Hash::check(DemoStockSeeder::PASSWORD, User::query()->where('email', 'cashier@demo.test')->value('password')))->toBeTrue();

    $sales = $tills->query(Sale::class, $shop)->count();
    $this->seed(DemoStockSeeder::class);
    expect($tills->query(Sale::class, $shop)->count())->toBe($sales)
        ->and(Organization::query()->get()->filter(fn (Organization $org) => $org->name === 'Demo Super Shop'))->toHaveCount(1);
});

it('makes valid EAN-13 barcodes', function () {
    expect(DemoStockSeeder::ean13('400638133393'))->toBe('4006381333931')
        ->and(DemoStockSeeder::ean13('894100000000'))->toHaveLength(13);
});
