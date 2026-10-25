<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Medição do checkout (pedido do Lira 2026-10-23): POST /finalizacao/eventos
 * aceita só a lista fechada de eventos, nunca grava o que foi digitado, e o
 * checkout:funil lê sem equipe.
 */
class CheckoutEventosTest extends TestCase
{
    use RefreshDatabase;

    private const CELULAR = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';

    private const COMPUTADOR = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'http://localhost']);
    }

    private function enviar(array $corpo, string $agente = self::CELULAR, array $cabecalhos = []): TestResponse
    {
        return $this->call('POST', '/finalizacao/eventos', [], [], [], [
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => $agente,
            ...$cabecalhos,
        ], is_string($corpo['_bruto'] ?? null) ? $corpo['_bruto'] : json_encode($corpo));
    }

    public function test_grava_lote_valido_sem_csrf(): void
    {
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'abriu'],
            ['evento' => 'rolagem', 'valor' => 50],
            ['evento' => 'foco', 'campo' => 'nome'],
            ['evento' => 'preencheu', 'campo' => 'nome'],
            ['evento' => 'pagamento', 'campo' => 'cartao'],
            ['evento' => 'erro', 'campo' => 'cpf'],
            ['evento' => 'saiu', 'segundos' => 42, 'rolagem' => 75],
        ]])->assertNoContent();

        $this->assertSame(7, CheckoutEvento::count());
        $saiu = CheckoutEvento::firstWhere('evento', 'saiu');
        $this->assertSame(42, $saiu->valor);
        $this->assertSame(75, $saiu->rolagem);
        $this->assertSame('celular', $saiu->dispositivo);
        $this->assertSame('abc123def456', $saiu->pagina_id);
        $this->assertStringStartsWith('n-', $saiu->visitor_id);
        $this->assertSame('nome', CheckoutEvento::firstWhere('evento', 'preencheu')->campo);
    }

    public function test_nunca_grava_o_valor_digitado(): void
    {
        // Chave a mais (o texto digitado) recusa o lote inteiro.
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'abriu'],
            ['evento' => 'preencheu', 'campo' => 'nome', 'valor' => 'Maria da Silva'],
        ]])->assertStatus(422);
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'preencheu', 'campo' => 'nome', 'texto' => 'Maria da Silva'],
        ]])->assertStatus(422);
        // Campo fora da lista (tentar passar o valor no lugar do nome do campo).
        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [
            ['evento' => 'preencheu', 'campo' => '123.456.789-09'],
        ]])->assertStatus(422);

        $this->assertSame(0, CheckoutEvento::count());

        $this->enviar(['pagina' => 'abc123def456', 'eventos' => [['evento' => 'preencheu', 'campo' => 'cpf']]])->assertNoContent();
        $linha = CheckoutEvento::sole()->getAttributes();
        $this->assertNull($linha['valor']);
        $this->assertArrayNotHasKey('ip', $linha);
    }

    public function test_recusa_evento_ou_formato_invalido(): void
    {
        $invalidos = [
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'pedido_criado']]],
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'qualquer']]],
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'rolagem', 'valor' => 33]]],
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'saiu', 'segundos' => -1, 'rolagem' => 10]]],
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu', 'campo' => 'nome']]],
            ['pagina' => 'abc123def456', 'eventos' => []],
            ['pagina' => 'ABC!', 'eventos' => [['evento' => 'abriu']]],
            ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']], 'extra' => 1],
            ['pagina' => 'abc123def456', 'eventos' => array_fill(0, 31, ['evento' => 'abriu'])],
            ['_bruto' => 'não é json'],
        ];

        foreach ($invalidos as $corpo) {
            $this->enviar($corpo)->assertStatus(422);
        }

        $this->enviar(['_bruto' => str_repeat('a', 9000)])->assertStatus(413);
        $this->assertSame(0, CheckoutEvento::count());
    }

    public function test_recusa_outro_site_e_ignora_robo(): void
    {
        $lote = ['pagina' => 'abc123def456', 'eventos' => [['evento' => 'abriu']]];

        $this->enviar($lote, self::CELULAR, ['HTTP_ORIGIN' => 'https://site-malicioso.com'])->assertForbidden();
        $this->enviar($lote, self::CELULAR, ['HTTP_SEC_FETCH_SITE' => 'cross-site'])->assertForbidden();
        $this->enviar($lote, 'curl/8.0')->assertNoContent();

        $this->assertSame(0, CheckoutEvento::count());
    }

    public function test_funil_conta_por_visitante_e_tira_equipe(): void
    {
        foreach (['c1', 'c2'] as $v) {
            CheckoutEvento::create(['visitor_id' => $v, 'evento' => 'abriu', 'dispositivo' => 'celular']);
        }
        CheckoutEvento::create(['visitor_id' => 'c2', 'evento' => 'finalizar', 'dispositivo' => 'celular']);
        CheckoutEvento::create(['visitor_id' => 'c2', 'evento' => CheckoutEvento::PEDIDO_CRIADO, 'dispositivo' => 'celular']);
        CheckoutEvento::create(['visitor_id' => 'c1', 'pagina_id' => 'x', 'evento' => 'saiu', 'valor' => 12, 'rolagem' => 40, 'dispositivo' => 'celular']);
        CheckoutEvento::create(['visitor_id' => 'c1', 'pagina_id' => 'x', 'evento' => 'saiu', 'valor' => 20, 'rolagem' => 40, 'dispositivo' => 'celular']);
        $equipe = User::factory()->create(['role' => User::STAFF_ROLES[0]]);
        CheckoutEvento::create(['visitor_id' => 'eq', 'user_id' => $equipe->id, 'evento' => 'abriu', 'dispositivo' => 'celular']);

        $this->artisan('checkout:funil')
            ->expectsOutputToContain('Funil do checkout')
            ->expectsTable(['Etapa', 'celular', 'total'], array_merge(
                [
                    ['Abriram', '2 (100%)', '2 (100%)'],
                    ['Viram o formulário', '0 (0%)', '0 (0%)'],
                    ['Rolaram 50%+', '0 (0%)', '0 (0%)'],
                    ['Focaram 1º campo', '0 (0%)', '0 (0%)'],
                ],
                array_map(fn ($c) => ["  preencheu {$c}", '0 (0%)', '0 (0%)'], CheckoutEvento::CAMPOS),
                [
                    ['CEP respondido (frete)', '0 (0%)', '0 (0%)'],
                    ['Escolheram pagamento', '0 (0%)', '0 (0%)'],
                    ['Clicaram Finalizar', '1 (50%)', '1 (50%)'],
                    ['Viram erro', '0 (0%)', '0 (0%)'],
                    ['Pedido criado', '1 (50%)', '1 (50%)'],
                ],
            ))
            ->expectsTable(['Quem saiu sem pedido', 'celular', 'total'], [
                ['Visitantes', 1, 1],
                ['Mediana de tempo ativo (s)', '20', '20'],
                ['Mediana de rolagem (%)', '40', '40'],
                ['Sem digitar nada', 1, 1],
            ])
            ->assertSuccessful();
    }
}
