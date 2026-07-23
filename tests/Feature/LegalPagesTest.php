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
}
