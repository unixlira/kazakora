<?php

namespace Tests\Feature\Admin;

use App\Modules\Admin\Models\AlertaAdmin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DispararAlertaAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_abre_alerta_de_trafego_e_manda_email(): void
    {
        Mail::fake();

        $this->artisan('alertas:disparar', ['tipo' => 'trafego_meta', 'chave' => 'conjunto-1', 'titulo' => 'Desligar M03', 'mensagem' => 'Gastou o CPA máximo sem vender.', '--email' => ['lira@exemplo.com']])
            ->assertSuccessful();

        $this->assertSame(1, AlertaAdmin::query()->where('tipo', 'trafego_meta')->count());
    }

    public function test_tipo_desconhecido_nao_abre_nada(): void
    {
        $this->artisan('alertas:disparar', ['tipo' => 'xyz', 'chave' => 'a', 'titulo' => 't', 'mensagem' => 'm'])->assertFailed();
        $this->assertSame(0, AlertaAdmin::query()->count());
    }
}
