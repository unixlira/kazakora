<?php

namespace Tests\Feature\Marketplace;

use App\Models\User;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosRastroService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Pedido do Lira 2026-11-16: AP623701379BR (pedido Amazon #3181, de 16/10)
 * não aparecia na busca de novembro e expirou sem ninguém ver.
 */
class CorreiosPrePostagemRastreioTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, array<string, mixed>> */
    private array $respostas = [];

    /** @var array<int, string> */
    private array $consultados = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-16 10:00:00');

        $this->mock(CorreiosRastroService::class)
            ->shouldReceive('consultar')
            ->andReturnUsing(function (string $codigo) {
                $this->consultados[] = $codigo;

                return $this->respostas[$codigo] ?? $this->rastreio();
            });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => User::ROLE_ADMIN]);
    }

    private function pre(array $extra = [], ?string $criadaEm = null): CorreiosPrePostagem
    {
        $pre = CorreiosPrePostagem::create(array_merge([
            'customer_name' => 'Cliente Teste',
            'origin' => 'amazon',
            'zip' => '13480-000',
            'street' => 'Rua Teste',
            'number' => '10',
            'neighborhood' => 'Centro',
            'city' => 'Limeira',
            'state' => 'SP',
            'service_code' => '03298',
            'service_label' => 'PAC (contrato)',
            'weight_grams' => 300,
            'content_items' => [['conteudo' => 'Power Bank', 'quantidade' => 1, 'valor' => 50]],
            'status' => CorreiosPrePostagem::STATUS_GERADA,
            'codigo_objeto' => 'AP'.random_int(100000000, 999999999).'BR',
        ], $extra));

        if ($criadaEm) {
            $pre->created_at = Carbon::parse($criadaEm);
            $pre->save();
        }

        return $pre;
    }

    /**
     * @return array<string, mixed>
     */
    private function rastreio(array $extra = []): array
    {
        return array_merge([
            'postado' => false, 'postado_em' => null, 'entregue' => false, 'entregue_em' => null,
            'recebedor' => null, 'previsao' => null, 'ultimo' => 'Etiqueta emitida',
            'eventos' => [['codigo' => 'FC', 'tipo' => '82', 'descricao' => 'Etiqueta emitida', 'data' => '2026-10-16T09:00:00-03:00', 'local' => null]],
        ], $extra);
    }

    private function expirada(): array
    {
        return $this->rastreio([
            'ultimo' => 'Etiqueta expirada',
            'eventos' => [
                ['codigo' => 'FC', 'tipo' => '10', 'descricao' => 'Etiqueta expirada', 'data' => '2026-11-05T08:00:00-03:00', 'local' => null],
                ['codigo' => 'FC', 'tipo' => '82', 'descricao' => 'Etiqueta emitida', 'data' => '2026-10-16T09:00:00-03:00', 'local' => null],
            ],
        ]);
    }

    public function test_busca_procura_em_todo_o_historico_sem_limitar_ao_mes(): void
    {
        $this->pre(['codigo_objeto' => 'AP623701379BR', 'external_order_id' => '702-1111111-3181', 'customer_name' => 'Elci'], '2026-10-16 09:00:00');
        $this->pre(['customer_name' => 'Outra Cliente']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/correios')
            ->assertInertia(fn ($page) => $page->has('items.data', 1)->where('filters.todoHistorico', false));

        foreach (['AP623701379BR', '702-1111111-3181', 'elci'] as $termo) {
            $this->actingAs($admin)->get('/admin/correios?mes=2026-11&pedido='.$termo)
                ->assertInertia(fn ($page) => $page
                    ->has('items.data', 1)
                    ->where('items.data.0.codigoObjeto', 'AP623701379BR')
                    ->where('filters.todoHistorico', true));
        }
    }

    public function test_comando_grava_entregue_e_expirada_e_ignora_as_antigas_e_as_ja_resolvidas(): void
    {
        $entregue = $this->pre(['codigo_objeto' => 'AP000000001BR'], '2026-11-01 10:00:00');
        $expirada = $this->pre(['codigo_objeto' => 'AP623701379BR'], '2026-10-16 09:00:00');
        $antiga = $this->pre(['codigo_objeto' => 'AP000000003BR'], '2026-08-01 10:00:00');
        $cancelada = $this->pre(['codigo_objeto' => 'AP000000004BR', 'status' => CorreiosPrePostagem::STATUS_CANCELADA]);
        $jaEntregue = $this->pre(['codigo_objeto' => 'AP000000005BR', 'entregue_em' => '2026-11-10 10:00:00', 'rastreio_situacao' => 'entregue']);

        $this->respostas = [
            'AP000000001BR' => $this->rastreio([
                'postado' => true, 'postado_em' => Carbon::parse('2026-11-02 11:00:00'),
                'entregue' => true, 'entregue_em' => Carbon::parse('2026-11-06 15:30:00'),
                'ultimo' => 'Objeto entregue ao destinatário',
            ]),
            'AP623701379BR' => $this->expirada(),
        ];

        $this->artisan('correios:acompanhar-pre-postagens')->assertSuccessful();

        sort($this->consultados);
        $this->assertSame(['AP000000001BR', 'AP623701379BR'], $this->consultados);

        $entregue->refresh();
        $this->assertSame(CorreiosPrePostagem::SITUACAO_ENTREGUE, $entregue->rastreio_situacao);
        $this->assertSame('2026-11-02 11:00', $entregue->postada_em->format('Y-m-d H:i'));
        $this->assertSame('2026-11-06 15:30', $entregue->entregue_em->format('Y-m-d H:i'));
        $this->assertSame('Objeto entregue ao destinatário', $entregue->rastreio_ultimo_evento);
        $this->assertNotNull($entregue->rastreio_consultado_em);

        $expirada->refresh();
        $this->assertSame(CorreiosPrePostagem::SITUACAO_EXPIRADA, $expirada->rastreio_situacao);
        $this->assertSame('2026-11-05', $expirada->expirada_em->format('Y-m-d'));
        $this->assertNull($expirada->postada_em);
        $this->assertSame('Etiqueta expirada', $expirada->rastreio_ultimo_evento);
        $this->assertCount(2, $expirada->rastreio_eventos);

        $this->assertNull($antiga->fresh()->rastreio_consultado_em);
        $this->assertNull($cancelada->fresh()->rastreio_consultado_em);
        $this->assertNull($jaEntregue->fresh()->rastreio_consultado_em);

        // Já resolvidas não voltam pra fila.
        $this->consultados = [];
        $this->artisan('correios:acompanhar-pre-postagens')->assertSuccessful();
        $this->assertSame([], $this->consultados);
    }

    public function test_comando_respeita_o_teto_e_gira_a_fila(): void
    {
        $a = $this->pre(['codigo_objeto' => 'AP000000011BR']);
        $b = $this->pre(['codigo_objeto' => 'AP000000012BR']);

        $this->artisan('correios:acompanhar-pre-postagens', ['--limite' => 1])->assertSuccessful();
        $this->artisan('correios:acompanhar-pre-postagens', ['--limite' => 1])->assertSuccessful();

        $this->assertSame(['AP000000011BR', 'AP000000012BR'], $this->consultados);
        $this->assertSame(CorreiosPrePostagem::SITUACAO_PRE_POSTADA, $a->fresh()->rastreio_situacao);
        $this->assertSame(CorreiosPrePostagem::SITUACAO_PRE_POSTADA, $b->fresh()->rastreio_situacao);
    }

    public function test_comando_a_mao_para_um_codigo(): void
    {
        $pre = $this->pre(['codigo_objeto' => 'AP623701379BR'], '2026-06-01 10:00:00');
        $this->respostas['AP623701379BR'] = $this->expirada();

        $this->artisan('correios:acompanhar-pre-postagens', ['--codigo' => 'ap623701379br'])
            ->expectsOutputToContain('expirada')
            ->assertSuccessful();

        $this->assertSame(CorreiosPrePostagem::SITUACAO_EXPIRADA, $pre->fresh()->rastreio_situacao);
    }

    public function test_tela_mostra_situacao_historico_filtro_e_duplicada(): void
    {
        $expirada = $this->pre([
            'codigo_objeto' => 'AP623701379BR', 'customer_name' => 'Elci', 'external_order_id' => '3181',
        ], '2026-10-16 09:00:00');
        $this->respostas['AP623701379BR'] = $this->expirada();
        $this->artisan('correios:acompanhar-pre-postagens', ['--codigo' => 'AP623701379BR']);

        $irma = $this->pre([
            'codigo_objeto' => 'AP623701380BR', 'customer_name' => 'ELCI ', 'zip' => '13480000', 'external_order_id' => '3180',
            'rastreio_situacao' => 'postada', 'postada_em' => '2026-10-16 14:00:00',
        ], '2026-10-16 11:00:00');
        $this->pre(['codigo_objeto' => 'AP623701381BR', 'customer_name' => 'Elci', 'number' => '99'], '2026-10-16 12:00:00');

        $admin = $this->admin();

        $this->actingAs($admin)->get('/admin/correios?situacao=nao_postadas')
            ->assertInertia(fn ($page) => $page
                ->has('items.data', 2)
                ->where('items.data.1.codigoObjeto', 'AP623701379BR')
                ->where('items.data.1.situacao', 'expirada')
                ->where('items.data.1.situacaoLabel', 'Expirada')
                ->where('items.data.1.situacaoTone', 'red')
                ->where('items.data.1.expiradaEm', '05/11/2026 08:00'));

        $this->actingAs($admin)->get('/admin/correios?pedido=AP623701380BR')
            ->assertInertia(fn ($page) => $page->where('items.data.0.situacaoLabel', 'Postada'));

        $this->actingAs($admin)->get("/admin/correios/{$expirada->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Correios/Show', false)
                ->where('item.situacaoLabel', 'Expirada')
                ->where('item.historico.0.descricao', 'Etiqueta gerada no sistema')
                ->where('item.historico.2.descricao', 'Etiqueta expirada')
                ->where('item.historico.2.tom', 'red')
                ->has('item.duplicadas', 1)
                ->where('item.duplicadas.0.id', $irma->id)
                ->where('item.duplicadas.0.codigoObjeto', 'AP623701380BR'));
    }
}
