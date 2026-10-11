<?php

namespace Tests\Feature\Loja;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** loja:emails-teste manda um exemplo de cada e-mail sem gravar nada (pedido 2026-10-10). */
class EmailsDeTesteTest extends TestCase
{
    use RefreshDatabase;

    public function test_manda_todos_os_exemplos(): void
    {
        Mail::fake();

        $this->artisan('loja:emails-teste', ['email' => 'dono@exemplo.com'])
            ->doesntExpectOutputToContain('✘')
            ->assertSuccessful();

        Mail::assertSentCount(7);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('cart_snapshots', 0);
    }
}
