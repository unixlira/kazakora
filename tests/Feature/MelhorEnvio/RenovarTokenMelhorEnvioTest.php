<?php

namespace Tests\Feature\MelhorEnvio;

use App\Modules\Admin\Models\AlertaAdmin;
use App\Models\MelhorEnvioToken;
use App\Modules\Admin\Support\AlertasAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RenovarTokenMelhorEnvioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.melhorenvio.token_url' => 'https://me.test/oauth/token']);
    }

    private function token(int $diasParaVencer): MelhorEnvioToken
    {
        return MelhorEnvioToken::create([
            'account_label' => 'KazaKora ME', 'access_token' => 'acesso', 'refresh_token' => 'r1',
            'token_expires_at' => now()->addDays($diasParaVencer), 'scopes' => [],
        ]);
    }

    public function test_renova_antes_de_vencer_mesmo_sem_uso(): void
    {
        Http::fake(['me.test/*' => Http::response(['access_token' => 'novo', 'refresh_token' => 'r2', 'expires_in' => 2592000])]);
        $token = $this->token(10);

        $this->artisan('melhorenvio:renovar-token')->assertSuccessful();

        $this->assertSame('r2', $token->fresh()->refresh_token);
        $this->assertTrue($token->fresh()->token_expires_at->gt(now()->addDays(29)));
    }

    public function test_longe_de_vencer_nao_chama_a_api(): void
    {
        Http::fake();
        $this->token(25);

        $this->artisan('melhorenvio:renovar-token')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_falha_na_renovacao_avisa_no_sino(): void
    {
        Http::fake(['me.test/*' => Http::response(['error' => 'invalid_request', 'message' => 'The refresh token is invalid.'], 401)]);
        $this->token(1);

        $this->artisan('melhorenvio:renovar-token')->assertFailed();

        $this->assertTrue(AlertaAdmin::query()->where('tipo', AlertasAdmin::MELHOR_ENVIO_CONEXAO)->exists());
    }

    public function test_volta_da_autorizacao_sem_login_conecta_com_state_valido_e_recusa_sem(): void
    {
        Http::fake([
            'me.test/oauth/token' => Http::response(['access_token' => 'novo', 'refresh_token' => 'r9', 'expires_in' => 2592000]),
            '*' => Http::response(['firstname' => 'Kaza', 'lastname' => 'Kora', 'email' => 'x@example.com']),
        ]);
        $this->token(-60);

        $this->get('/api/melhorenvio/callback?code=abc&state=inventado')->assertRedirect('/admin/logistica');
        $this->assertSame('r1', MelhorEnvioToken::current()->refresh_token);

        parse_str(parse_url(app(\App\Services\MelhorEnvio\MelhorEnvioAuthService::class)->getAuthorizationUrl(), PHP_URL_QUERY), $q);
        $this->get('/api/melhorenvio/callback?code=abc&state='.$q['state'])->assertRedirect('/admin/logistica');
        $this->assertSame('r9', MelhorEnvioToken::current()->refresh_token);
        $this->assertSame(\App\Support\FreteProvedor::CORREIOS, \App\Support\FreteProvedor::escolhido());

        // State é de uso único.
        $this->get('/api/melhorenvio/callback?code=abc&state='.$q['state']);
        $this->assertSame(1, MelhorEnvioToken::query()->count());
    }

    public function test_callback_na_loja_mostra_pagina_em_vez_de_404_do_admin(): void
    {
        // 2026-11-09: a volta caía em kazakora.com/admin/logistica (404 no nginx).
        $this->get('http://kazakora.com/api/melhorenvio/callback?error=invalid_scope&error_description=escopo+cortado')
            ->assertStatus(422)
            ->assertSee('escopo cortado');

        parse_str(parse_url(app(\App\Services\MelhorEnvio\MelhorEnvioAuthService::class)->getAuthorizationUrl(), PHP_URL_QUERY), $q);
        \Illuminate\Support\Facades\Http::fake([
            '*oauth/token*' => \Illuminate\Support\Facades\Http::response(['access_token' => 'a2', 'refresh_token' => 'r2', 'expires_in' => 2592000]),
            '*' => \Illuminate\Support\Facades\Http::response(['firstname' => 'Kaza', 'lastname' => 'Kora', 'email' => 'x@y.z']),
        ]);
        $this->get('http://kazakora.com/api/melhorenvio/callback?code=abc&state='.$q['state'])
            ->assertOk()
            ->assertSee('Melhor Envio conectado');
    }

    public function test_link_de_autorizacao_continua_exigindo_admin(): void
    {
        $this->get('/api/melhorenvio/auth')->assertStatus(401);
    }
}
