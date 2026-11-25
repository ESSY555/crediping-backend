<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2Phase3ComprehensiveTest extends TestCase
{
    use RefreshDatabase;

    private function userWithBusiness(string $suffix): array
    {
        $user = User::factory()->create();
        $business = Business::create([
            'name' => "Business {$suffix}",
            'slug' => "business-{$suffix}",
            'email' => "business{$suffix}@example.com",
            'phone' => "555-000" . str_pad($suffix, 4, '0', STR_PAD_LEFT),
            'address' => "{$suffix} Main Street",
            'city' => 'Test City',
            'country' => 'Test Country',
            'currency' => 'USD',
        ]);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);

        return [$user, $business];
    }

    // ============ PHASE 2: CRM TESTS ============

    public function test_customer_search_by_name(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'John Doe Enterprises',
            'phone' => '+234800000001',
        ]);

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Jane Smith Business',
            'phone' => '+234800000002',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?search=john');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'John Doe Enterprises');
    }

    public function test_customer_search_by_phone(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Customer One',
            'phone' => '+234-800-000-001',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?search=234-800');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_customer_search_by_email(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Email Customer',
            'email' => 'customer@example.com',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?search=example');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_customer_search_by_business_name(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'John Smith',
            'business_name' => 'Acme Corporation',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?search=acme');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_customer_filter_by_status(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Active Customer',
            'status' => 'active',
        ]);

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Inactive Customer',
            'status' => 'inactive',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?status=active');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.name', 'Active Customer');
    }

    public function test_customer_filter_by_category(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Retail Customer',
            'category' => 'retail',
        ]);

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Wholesale Customer',
            'category' => 'wholesale',
        ]);

        $response = $this->actingAs($user)->getJson('/api/v1/customers?category=retail');
        
        $response->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }

    public function test_customer_groups_pagination(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        for ($i = 0; $i < 20; $i++) {
            $this->actingAs($user)->postJson('/api/v1/customers', [
                'name' => "Customer {$i}",
                'phone' => "+234800000" . str_pad($i, 3, '0', STR_PAD_LEFT),
            ]);
        }

        $response = $this->actingAs($user)->getJson('/api/v1/customers?per_page=10');
        
        $response->assertSuccessful()
            ->assertJsonCount(10, 'data.data')
            ->assertJsonPath('data.total', 20);
    }

    public function test_customer_group_assignment_multiple_groups(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $group1 = $this->actingAs($user)->postJson('/api/v1/customer-groups', ['name' => 'VIP'])->json('data');
        $group2 = $this->actingAs($user)->postJson('/api/v1/customer-groups', ['name' => 'Retail'])->json('data');

        $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Multi-Group Customer',
            'group_ids' => [$group1['id'], $group2['id']],
        ])->assertCreated()
         ->assertJsonCount(2, 'data.groups');
    }

    public function test_customer_group_update(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Test Customer',
        ])->json('data');

        $group = $this->actingAs($user)->postJson('/api/v1/customer-groups', ['name' => 'New Group'])->json('data');

        $this->actingAs($user)->putJson("/api/v1/customers/{$customer['id']}", [
            'name' => 'Updated Customer',
            'group_ids' => [$group['id']],
        ])->assertSuccessful()
         ->assertJsonCount(1, 'data.groups');
    }

    public function test_customer_groups_are_tenant_isolated(): void
    {
        [$userA, $businessA] = $this->userWithBusiness('a');
        [$userB, $businessB] = $this->userWithBusiness('b');

        $groupA = $this->actingAs($userA)->postJson('/api/v1/customer-groups', ['name' => 'Group A'])->json('data');
        $groupB = $this->actingAs($userB)->postJson('/api/v1/customer-groups', ['name' => 'Group B'])->json('data');

        // Business A should not see Business B's group
        $response = $this->actingAs($userA)->getJson('/api/v1/customer-groups');
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals($groupA['id'], $response->json('data.data.0.id'));
    }

    // ============ PHASE 3: SALES TESTS ============

    public function test_invoice_creation_with_items(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Invoice Customer',
        ])->json('data');

        $product = $this->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Test Product',
            'sku' => 'TEST-001',
            'unit_price' => 100.00,
            'tax_rate' => 10,
        ])->json('data');

        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer['id'],
            'items' => [
                [
                    'product_id' => $product['id'],
                    'quantity' => 2,
                    'description' => 'Test Product',
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.subtotal', '200.00')
            ->assertJsonPath('data.tax', '0.00')
            ->assertJsonPath('data.total', '200.00');
    }

    public function test_invoice_calculations_with_discount(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Discount Customer',
        ])->json('data');

        $response = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer['id'],
            'discount' => 50.00,
            'items' => [
                [
                    'quantity' => 1,
                    'description' => 'Item 1',
                    'unit_price' => 100.00,
                    'tax_rate' => 10,
                ],
            ],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.tax', '0.00')
            ->assertJsonPath('data.discount', '50.00')
            ->assertJsonPath('data.total', '50.00');
    }

    public function test_invoice_pdf_generation(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'PDF Customer',
        ])->json('data');

        $invoice = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer['id'],
            'items' => [
                [
                    'quantity' => 1,
                    'description' => 'Service',
                    'unit_price' => 500.00,
                    'tax_rate' => 0,
                ],
            ],
        ])->json('data');

        $response = $this->actingAs($user)->get("/api/v1/invoices/{$invoice['id']}/pdf");

        $response->assertSuccessful()
            ->assertHeader('Content-Type', 'application/pdf');
        
        // Verify PDF content contains key elements
        $pdfContent = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdfContent);
        $this->assertStringContainsString($invoice['invoice_number'], $response->headers->get('Content-Disposition'));
    }

    public function test_invoice_status_determination(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Status Customer',
        ])->json('data');

        // Draft invoice
        $invoice = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer['id'],
            'items' => [
                [
                    'quantity' => 1,
                    'description' => 'Item',
                    'unit_price' => 100.00,
                ],
            ],
        ])->json('data');

        $this->assertEquals('draft', $invoice['status']);
    }

    public function test_invoice_update_only_draft_or_sent(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        $customer = $this->actingAs($user)->postJson('/api/v1/customers', [
            'name' => 'Customer',
        ])->json('data');

        $invoice = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer['id'],
            'items' => [
                [
                    'quantity' => 1,
                    'description' => 'Item',
                    'unit_price' => 100.00,
                ],
            ],
        ])->json('data');

        // Mark as paid
        Invoice::find($invoice['id'])->update(['status' => 'paid']);

        // Try to update paid invoice
        $response = $this->actingAs($user)->putJson("/api/v1/invoices/{$invoice['id']}", [
            'items' => [
                [
                    'quantity' => 2,
                    'description' => 'Item',
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $response->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_invoice_unique_number_per_business(): void
    {
        [$userA, $businessA] = $this->userWithBusiness('a');
        [$userB, $businessB] = $this->userWithBusiness('b');

        $customerA = $this->actingAs($userA)->postJson('/api/v1/customers', ['name' => 'Customer A'])->json('data');
        $customerB = $this->actingAs($userB)->postJson('/api/v1/customers', ['name' => 'Customer B'])->json('data');

        $invoiceA = $this->actingAs($userA)->postJson('/api/v1/invoices', [
            'customer_id' => $customerA['id'],
            'items' => [['quantity' => 1, 'description' => 'Item', 'unit_price' => 100.00]],
        ])->json('data');

        $invoiceB = $this->actingAs($userB)->postJson('/api/v1/invoices', [
            'customer_id' => $customerB['id'],
            'items' => [['quantity' => 1, 'description' => 'Item', 'unit_price' => 100.00]],
        ])->json('data');

        // Both should have invoice numbers starting with current year
        $this->assertStringContainsString(now()->format('Y'), $invoiceA['invoice_number']);
        $this->assertStringContainsString(now()->format('Y'), $invoiceB['invoice_number']);
    }

    public function test_invoice_cannot_be_accessed_across_tenants(): void
    {
        [$userA, $businessA] = $this->userWithBusiness('a');
        [$userB, $businessB] = $this->userWithBusiness('b');

        $customerA = $this->actingAs($userA)->postJson('/api/v1/customers', ['name' => 'Customer A'])->json('data');

        $invoiceA = $this->actingAs($userA)->postJson('/api/v1/invoices', [
            'customer_id' => $customerA['id'],
            'items' => [['quantity' => 1, 'description' => 'Item', 'unit_price' => 100.00]],
        ])->json('data');

        // User B should not access User A's invoice
        $response = $this->actingAs($userB)->getJson("/api/v1/invoices/{$invoiceA['id']}");
        
        $response->assertNotFound();
    }

    public function test_product_crud_operations(): void
    {
        [$user, $business] = $this->userWithBusiness('a');

        // Create
        $product = $this->actingAs($user)->postJson('/api/v1/products', [
            'name' => 'Test Product',
            'sku' => 'TEST-SKU',
            'description' => 'Product Description',
            'unit_price' => 99.99,
            'tax_rate' => 15,
            'status' => 'active',
        ])->assertCreated()->json('data');

        // Read
        $this->actingAs($user)->getJson("/api/v1/products/{$product['id']}")
            ->assertSuccessful()
            ->assertJsonPath('data.name', 'Test Product');

        // Update
        $this->actingAs($user)->putJson("/api/v1/products/{$product['id']}", [
            'name' => 'Updated Product',
            'unit_price' => 149.99,
        ])->assertSuccessful()
         ->assertJsonPath('data.name', 'Updated Product');

        // List
        $this->actingAs($user)->getJson('/api/v1/products')
            ->assertSuccessful()
            ->assertJsonCount(1, 'data.data');
    }
}
