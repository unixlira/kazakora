<?php

namespace Tests\Unit\Jobs;

use App\Jobs\ProcessMercadoLivreWebhook;
use App\Jobs\ProcessShopeeWebhook;
use Tests\TestCase;

/**
 * BUG REAL 2026-08-17 (pedido 260817JCXFKP1R, Shopee, nunca importado): o
 * canal reentregou o mesmo webhook 2x em 11s, 2 execuções concorrentes do
 * mesmo import colidiram numa constraint única (ver
 * AutoImportProductSkuRaceTest) e o pedido nunca chegou a existir. Ambos os
 * jobs de webhook agora são ShouldBeUnique — cobre só o contrato de
 * uniqueId() (mesma entrega repetida = mesma chave, evitando 2 execuções
 * concorrentes do mesmo import), não o lock de fila em si (mecanismo do
 * próprio Laravel, já testado por eles).
 */
class WebhookJobUniquenessTest extends TestCase
{
    public function test_shopee_webhook_job_uses_the_order_sn_as_its_unique_key(): void
    {
        $jobA = new ProcessShopeeWebhook(['data' => ['ordersn' => 'SN123']], 1);
        $jobB = new ProcessShopeeWebhook(['data' => ['ordersn' => 'SN123']], 2);
        $jobOther = new ProcessShopeeWebhook(['data' => ['ordersn' => 'SN999']], 3);

        $this->assertSame('SN123', $jobA->uniqueId());
        $this->assertSame($jobA->uniqueId(), $jobB->uniqueId());
        $this->assertNotSame($jobA->uniqueId(), $jobOther->uniqueId());
    }

    public function test_shopee_webhook_job_falls_back_to_the_log_id_when_the_payload_has_no_order_sn(): void
    {
        $job = new ProcessShopeeWebhook(['topic' => 'something_unparseable'], 42);

        $this->assertSame('42', $job->uniqueId());
    }

    public function test_mercado_livre_webhook_job_uses_the_resource_as_its_unique_key(): void
    {
        $jobA = new ProcessMercadoLivreWebhook(['resource' => '/orders/123'], 1);
        $jobB = new ProcessMercadoLivreWebhook(['resource' => '/orders/123'], 2);
        $jobOther = new ProcessMercadoLivreWebhook(['resource' => '/shipments/456'], 3);

        $this->assertSame('/orders/123', $jobA->uniqueId());
        $this->assertSame($jobA->uniqueId(), $jobB->uniqueId());
        $this->assertNotSame($jobA->uniqueId(), $jobOther->uniqueId());
    }
}
