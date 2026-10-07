<?php

namespace Database\Seeders;

use App\Models\User;
use App\Platform\Access\AccessResolver;
use App\Platform\Access\Models\Role;
use App\Platform\Access\Services\RoleService;
use App\Platform\Modules\Services\ModuleToggleService;
use App\Platform\Rules\Enums\RuleMode;
use App\Platform\Rules\RuleTargets;
use App\Platform\Rules\Services\RuleService;
use App\Platform\Tenancy\Actions\AddMember;
use App\Platform\Tenancy\Context\ContextResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\AccessScope;
use App\Platform\Tenancy\Enums\MembershipType;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\Accounting\Services\TaxCodes;
use Modules\Crm\Models\Contact;
use Modules\Crm\Models\Stage;
use Modules\Crm\Services\Activities;
use Modules\Crm\Services\Contacts;
use Modules\Crm\Services\Crm;
use Modules\Crm\Services\Deals;
use Modules\Crm\Services\Fields;
use Modules\Crm\Services\Pipelines;
use Modules\Crm\Services\Quotes;
use Modules\Inventory\Models\Item;
use Modules\Inventory\Services\Inventories;
use Modules\Pos\Models\Register;
use Modules\Pos\Services\Sales;
use Modules\Pos\Services\Sessions;
use Modules\Pos\Services\Tills;
use Throwable;

/**
 * Local-only CRM demo for Demo Super Shop (after DemoStockSeeder): the
 * company's own fields (delivery address and payment terms on quotes,
 * warranty on lines, birthday and customer type on contacts), loyalty
 * points (1 per 100 taka), about twenty customers and businesses, bulk
 * orders in the pipeline (won, lost, open), follow-ups late, today and
 * coming, estimates and quotations (one accepted with its invoice), and a
 * few counter sales today with mobile numbers that find their customers.
 * Through the modules' own services; only adds; stops when the shop already
 * has contacts. Login sales@demo.test (sales person), password DemoStockSeeder::PASSWORD.
 */
class DemoCrmSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            return;
        }
        $shop = Organization::query()->get()->first(fn (Organization $org) => $org->name === 'Demo Super Shop');
        if ($shop === null) {
            $this->command?->warn('CRM demo skipped: run DemoStockSeeder first.');

            return;
        }
        $crm = app(Crm::class);
        if ($crm->query(Contact::class, $shop)->exists()) {
            $this->command?->warn('CRM demo skipped: the shop has contacts already.');

            return;
        }
        $owner = User::query()->where('email', 'shop.owner@demo.test')->firstOrFail();
        app(ModuleToggleService::class)->enable($shop, 'crm', 'Demo data');
        app(RuleService::class)->set(app(RuleTargets::class)->organization($shop), 'crm.loyalty_points_per_100', RuleMode::Set, 1, 'Demo data', trusted: true);
        app(ContextResolver::class)->enterOrganization($owner, $shop->id);
        $seller = $this->seller($shop, $owner);
        $today = CarbonImmutable::now('UTC')->startOfDay();
        mt_srand(20261007);

        try {
            $this->fields($shop, $owner);
            $contacts = $this->contacts($shop, $seller);
            $this->deals($shop, $contacts, $seller, $owner, $today);
            $this->counter($shop);
        } finally {
            Carbon::setTestNow();
            app(CurrentContext::class)->clear();
            app(AccessResolver::class)->forget();
        }
        $this->command?->info('Demo login: sales@demo.test / '.DemoStockSeeder::PASSWORD);
    }

    private function seller(Organization $shop, User $owner): User
    {
        $seller = User::query()->where('email', 'sales@demo.test')->first()
            ?? User::query()->forceCreate(['name' => 'Mitu Sales', 'email' => 'sales@demo.test', 'password' => DemoStockSeeder::PASSWORD, 'email_verified_at' => now()]);
        if (! $shop->memberships()->where('user_id', $seller->id)->exists()) {
            app(AddMember::class)->handle($shop, $seller, MembershipType::Staff, AccessScope::Descendants, actor: $owner);
        }
        $roles = app(RoleService::class);
        $ids = [];
        foreach (['sales_person'] as $template) {
            $ids[$template] = (Role::query()->where('organization_id', $shop->id)->where('template_key', $template)->first()
                ?? $roles->create($shop, ['en' => __("access.templates.{$template}.name", [], 'en'), 'bn' => __("access.templates.{$template}.name", [], 'bn')], null, null, $template, $owner, 'Demo data'))->id;
        }
        $roles->syncMembershipRoles($shop->memberships()->where('user_id', $seller->id)->sole(), [$ids['sales_person']], $owner, 'Demo data');

        return $seller;
    }

    private function fields(Organization $shop, User $owner): void
    {
        $fields = app(Fields::class);
        foreach ([
            ['entity' => 'quote', 'key' => 'delivery_address', 'type' => 'long_text', 'label' => ['en' => 'Delivery address', 'bn' => 'ডেলিভারির ঠিকানা'], 'sort_order' => 10],
            ['entity' => 'quote', 'key' => 'payment_terms', 'type' => 'choice', 'label' => ['en' => 'Payment terms', 'bn' => 'পরিশোধের শর্ত'], 'is_required' => true, 'sort_order' => 20, 'options' => [
                ['value' => 'advance', 'label' => ['en' => 'Full advance', 'bn' => 'পুরো অগ্রিম']],
                ['value' => 'half', 'label' => ['en' => '50% advance, rest on delivery', 'bn' => '৫০% অগ্রিম, বাকি ডেলিভারিতে']],
                ['value' => 'credit_30', 'label' => ['en' => '30 days credit', 'bn' => '৩০ দিনের বাকি']],
            ]],
            ['entity' => 'quote', 'key' => 'delivery_charge', 'type' => 'money', 'label' => ['en' => 'Delivery charge', 'bn' => 'ডেলিভারি চার্জ'], 'sort_order' => 30],
            ['entity' => 'quote_line', 'key' => 'brand', 'type' => 'text', 'label' => ['en' => 'Brand', 'bn' => 'ব্র্যান্ড'], 'sort_order' => 10],
            ['entity' => 'quote_line', 'key' => 'warranty_months', 'type' => 'number', 'label' => ['en' => 'Warranty (months)', 'bn' => 'ওয়ারেন্টি (মাস)'], 'sort_order' => 20],
            ['entity' => 'contact', 'key' => 'customer_type', 'type' => 'choice', 'label' => ['en' => 'Customer type', 'bn' => 'ক্রেতার ধরন'], 'sort_order' => 10, 'options' => [
                ['value' => 'household', 'label' => ['en' => 'Household', 'bn' => 'পরিবার']],
                ['value' => 'restaurant', 'label' => ['en' => 'Restaurant', 'bn' => 'রেস্তোরাঁ']],
                ['value' => 'office', 'label' => ['en' => 'Office', 'bn' => 'অফিস']],
            ]],
            ['entity' => 'contact', 'key' => 'birthday', 'type' => 'date', 'label' => ['en' => 'Birthday', 'bn' => 'জন্মদিন'], 'sort_order' => 20, 'on_print' => false],
            ['entity' => 'deal', 'key' => 'delivery_by', 'type' => 'date', 'label' => ['en' => 'Needed by', 'bn' => 'যেদিনের মধ্যে লাগবে'], 'sort_order' => 10],
        ] as $field) {
            $fields->save($shop, null, null, $field + ['is_required' => false, 'on_print' => true], $owner);
        }
    }

    /**
     * @return array<string, Contact>
     */
    private function contacts(Organization $shop, User $seller): array
    {
        $contacts = app(Contacts::class);
        $branches = Organization::query()->where('parent_id', $shop->id)->get()->keyBy(fn (Organization $branch) => $branch->name);
        $rows = [
            ['Nasrin Akter', null, '01711-300101', 'household', ['regular'], 'walk_in', 'Dhanmondi', '1988-03-14'],
            ['Rahim Uddin', null, '01811-300102', 'household', ['regular', 'vip'], 'referral', 'Dhanmondi', null],
            ['Farzana Haque', null, '01911-300103', 'household', [], 'facebook', 'Uttara', '1992-11-02'],
            ['Tanvir Ahmed', null, '01611-300104', 'household', ['regular'], 'walk_in', 'Uttara', null],
            ['Sumaiya Islam', null, '01511-300105', 'household', [], 'website', 'Dhanmondi', null],
            ['Kamrul Hasan', 'Spice Garden Restaurant', '01711-300106', 'restaurant', ['wholesale'], 'phone', 'Dhanmondi', null],
            ['Shirin Sultana', 'Hotel Lake View', '01811-300107', 'restaurant', ['wholesale', 'vip'], 'referral', 'Uttara', null],
            ['Abdul Karim', 'Karim Tea Stall', '01911-300108', 'restaurant', ['wholesale'], 'walk_in', 'Uttara', null],
            ['Mahmudul Hasan', 'Northern Tech Ltd', '01711-300109', 'office', ['office'], 'event', 'Uttara', null],
            ['Ruma Begum', 'Green Leaf School Canteen', '01811-300110', 'restaurant', ['wholesale'], 'phone', 'Dhanmondi', null],
            ['Imran Khan', 'Dhaka Garments Ltd', '01911-300111', 'office', ['office', 'vip'], 'referral', 'Dhanmondi', null],
            ['Nusrat Jahan', null, '01611-300112', 'household', [], 'walk_in', 'Dhanmondi', '1995-07-21'],
            ['Arif Hossain', null, '01511-300113', 'household', ['regular'], 'walk_in', 'Uttara', null],
            ['Lima Akter', null, '01711-300114', 'household', [], 'facebook', 'Dhanmondi', null],
            ['Sajid Rahman', 'Bay Software', '01811-300115', 'office', ['office'], 'website', 'Uttara', null],
        ];
        $made = [];
        foreach ($rows as $index => [$name, $company, $phone, $type, $tags, $source, $branch, $birthday]) {
            $made[$name] = $contacts->create($shop, ($branches[$branch] ?? $shop)->id, [
                'kind' => $company === null ? 'person' : 'organization', 'name' => $name, 'company_name' => $company, 'phone' => $phone,
                'email' => $company === null ? null : Str::slug($company, '.').'@example.com', 'tags' => $tags, 'source' => $source, 'owner_id' => $seller->id,
                'sms_consent' => $index % 3 !== 2, 'email_consent' => $company !== null,
                'extra' => array_filter(['customer_type' => $type, 'birthday' => $birthday]),
            ], $seller);
        }

        return $made;
    }

    /**
     * @param  array<string, Contact>  $contacts
     */
    private function deals(Organization $shop, array $contacts, User $seller, User $owner, CarbonImmutable $today): void
    {
        $crm = app(Crm::class);
        $pipeline = app(Pipelines::class)->default($shop);
        $stages = $crm->query(Stage::class, $shop)->where('pipeline_id', $pipeline->getKey())->orderBy('sort_order')->get();
        $open = $stages->where('outcome', 'open')->values();
        $deals = app(Deals::class);
        $made = [];
        foreach ([
            ['Kamrul Hasan', 'Monthly rice, oil and spices', 18000000, 0, 10],
            ['Shirin Sultana', 'Breakfast supplies for the hotel', 26000000, 1, 5],
            ['Abdul Karim', 'Tea, sugar and biscuits every week', 4500000, 2, 3],
            ['Mahmudul Hasan', 'Office pantry for 80 staff', 12000000, 1, 14],
            ['Ruma Begum', 'School canteen term order', 9500000, 0, 21],
            ['Imran Khan', 'Eid gift packs for 300 workers', 45000000, 'won', -5],
            ['Sajid Rahman', 'Pantry snacks', 3000000, 'lost', -12],
        ] as [$name, $title, $value, $stage, $days]) {
            $deal = $deals->create($shop, $contacts[$name]->unit_id, ['contact_id' => $contacts[$name]->getKey(), 'title' => $title, 'value_minor' => $value,
                'expected_on' => $today->addDays($days)->toDateString(), 'extra' => ['delivery_by' => $today->addDays($days + 3)->toDateString()]], $seller);
            if ($stage === 'won' || $stage === 'lost') {
                $deals->move($shop, $deal, $deal->version, $stages->firstWhere('outcome', $stage)->getKey(), $stage === 'lost' ? 'Found a cheaper supplier' : null, $seller);
            } elseif ($stage > 0) {
                $deals->move($shop, $deal, $deal->version, $open[min($stage, $open->count() - 1)]->getKey(), null, $seller);
            }
            $made[$name] = $deal->fresh();
        }

        // Follow-ups: two late, three today, some coming; notes of what happened.
        $activities = app(Activities::class);
        foreach ([
            ['Kamrul Hasan', 'call', 'Confirm this month\'s rice quantity', -2, 11],
            ['Abdul Karim', 'visit', 'Take samples of the new biscuits', -1, 15],
            ['Shirin Sultana', 'meeting', 'Price meeting with the hotel manager', 0, 11],
            ['Mahmudul Hasan', 'call', 'Ask about the pantry headcount', 0, 14],
            ['Rahim Uddin', 'sms', 'Tell about the Friday offer', 0, 17],
            ['Ruma Begum', 'call', 'Term start date for the canteen', 2, 10],
            ['Imran Khan', 'visit', 'Deliver the gift packs and collect the cheque', 3, 12],
        ] as [$name, $kind, $subject, $days, $hour]) {
            $activities->create($shop, ['contact_id' => $contacts[$name]->getKey(), 'kind' => $kind, 'subject' => $subject,
                'due_at' => $today->addDays($days)->setTime($hour - 6, 0)->toIso8601String(), 'assigned_to' => $seller->getKey(), 'deal_id' => isset($made[$name]) ? $made[$name]->getKey() : null], $seller);
        }
        foreach ([['Kamrul Hasan', 'note', 'Prefers delivery before 9 in the morning'], ['Shirin Sultana', 'call', 'Asked for a price list by email'], ['Nasrin Akter', 'note', 'Allergic to peanuts; does not buy them']] as [$name, $kind, $subject]) {
            $activities->create($shop, ['contact_id' => $contacts[$name]->getKey(), 'kind' => $kind, 'subject' => $subject], $seller);
        }

        // Quotes: an estimate being worked on, one turned into a sent quotation, and one accepted with its invoice.
        $items = app(Inventories::class)->query(Item::class, $shop)->whereIn('sku', ['RICE-MINI', 'OIL-SOY1', 'SUGAR', 'TEA-400', 'BISCUIT', 'MILK-P500', 'DETERG-1'])->get()->keyBy('sku');
        $vat = collect(app(TaxCodes::class)->salesCodes($shop))->keyBy('code');
        $line = fn (string $sku, int $quantity, int $discount = 0, array $extra = []) => [
            'item_id' => $items[$sku]->id, 'quantity_milli' => $quantity * 1000, 'discount_minor' => $discount,
            'tax_code_id' => $items[$sku]->tax_code_id !== null && $vat->firstWhere('id', $items[$sku]->tax_code_id) !== null ? $items[$sku]->tax_code_id : null, 'extra' => $extra,
        ];
        $quotes = app(Quotes::class);
        $quotes->create($shop, $contacts['Kamrul Hasan']->unit_id, ['kind' => 'estimate', 'contact_id' => $contacts['Kamrul Hasan']->getKey(), 'deal_id' => $made['Kamrul Hasan']->getKey(),
            'subject' => 'Monthly kitchen supplies', 'extra' => ['payment_terms' => 'credit_30', 'delivery_address' => "Spice Garden Restaurant\nRoad 27, Dhanmondi, Dhaka"],
            'lines' => [$line('RICE-MINI', 500, 250000), $line('OIL-SOY1', 60), $line('SUGAR', 100), ['description' => 'Delivery twice a month', 'quantity_milli' => 2000, 'unit_price_minor' => 150000]]], $seller);
        $estimate = $quotes->create($shop, $contacts['Shirin Sultana']->unit_id, ['kind' => 'estimate', 'contact_id' => $contacts['Shirin Sultana']->getKey(), 'deal_id' => $made['Shirin Sultana']->getKey(),
            'subject' => 'Breakfast supplies, first month', 'extra' => ['payment_terms' => 'half', 'delivery_charge' => 150000],
            'lines' => [$line('MILK-P500', 40, 0, ['brand' => 'Dano']), $line('TEA-400', 30), $line('BISCUIT', 200, 20000), $line('SUGAR', 50)]], $seller);
        $quotation = $quotes->convert($shop, $estimate, $estimate->version, $seller);
        $quotes->send($shop, $quotation, $quotation->version, $seller);
        $gift = $quotes->create($shop, $contacts['Imran Khan']->unit_id, ['kind' => 'quotation', 'contact_id' => $contacts['Imran Khan']->getKey(), 'deal_id' => $made['Imran Khan']->getKey(),
            'subject' => 'Eid gift packs, 300 workers', 'terms' => "50% advance with the order.\nDelivery within 5 days of the advance.", 'extra' => ['payment_terms' => 'half', 'delivery_address' => "Dhaka Garments Ltd\nPlot 12, Uttara Sector 7"],
            'lines' => [$line('OIL-SOY1', 300, 0, ['brand' => 'Teer']), $line('SUGAR', 300), $line('DETERG-1', 300, 300000, ['warranty_months' => '0']), ['description' => 'Packing in gift bags', 'quantity_milli' => 300000, 'unit_price_minor' => 3000]]], $seller);
        $gift = $quotes->send($shop, $gift, $gift->version, $seller);
        try {
            // The deal is already won; accepting makes the customer and the draft invoice in the books.
            $quotes->accept($shop, $gift, $gift->version, true, $owner);
        } catch (Throwable $exception) {
            $this->command?->warn('Quotation accepted without an invoice: '.$exception->getMessage());
        }
    }

    /** A few counter sales today with mobile numbers: known customers found, a new one added, points earned. */
    private function counter(Organization $shop): void
    {
        $tills = app(Tills::class);
        $register = $tills->query(Register::class, $shop)->where('code', 'DHN1')->first();
        $cashier = User::query()->where('email', 'cashier@demo.test')->first();
        if ($register === null || $cashier === null || app(Sessions::class)->current($shop, $register) === null) {
            return;
        }
        $items = app(Inventories::class)->query(Item::class, $shop)->whereIn('sku', ['RICE-MINI', 'OIL-SOY1', 'EGG-12', 'BISCUIT', 'SOAP-100'])->get()->keyBy('sku');
        foreach ([['01711-300101', null, ['RICE-MINI' => 5000, 'OIL-SOY1' => 2000]], ['01811-300102', null, ['EGG-12' => 2000, 'BISCUIT' => 3000]], ['01755-300199', 'Jamal Uddin', ['SOAP-100' => 4000, 'BISCUIT' => 2000]]] as [$phone, $name, $lines]) {
            $priced = collect($lines)->map(fn ($quantity, $sku) => intdiv($quantity * $items[$sku]->sale_price_minor + 500, 1000))->sum();
            try {
                app(Sales::class)->sell($shop, $register, ['op_id' => (string) Str::ulid(), 'customer_phone' => $phone, 'customer_name' => $name,
                    'lines' => collect($lines)->map(fn ($quantity, $sku) => ['item_id' => $items[$sku]->id, 'quantity_milli' => $quantity])->values()->all(),
                    'payments' => [['method' => 'cash', 'amount_minor' => intdiv($priced * 2 + 50000, 50000) * 50000]]], $cashier);
            } catch (Throwable) {
                // Short on the shelf: that customer walks away.
            }
        }
    }
}
