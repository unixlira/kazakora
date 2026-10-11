<?php

namespace Tests\Feature\Loja;

use App\Modules\Contato\Mail\MensagemDoSite;
use App\Modules\Contato\Models\MensagemContato;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Fale conosco > Mandar mensagem (pedido 2026-10-10). */
class FaleConoscoTest extends TestCase
{
    use RefreshDatabase;

    private function dados(array $extra = []): array
    {
        return [
            'nome' => 'Maria',
            'email' => 'maria@exemplo.com',
            'assunto' => 'Prazo de entrega',
            'mensagem' => 'Comprando hoje, chega até sexta?',
            'site' => '',
            'form_token' => Crypt::encryptString((string) now()->subSeconds(30)->timestamp),
            ...$extra,
        ];
    }

    public function test_pagina_abre_com_token(): void
    {
        $this->get('/fale-conosco')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Contato/Mensagem', false)
            ->whereType('formToken', 'string'));
    }

    public function test_mensagem_vai_para_o_email_do_dono_com_assunto(): void
    {
        Mail::fake();

        $this->post('/fale-conosco', $this->dados())->assertRedirect('/fale-conosco')->assertSessionHas('success');

        Mail::assertSent(MensagemDoSite::class, function (MensagemDoSite $mail) {
            $mail->build();

            return $mail->hasTo(config('loja.email_contato'))
                && $mail->subject === 'Mensagem do site: Prazo de entrega'
                && $mail->hasReplyTo('maria@exemplo.com');
        });
        $this->assertNotNull(MensagemContato::firstOrFail()->enviado_em);
    }

    public function test_robo_que_preenche_o_campo_escondido_e_descartado(): void
    {
        Mail::fake();

        $this->post('/fale-conosco', $this->dados(['site' => 'http://spam.example']))->assertSessionHas('success');

        Mail::assertNothingSent();
        $this->assertSame(0, MensagemContato::count());
    }

    public function test_envio_rapido_demais_e_recusado(): void
    {
        Mail::fake();

        $this->post('/fale-conosco', $this->dados(['form_token' => Crypt::encryptString((string) now()->timestamp)]))
            ->assertSessionHasErrors('mensagem');
        $this->post('/fale-conosco', $this->dados(['form_token' => 'forjado']))->assertSessionHasErrors('mensagem');

        Mail::assertNothingSent();
    }

    public function test_mensagem_cheia_de_links_e_recusada(): void
    {
        Mail::fake();

        $this->post('/fale-conosco', $this->dados(['mensagem' => 'veja http://a.com http://b.com http://c.com']))
            ->assertSessionHasErrors('mensagem');

        Mail::assertNothingSent();
    }

    public function test_limite_de_envios_por_ip(): void
    {
        Mail::fake();

        foreach (range(1, 5) as $i) {
            $this->post('/fale-conosco', $this->dados())->assertRedirect();
        }

        $this->post('/fale-conosco', $this->dados())->assertStatus(429);
    }
}
