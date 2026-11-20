<?php

namespace Tests\Feature;

use App\Modules\Financeiro\Support\PrevisaoFornecedores;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailPrevisaoFornecedoresTest extends TestCase
{
    public function test_no_email_before_start_or_future_date(): void
    {
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 11, 20));
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        $this->artisan('financeiro:fornecedores-d10 --data=2026-11-23')->assertFailed();
        Mail::assertNothingSent();
    }

    public function test_mail_failure_never_persists_sent_state(): void
    {
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 0));
        $this->app->instance('env', 'production');
        $this->mock(PrevisaoFornecedores::class, function ($m) {
            $m->shouldReceive('elegivel')->andReturn(true);
            $m->shouldReceive('calcular')->andReturn([]);
            $m->shouldReceive('texto')->andReturn('Previsão');
            $m->shouldReceive('csv')->andReturn('CSV');
        });
        Mail::shouldReceive('to')->once()->andThrow(new \RuntimeException('SMTP indisponível'));
        try {
            $this->artisan('financeiro:fornecedores-d10')->run();
            self::fail('Deveria propagar falha SMTP');
        } catch (\RuntimeException $e) {
            self::assertSame('SMTP indisponível', $e->getMessage());
        }
        Storage::disk('local')->assertMissing('automation/fornecedores-d10/2026-11-23.json');
    }

    public function test_dry_run_does_not_send_or_persist_and_live_is_idempotent(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->travelTo(now()->setDate(2026, 11, 23)->setTime(6, 0));
        $this->mock(PrevisaoFornecedores::class, function ($m) {
            $m->shouldReceive('elegivel')->andReturn(true);
            $m->shouldReceive('calcular')->twice()->andReturn(['data' => '2026-11-23', 'fornecedores' => [], 'pendencias' => [], 'total_centavos' => 0]);
            $m->shouldReceive('texto')->twice()->andReturn('Previsão');
            $m->shouldReceive('csv')->twice()->andReturn('Fornecedor;Produto');
        });
        $this->artisan('financeiro:fornecedores-d10 --dry-run')->assertSuccessful();
        Mail::assertNothingSent();
        Storage::disk('local')->assertMissing('automation/fornecedores-d10/2026-11-23.json');
        $this->app->instance('env', 'production');
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        $this->artisan('financeiro:fornecedores-d10')->assertSuccessful();
        Mail::assertSentCount(1);
        Storage::disk('local')->assertExists('automation/fornecedores-d10/2026-11-23.json');
    }
}
