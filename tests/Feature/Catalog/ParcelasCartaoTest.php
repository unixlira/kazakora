<?php

namespace Tests\Feature\Catalog;

use App\Modules\Checkout\Support\ParcelasCartao;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParcelasCartaoTest extends TestCase
{
    public function test_tabela_de_parcelas_vem_do_mercado_pago_ate_12x(): void
    {
        config(['services.mercadopago.access_token' => 'token-de-teste']);
        Cache::flush();
        Http::fake(['api.mercadopago.com/*' => Http::response([['payer_costs' => [
            ['installments' => 1, 'installment_rate' => 0, 'min_allowed_amount' => 0.5],
            ['installments' => 12, 'installment_rate' => 22.11, 'min_allowed_amount' => 60],
            ['installments' => 13, 'installment_rate' => 23.04, 'min_allowed_amount' => 65],
        ]]])]);

        $this->assertSame([
            ['parcelas' => 1, 'taxa' => 0.0, 'minimo' => 0.5],
            ['parcelas' => 12, 'taxa' => 22.11, 'minimo' => 60.0],
        ], ParcelasCartao::tabela());
    }

    public function test_sem_resposta_do_mercado_pago_a_tabela_fica_vazia(): void
    {
        config(['services.mercadopago.access_token' => 'token-de-teste']);
        Cache::flush();
        Http::fake(['api.mercadopago.com/*' => Http::response([], 500)]);

        $this->assertSame([], ParcelasCartao::tabela());
    }
}
