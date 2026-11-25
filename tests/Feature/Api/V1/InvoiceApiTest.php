<?php

namespace Tests\Feature\Api\V1;

use App\Models\Business;
use App\Models\Customer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_totals_are_calculated_from_products_server_side(): void
    {
        $user = User::factory()->create();
        $business = Business::create(['name' => 'A', 'slug' => 'a']);
        $user->businesses()->attach($business->id, ['role' => 'owner', 'status' => 'active']);
        $customer = $this->asTenant($business->id, fn () => Customer::create(['name' => 'Buyer']));
        $product = $this->asTenant($business->id, fn () => Product::create(['name' => 'Widget', 'unit_price' => 100, 'tax_rate' => 10]));

        $invoice = $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'discount' => 5,
            'items' => [[
                'product_id' => $product->id, 'quantity' => 2,
                'unit_price' => 1, 'tax_rate' => 99,
            ]],
        ])->assertCreated()->json('data');

        $this->assertSame('200.00', $invoice['subtotal']);
        $this->assertSame('0.00', $invoice['tax']);
        $this->assertSame('195.00', $invoice['total']);
        $this->assertSame('195.00', $invoice['balance_due']);
    }

    public function test_invoice_cannot_reference_another_business_customer(): void
    {
        $user = User::factory()->create();
        $businessA = Business::create(['name' => 'A', 'slug' => 'a']);
        $businessB = Business::create(['name' => 'B', 'slug' => 'b']);
        $user->businesses()->attach($businessA->id, ['role' => 'owner', 'status' => 'active']);
        $customer = $this->asTenant($businessB->id, fn () => Customer::create(['name' => 'Private']));

        $this->actingAs($user)->postJson('/api/v1/invoices', [
            'customer_id' => $customer->id,
            'items' => [['description' => 'Custom', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    private function asTenant(int $businessId, \Closure $callback): mixed
    {
        app(\App\Tenancy\TenantContext::class)->set($businessId);
        try { return $callback(); } finally { app(\App\Tenancy\TenantContext::class)->clear(); }
    }
}
