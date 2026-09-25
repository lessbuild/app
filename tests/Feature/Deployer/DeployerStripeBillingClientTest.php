<?php

namespace Tests\Feature\Deployer;

use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Services\DeployerStripeBillingClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeployerStripeBillingClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::connection('deployer')->dropIfExists('organization_user');
        Schema::connection('deployer')->dropIfExists('organizations');
        Schema::connection('deployer')->create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
        Schema::connection('deployer')->create('organization_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('user_id');
        });
        config([
            'cashier.secret' => 'sk_test_deployer',
            'cashier.api_url' => 'https://api.stripe.com',
            'billing.trial_days' => 14,
            'billing.plans' => [
                'team' => [
                    'monthly_price_id' => 'price_team_monthly',
                    'yearly_price_id' => 'price_team_yearly',
                    'yearly_seat_price_id' => 'price_seat_yearly',
                    'included_seats' => 3,
                ],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        Schema::connection('deployer')->dropIfExists('organization_user');
        Schema::connection('deployer')->dropIfExists('organizations');
        parent::tearDown();
    }

    public function test_checkout_uses_the_core_workspace_plan_and_preserves_seat_trial_and_customer_rules(): void
    {
        $organizationId = DB::connection('deployer')->table('organizations')->insertGetId(['name' => 'Billing workspace']);
        DB::connection('deployer')->table('organization_user')->insert([
            ['organization_id' => $organizationId, 'user_id' => 1],
            ['organization_id' => $organizationId, 'user_id' => 2],
            ['organization_id' => $organizationId, 'user_id' => 3],
            ['organization_id' => $organizationId, 'user_id' => 4],
            ['organization_id' => $organizationId, 'user_id' => 5],
        ]);
        $organization = Organization::query()->findOrFail($organizationId);
        Http::fake([
            'https://api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_deployer_workspace',
                'url' => 'https://checkout.stripe.com/c/pay/cs_deployer_workspace',
            ], 200),
        ]);

        $session = app(DeployerStripeBillingClient::class)->createCheckoutSession(
            $organization,
            '01J8COREWORKSPACE00000000000',
            'billing@example.test',
            'team',
            'yearly',
            'cus_deployer_workspace',
            true,
            'stable-checkout-key',
        );

        $this->assertSame('cs_deployer_workspace', $session['id']);
        $this->assertSame('https://checkout.stripe.com/c/pay/cs_deployer_workspace', $session['url']);
        Http::assertSent(function ($request) use ($organizationId): bool {
            $data = $request->data();

            return $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
                && $request->hasHeader('Idempotency-Key', 'deployer-checkout-stable-checkout-key')
                && ($data['mode'] ?? null) === 'subscription'
                && ($data['line_items[0][price]'] ?? null) === 'price_team_yearly'
                && ($data['line_items[1][price]'] ?? null) === 'price_seat_yearly'
                && ($data['line_items[1][quantity]'] ?? null) === '2'
                && ($data['customer'] ?? null) === 'cus_deployer_workspace'
                && ! array_key_exists('customer_email', $data)
                && ($data['metadata[core_workspace_id]'] ?? null) === '01J8COREWORKSPACE00000000000'
                && ($data['metadata[organization_id]'] ?? null) === (string) $organizationId
                && ($data['subscription_data[trial_period_days]'] ?? null) === '14'
                && str_contains((string) ($data['success_url'] ?? ''), 'checkout=success');
        });
    }
}
