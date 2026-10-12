<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegalPagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function pages(): array
    {
        return [
            'trocas e devoluções' => ['/trocas-e-devolucoes'],
            'política de privacidade' => ['/politica-de-privacidade'],
            'termos de uso' => ['/termos-de-uso'],
        ];
    }

    #[DataProvider('pages')]
    public function test_legal_page_is_publicly_accessible_without_auth(string $uri): void
    {
        $this->get($uri)->assertOk();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function legacyUrls(): array
    {
        return [
            'quem somos' => ['/quem-somos', '/'],
            'quem somos com barra' => ['/quem-somos/', '/'],
            'produtos' => ['/produtos', '/?todos=1'],
            'política de frete' => ['/politica-de-frete', '/politica-de-entrega'],
            'contato' => ['/contato', '/fale-conosco'],
        ];
    }

    #[DataProvider('legacyUrls')]
    public function test_legacy_store_url_redirects_permanently(string $uri, string $destino): void
    {
        $this->get($uri)->assertStatus(301)->assertRedirect($destino);
    }
}
