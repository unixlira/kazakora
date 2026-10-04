<?php

namespace App\Console\Commands;

use App\Modules\Admin\Support\DigitalMarketingResearchService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ScanDigitalMarketingOpportunities extends Command
{
    protected $signature = 'digital-marketing:scan {--dry-run : Executa a varredura sem gravar cache/arquivo}';

    protected $description = 'Atualiza o radar de MKT Digital com criativos mapeados, oportunidades de PDF e matriz regional de checkout.';

    public function handle(DigitalMarketingResearchService $research): int
    {
        try {
            $snapshot = $research->scanAndCache((bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            Log::error('digital_marketing.scan.failed', ['message' => $exception->getMessage()]);
            $this->error('Falha ao atualizar radar de MKT Digital: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Radar MKT Digital atualizado: criativos=%d; oportunidades_pdf=%d; regioes=%d; prioridades=%d.',
            (int) ($snapshot['summary']['mappedCreatives'] ?? 0),
            (int) ($snapshot['summary']['pdfOpportunities'] ?? 0),
            (int) ($snapshot['summary']['paymentRegions'] ?? 0),
            (int) ($snapshot['summary']['priorityRegions'] ?? 0),
        ));

        return self::SUCCESS;
    }
}
