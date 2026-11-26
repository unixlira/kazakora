<?php

namespace Tests\Feature\Seguranca;

use Tests\TestCase;

/** CORS de /api/* só para os sites próprios (auditoria 2026-11-25). */
class CorsTest extends TestCase
{
    private function preflight(string $origem)
    {
        return $this->call('OPTIONS', '/api/ferrakora/catalogo', [], [], [], [
            'HTTP_ORIGIN' => $origem,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        ]);
    }

    public function test_sites_proprios_sao_liberados(): void
    {
        foreach (['https://kazakora.com', 'https://ferrakora.com', 'https://www.ferrakora.com', 'https://app.kazakora.com', 'https://ferrakora.devlira.com.br'] as $origem) {
            $this->assertSame($origem, $this->preflight($origem)->headers->get('Access-Control-Allow-Origin'), $origem);
        }
    }

    public function test_origem_de_fora_nao_recebe_liberacao(): void
    {
        foreach (['https://site-malicioso.com', 'https://kazakora.com.evil.io', 'http://kazakora.com', 'https://devlira.com.br.evil.io'] as $origem) {
            $this->assertNotContains(
                $this->preflight($origem)->headers->get('Access-Control-Allow-Origin'),
                [$origem, '*'],
                $origem,
            );
        }
    }
}
