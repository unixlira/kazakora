<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class IpRealCloudflareTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_teste-ip', fn (Request $request) => $request->ip());
    }

    public function test_vindo_do_cloudflare_usa_o_ip_do_visitante(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '104.22.17.76'])
            ->withHeader('X-Forwarded-For', '189.40.1.2')
            ->get('/_teste-ip')
            ->assertSee('189.40.1.2');
    }

    public function test_ip_forjado_antes_do_cloudflare_e_ignorado(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '104.22.17.76'])
            ->withHeader('X-Forwarded-For', '1.1.1.1, 189.40.1.2')
            ->get('/_teste-ip')
            ->assertSee('189.40.1.2')
            ->assertDontSee('1.1.1.1');
    }

    public function test_fora_do_cloudflare_o_cabecalho_e_ignorado(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '200.10.10.10'])
            ->withHeader('X-Forwarded-For', '189.40.1.2')
            ->get('/_teste-ip')
            ->assertSee('200.10.10.10')
            ->assertDontSee('189.40.1.2');
    }
}
