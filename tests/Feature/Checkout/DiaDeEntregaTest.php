<?php

namespace Tests\Feature\Checkout;

use App\Modules\Checkout\Support\DiaDeEntrega;
use App\Modules\Checkout\Support\EntregaExpressa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dia de entrega de verdade (pedido do Lira 2026-10-24): sábado à noite e
 * domingo não têm entrega, então nada de "Receba amanhã". Full de segunda a
 * sábado (corte 13h), Correios só em dia útil, feriado nacional fora.
 */
class DiaDeEntregaTest extends TestCase
{
    use RefreshDatabase;

    private function em(string $data): Carbon
    {
        return Carbon::parse($data, 'America/Sao_Paulo');
    }

    public function test_full_sabado_a_noite_e_domingo_recebe_segunda(): void
    {
        $servico = app(EntregaExpressa::class);

        // 24/10/2026 é sábado; 25 domingo; 26 segunda.
        foreach (['2026-10-24 13:00', '2026-10-24 22:00', '2026-10-25 09:00', '2026-10-25 23:59'] as $quando) {
            $r = $servico->consultar('01001-000', $this->em($quando));
            $this->assertSame('Receba SEGUNDA', $r['mensagem'], $quando);
            $this->assertSame(EntregaExpressa::PROXIMO, $r['tipo'], $quando);
            $this->assertSame('2026-10-26', $r['dia'], $quando);
        }
    }

    public function test_full_sabado_de_manha_ainda_e_hoje_e_sexta_a_tarde_e_amanha(): void
    {
        $servico = app(EntregaExpressa::class);

        $this->assertSame('Receba hoje até as 21h', $servico->consultar('01001-000', $this->em('2026-10-24 12:59'))['mensagem']);
        $this->assertSame('Receba amanhã', $servico->consultar('01001-000', $this->em('2026-10-23 15:00'))['mensagem']);
    }

    public function test_feriado_pula_para_o_proximo_dia(): void
    {
        $servico = app(EntregaExpressa::class);

        // 12/10/2026 (segunda) é feriado: sábado à noite vai para terça.
        $this->assertSame('Receba TERÇA', $servico->consultar('01001-000', $this->em('2026-10-10 20:00'))['mensagem']);
        // 02/11/2026 (segunda, Finados): domingo 01/11 vai para terça.
        $this->assertSame('Receba TERÇA', $servico->consultar('01001-000', $this->em('2026-11-01 10:00'))['mensagem']);
        // No próprio feriado não sai no mesmo dia, e o dia vem pelo nome.
        $this->assertSame('Receba TERÇA', $servico->consultar('01001-000', $this->em('2026-11-02 09:00'))['mensagem']);
    }

    public function test_flex_e_turbo_dos_correios_contam_dias_uteis(): void
    {
        $dia = app(DiaDeEntrega::class);

        $this->assertSame('Receba de 1 a 7 dias', $dia->fraseFlex($this->em('2026-10-27 10:00')));
        // Fim de semana: os Correios postam na segunda e entregam na terça.
        $this->assertSame('Receba a partir de TERÇA', $dia->fraseFlex($this->em('2026-10-24 22:00')));
        $this->assertSame('Receba a partir de TERÇA', $dia->fraseFlex($this->em('2026-10-25 10:00')));
        $this->assertSame('Receba a partir de SEGUNDA', $dia->fraseFlex($this->em('2026-10-23 10:00')));

        $this->assertSame('Receba em 2 dias', $dia->fraseCorreios(2, $this->em('2026-10-26 10:00')));
        $this->assertSame('Receba TERÇA', $dia->fraseCorreios(1, $this->em('2026-10-24 10:00')));
        // Quarta + 3 dias úteis = segunda (o fim de semana não conta).
        $this->assertSame('Receba SEGUNDA', $dia->fraseCorreios(3, $this->em('2026-10-21 10:00')));
        // Quarta 28/10 + 3 dias úteis pula Finados (02/11): terça.
        $this->assertSame('Receba TERÇA', $dia->fraseCorreios(3, $this->em('2026-10-28 10:00')));
        // Longe demais para o nome do dia: data.
        $this->assertSame('Receba em 06/11', $dia->fraseCorreios(8, $this->em('2026-10-26 10:00')));
    }

    public function test_pagina_do_produto_no_sabado_a_noite(): void
    {
        Carbon::setTestNow($this->em('2026-10-24 21:30'));

        // Grande SP: o grátis sai pelo Full, na segunda.
        $this->getJson('/frete/prazo?cep=01001-000')
            ->assertJsonPath('entrega_expressa.mensagem', 'Receba SEGUNDA')
            ->assertJsonPath('flex_mensagem', 'Receba SEGUNDA');
        // Fora dela: Correios postam na segunda.
        $this->getJson('/frete/prazo?cep=20040-020')->assertJsonPath('flex_mensagem', 'Receba a partir de TERÇA');

        Carbon::setTestNow();
    }

    public function test_hora_em_utc_vira_brasilia(): void
    {
        // 02:00 UTC de domingo = 23:00 de sábado em Brasília: segunda.
        $this->assertSame('Receba SEGUNDA', app(EntregaExpressa::class)->consultar('01001-000', Carbon::parse('2026-10-25 02:00', 'UTC'))['mensagem']);
    }
}
