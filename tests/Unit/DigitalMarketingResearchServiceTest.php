<?php

namespace Tests\Unit;

use App\Modules\Admin\Support\DigitalMarketingResearchService;
use Tests\TestCase;

class DigitalMarketingResearchServiceTest extends TestCase
{
    public function test_it_builds_digital_marketing_dashboard_snapshot(): void
    {
        $snapshot = app(DigitalMarketingResearchService::class)->scanAndCache(dryRun: true);

        $this->assertGreaterThanOrEqual(4, $snapshot['summary']['mappedCreatives']);
        $this->assertGreaterThanOrEqual(4, $snapshot['summary']['verifiedEvidenceLinks']);
        $this->assertGreaterThanOrEqual(82, $snapshot['summary']['minimumScore']);
        $this->assertGreaterThanOrEqual(4, $snapshot['summary']['paymentRegions']);
        $this->assertNotEmpty($snapshot['regionPlaybooks']);
        $this->assertNotEmpty($snapshot['sourceSearches']);
        $this->assertStringContainsString('PDFs', $snapshot['providerStatus']['name']);
    }

    public function test_gateway_matrix_prioritizes_latin_america_for_brl_receiving(): void
    {
        $matrix = app(DigitalMarketingResearchService::class)->paymentGatewayMatrix();
        $latinAmerica = collect($matrix)->firstWhere('region', 'América Latina');

        $this->assertNotNull($latinAmerica);
        $this->assertSame(1, $latinAmerica['priority']);
        $this->assertStringContainsString('Hotmart', $latinAmerica['gateway']);
        $this->assertStringContainsString('reais', $latinAmerica['receivesInBrl']);
    }
}
