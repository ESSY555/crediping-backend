<?php

namespace Database\Seeders;

use App\Models\Business;
use App\Models\BusinessSetting;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\MessageTemplate;
use App\Models\Product;
use App\Models\ReminderRule;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Tenancy\TenantManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Development data: one demo business with an owner, a manager and a realistic
 * ledger. Safe to re-run; it does nothing when the demo business already exists.
 *
 *   owner:   demo@flowcrm.test / password
 *   manager: manager@flowcrm.test / password
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (Business::query()->where('slug', 'ridgeline-trading')->exists()) {
            $this->command?->info('Demo business already seeded.');
            return;
        }

        $business = Business::create([
            'name' => 'Ridgeline Trading Co.',
            'slug' => 'ridgeline-trading',
            'email' => 'accounts@ridgeline.test',
            'phone' => '+234 802 000 1122',
            'address' => '14 Adeola Odeku St, Victoria Island',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'Nigeria',
            'currency' => 'NGN',
            'timezone' => 'Africa/Lagos',
            'tax_number' => 'TIN-0099-2211',
            'status' => 'active',
        ]);

        $owner = User::create(['name' => 'Ada Nwachukwu', 'email' => 'demo@flowcrm.test', 'phone' => '+234 802 000 1122', 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $manager = User::create(['name' => 'Segun Adeyemi', 'email' => 'manager@flowcrm.test', 'phone' => '+234 803 111 2233', 'password' => Hash::make('password'), 'email_verified_at' => now()]);
        $business->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
        $business->users()->attach($manager->id, ['role' => 'manager', 'status' => 'active']);

        TenantManager::runAs($business->id, function () use ($owner) {
            foreach ([
                'setup_completed' => 'true', 'invoice_prefix' => 'INV-', 'payment_terms_days' => '14',
                'invoice_footer' => 'Thank you for choosing us. Payment to Ridgeline Trading Co., GTBank 0123456789.',
                'reminders_enabled' => 'true', 'payment_receipts_enabled' => 'false', 'industry' => 'Distributor',
            ] as $key => $value) {
                BusinessSetting::create(['key' => $key, 'value' => $value]);
            }

            $groups = collect(['Distributors', 'Retailers', 'Wholesale', 'Walk-in', 'VIP'])
                ->mapWithKeys(fn ($name) => [$name => CustomerGroup::create(['name' => $name])]);

            $customers = collect([
                ['Amaka Obi', 'Bright Foods Ltd', '+234 803 221 0091', 'amaka@brightfoods.ng', '12 Market Road, Lagos', 'Distributors', 'Pays on Fridays. Prefers WhatsApp over calls.'],
                ['Tunde Bakare', 'Bakare & Sons', '+234 706 884 2210', 'tunde@bakaresons.com', '5 Balogun Street, Lagos', 'Retailers', 'Requested monthly statements.'],
                ['Zainab Yusuf', 'Kano Textiles', '+234 812 445 7781', 'zainab@kanotextiles.ng', '33 Murtala Way, Kano', 'Wholesale', 'Bulk orders every quarter.'],
                ['Chidi Nwosu', 'Nwosu Pharmacy', '+234 809 112 3345', 'chidi@nwosurx.com', '7 Otigba Street, Enugu', 'Retailers', 'Two invoices went overdue last quarter.'],
                ['Fatima Sule', 'Sule Logistics', '+234 701 993 8820', 'fatima@sulelogistics.ng', '21 Ahmadu Bello Way, Abuja', 'Distributors', 'New account, credit limit under review.'],
                ['Emeka Ali', null, '+234 815 220 4413', 'emeka.ali@gmail.com', 'Lagos', 'Walk-in', ''],
            ])->map(function ($row) use ($groups) {
                [$name, $company, $phone, $email, $address, $group, $notes] = $row;
                $customer = Customer::create([
                    'name' => $name, 'business_name' => $company, 'phone' => $phone, 'email' => $email,
                    'address' => $address, 'category' => strtolower($group), 'notes' => $notes, 'status' => 'active',
                ]);
                $customer->groups()->attach($groups[$group]->id, ['business_id' => $customer->business_id]);
                return $customer;
            });

            $products = collect([
                ['Palm oil — 25L drum', 'PALM-25', 32000, 7.5],
                ['Groundnut oil — 20L drum', 'GNUT-20', 29000, 7.5],
                ['Assorted dry goods carton', 'DRY-01', 24000, 0],
                ['Paracetamol 500mg — pack of 100', 'PARA-100', 4500, 0],
                ['Ankara roll — 12 yards', 'ANK-12', 18500, 7.5],
                ['Delivery handling', 'DELIV', 15000, 0],
            ])->map(fn ($p) => Product::create(['name' => $p[0], 'sku' => $p[1], 'unit_price' => $p[2], 'tax_rate' => $p[3], 'status' => 'active']));

            $templates = [
                ['name' => 'invoice_reminder', 'category' => 'utility', 'status' => 'approved',
                    'content' => 'Hello {{customer_name}}, invoice {{invoice_number}} for {{total}} is due on {{due_date}}. Outstanding balance: {{balance_due}}. Kindly confirm payment. — {{business_name}}'],
                ['name' => 'overdue_followup', 'category' => 'utility', 'status' => 'approved',
                    'content' => 'Hi {{customer_name}}, invoice {{invoice_number}} is now {{days_overdue}} day(s) overdue with {{balance_due}} outstanding. Please advise on a payment date. — {{business_name}}'],
                ['name' => 'payment_receipt', 'category' => 'utility', 'status' => 'approved',
                    'content' => 'Thank you {{customer_name}}. We have received your payment for invoice {{invoice_number}}. Balance: {{balance_due}}.'],
                ['name' => 'new_stock_alert', 'category' => 'marketing', 'status' => 'pending',
                    'content' => 'Hello {{customer_name}}, new stock has arrived at {{business_name}}. Reply to this message to place an order.'],
            ];
            $templates = collect($templates)->mapWithKeys(fn ($t) => [$t['name'] => MessageTemplate::create($t + ['language' => 'en'])]);

            ReminderRule::create(['name' => '3 days before due', 'trigger_type' => 'before_due', 'days_offset' => 3, 'template_id' => $templates['invoice_reminder']->id, 'enabled' => true]);
            ReminderRule::create(['name' => 'Due today', 'trigger_type' => 'on_due', 'days_offset' => 0, 'template_id' => $templates['invoice_reminder']->id, 'enabled' => true]);
            ReminderRule::create(['name' => '7 days overdue', 'trigger_type' => 'after_due', 'days_offset' => 7, 'template_id' => $templates['overdue_followup']->id, 'enabled' => true]);
            ReminderRule::create(['name' => '30 days overdue', 'trigger_type' => 'after_due', 'days_offset' => 30, 'template_id' => $templates['overdue_followup']->id, 'enabled' => false]);

            $invoices = app(InvoiceService::class);
            $payments = app(PaymentService::class);
            $make = fn (Customer $customer, array $items, int $issuedDaysAgo, int $terms = 14, string $status = 'sent') => $invoices->create([
                'customer_id' => $customer->id,
                'issue_date' => today()->subDays($issuedDaysAgo)->toDateString(),
                'due_date' => today()->subDays($issuedDaysAgo)->addDays($terms)->toDateString(),
                'status' => $status,
                'items' => $items,
            ], $owner->id);
            $line = fn (Product $p, float $qty) => ['product_id' => $p->id, 'quantity' => $qty];

            $paid = $make($customers[0], [$line($products[0], 40), $line($products[5], 1)], 60);
            $payments->record(['invoice_id' => $paid->id, 'amount' => 800000, 'payment_method' => 'bank_transfer', 'reference' => 'TRF-90211', 'payment_date' => today()->subDays(52)->toDateString()], $owner->id);
            $payments->record(['invoice_id' => $paid->id, 'amount' => (float) $paid->fresh()->balance_due, 'payment_method' => 'bank_transfer', 'reference' => 'TRF-90342', 'payment_date' => today()->subDays(46)->toDateString()], $owner->id);

            $partial = $make($customers[1], [$line($products[2], 30)], 5);
            $payments->record(['invoice_id' => $partial->id, 'amount' => 300000, 'payment_method' => 'cash', 'reference' => 'CSH-1188', 'payment_date' => today()->subDays(2)->toDateString()], $owner->id);

            $make($customers[3], [$line($products[3], 120)], 55);                       // overdue 41 days
            $make($customers[2], [$line($products[4], 85)], 8);                          // due in 6 days
            $make($customers[4], [['description' => 'Freight service — Lagos to Abuja', 'quantity' => 3, 'unit_price' => 410000]], 2, 14, 'draft');
            $overdue = $make($customers[0], [$line($products[1], 25)], 70);              // overdue 56 days
            $payments->record(['invoice_id' => $overdue->id, 'amount' => 200000, 'payment_method' => 'card', 'reference' => 'POS-5521', 'payment_date' => today()->subDays(63)->toDateString()], $owner->id);
            $make($customers[5], [['description' => 'Retail counter order', 'quantity' => 1, 'unit_price' => 96000]], 1);

            $invoices->markOverdue();
        });

        $this->command?->info('Demo business seeded: demo@flowcrm.test / password');
    }
}
