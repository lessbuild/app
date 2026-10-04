<?php

declare(strict_types=1);

namespace Tests\Feature\StatusPages;

use App\Models\Project;
use App\Models\StatusPage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class WhiteLabelTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * A status page shows “Powered by” until the plan includes white labelling, then the account's own name, logo
     * and colour.
     */
    public function test_status_pages_use_the_accounts_branding_on_plans_with_white_labelling(): void
    {
        $project = Project::factory()->create();
        $project->account->forceFill(['brand_name' => 'Acme Studio', 'brand_logo_url' => 'https://acme.test/logo.png', 'brand_color' => '#1f6feb'])->save();
        $page = StatusPage::factory()->create(['account_id' => $project->account_id]);

        $this->get($page->publicUrl())->assertOk()->assertSee('Powered by')->assertDontSee('https://acme.test/logo.png');
        $this->onTier($project, 'deploy', 'team');
        $this->get($page->publicUrl())->assertOk()->assertDontSee('Powered by')->assertSee('https://acme.test/logo.png')->assertSee('--ui-primary: #1f6feb', false)->assertSee('Acme Studio');
    }
}
