<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Support\EntregaExpressa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Entrega expressa na Grande SP (pedido 2026-10-09): CEP na área + até 13h
 * = "Receba hoje até as 21h"; depois das 13h = "Receba amanhã"; fora da área
 * = prazo normal (null).
 */
class EntregaExpressaTest extends TestCase
{
    use RefreshDatabase;

    private function em(string $hora): Carbon
    {
        return Carbon::parse("2026-10-09 {$hora}", 'America/Sao_Paulo');
    }

    public function test_cep_na_area_antes_das_13h_recebe_hoje(): void
    {
        $r = app(EntregaExpressa::class)->consultar('03187-040', $this->em('12:59'));

        $this->assertSame(EntregaExpressa::HOJE, $r['tipo']);
        $this->assertSame('Receba hoje até as 21h', $r['mensagem']);
        $this->assertSame('São Paulo', $r['local']);
    }

    public function test_cep_na_area_a_partir_das_13h_recebe_amanha(): void
    {
        $servico = app(EntregaExpressa::class);

        $this->assertSame('Receba amanhã', $servico->consultar('03187040', $this->em('13:00'))['mensagem']);
        $this->assertSame('Receba amanhã', $servico->consultar('03187040', $this->em('22:30'))['mensagem']);
    }

    public function test_horario_usa_brasilia_mesmo_com_hora_em_utc(): void
    {
        // 15:30 UTC = 12:30 em Brasília → ainda é "hoje".
        $r = app(EntregaExpressa::class)->consultar('01001-000', Carbon::parse('2026-10-09 15:30', 'UTC'));

        $this->assertSame(EntregaExpressa::HOJE, $r['tipo']);
    }

    public function test_municipios_da_grande_sp_atendidos(): void
    {
        $servico = app(EntregaExpressa::class);

        foreach ([
            '06010-000' => 'Osasco',
            '06454-000' => 'Barueri',
            '06760-000' => 'Taboão da Serra',
            '09510-000' => 'São Caetano do Sul',
            '08490-000' => 'São Paulo',
        ] as $cep => $local) {
            $this->assertSame($local, $servico->consultar($cep, $this->em('10:00'))['local'], $cep);
        }
    }

    public function test_cep_fora_da_area_ou_invalido_nao_tem_entrega_expressa(): void
    {
        $servico = app(EntregaExpressa::class);

        foreach (['20040-020', '13010-000', '06480-000', '08500-000', '123', '', null] as $cep) {
            $this->assertNull($servico->consultar($cep, $this->em('10:00')), (string) $cep);
        }
    }

    public function test_desligada_pela_config_nao_atende_ninguem(): void
    {
        config(['entrega_expressa.ativa' => false]);

        $this->assertNull(app(EntregaExpressa::class)->consultar('01001-000', $this->em('10:00')));
    }

    public function test_endpoint_de_prazo_da_pagina_do_produto(): void
    {
        Carbon::setTestNow($this->em('09:00'));

        $this->getJson('/frete/prazo?cep=01001-000')
            ->assertOk()
            ->assertJsonPath('entrega_expressa.mensagem', 'Receba hoje até as 21h');

        $this->getJson('/frete/prazo?cep=20040-020')
            ->assertOk()
            ->assertJsonPath('entrega_expressa', null);

        $this->getJson('/frete/prazo')->assertStatus(422);

        Carbon::setTestNow();
    }

    /** Turbo de motoboy (pedido 2026-10-24): área do Full, seg a sex em horário comercial e sábado até 13h. */
    public function test_motoboy_so_na_area_e_em_horario_comercial(): void
    {
        $servico = app(EntregaExpressa::class);
        $dia = fn (string $data) => Carbon::parse($data, 'America/Sao_Paulo');

        // 2026-10-09 é sexta; 10 sábado; 11 domingo.
        $this->assertSame(['preco' => 39.0, 'mensagem' => 'Receba em até 3 horas'], $servico->motoboy('01001-000', $dia('2026-10-09 10:00')));
        $this->assertNotNull($servico->motoboy('01001-000', $dia('2026-10-10 12:59')));
        $this->assertNull($servico->motoboy('01001-000', $dia('2026-10-10 13:00')));
        $this->assertNull($servico->motoboy('01001-000', $dia('2026-10-11 10:00')));
        $this->assertNull($servico->motoboy('01001-000', $dia('2026-10-09 18:00')));
        $this->assertNull($servico->motoboy('01001-000', $dia('2026-10-09 07:59')));
        $this->assertNull($servico->motoboy('20040-020', $dia('2026-10-09 10:00')));
    }

    public function test_fim_de_semana_e_sabado_depois_das_13h_ou_domingo(): void
    {
        $servico = app(EntregaExpressa::class);
        $dia = fn (string $data) => Carbon::parse($data, 'America/Sao_Paulo');

        $this->assertFalse($servico->fimDeSemana($dia('2026-10-09 22:00')));
        $this->assertFalse($servico->fimDeSemana($dia('2026-10-10 12:59')));
        $this->assertTrue($servico->fimDeSemana($dia('2026-10-10 13:00')));
        $this->assertTrue($servico->fimDeSemana($dia('2026-10-11 09:00')));
        // 15:30 UTC de sábado = 12:30 em Brasília: ainda não.
        $this->assertFalse($servico->fimDeSemana(Carbon::parse('2026-10-10 15:30', 'UTC')));
    }

    public function test_endpoint_de_prazo_manda_motoboy_e_fim_de_semana(): void
    {
        Carbon::setTestNow($this->em('09:00'));
        $this->getJson('/frete/prazo?cep=01001-000')->assertJsonPath('motoboy.preco', 39)->assertJsonPath('fim_de_semana', false);
        $this->getJson('/frete/prazo?cep=20040-020')->assertJsonPath('motoboy', null);

        Carbon::setTestNow(Carbon::parse('2026-10-11 10:00', 'America/Sao_Paulo'));
        $this->getJson('/frete/prazo?cep=01001-000')->assertJsonPath('motoboy', null)->assertJsonPath('fim_de_semana', true);

        Carbon::setTestNow();
    }

    public function test_cotacao_do_checkout_devolve_a_entrega_expressa(): void
    {
        Carbon::setTestNow($this->em('14:00'));

        $this->postJson('/finalizacao/frete', ['zip' => '01001-000'])
            ->assertOk()
            ->assertJsonPath('entrega_expressa.mensagem', 'Receba amanhã');

        Carbon::setTestNow();
    }
}
