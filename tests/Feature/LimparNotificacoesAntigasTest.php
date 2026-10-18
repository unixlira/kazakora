<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Limpeza do sino (pedido 2026-10-18). */
class LimparNotificacoesAntigasTest extends TestCase
{
    use RefreshDatabase;

    private function notificacao(User $user, int $dias, bool $lida): string
    {
        $id = (string) Str::uuid();
        DatabaseNotification::query()->forceCreate([
            'id' => $id,
            'type' => 'App\\Notifications\\Teste',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => ['x' => 1],
            'read_at' => $lida ? now()->subDays($dias) : null,
            'created_at' => now()->subDays($dias),
            'updated_at' => now()->subDays($dias),
        ]);

        return $id;
    }

    public function test_apaga_lidas_de_15_dias_e_qualquer_uma_de_30_em_lotes(): void
    {
        $user = User::factory()->create();
        $lidaVelha = $this->notificacao($user, 16, true);
        $naoLidaVelha = $this->notificacao($user, 31, false);
        $naoLidaRecente = $this->notificacao($user, 20, false);
        $lidaRecente = $this->notificacao($user, 5, true);
        $extra = $this->notificacao($user, 40, true);

        $this->artisan('notificacoes:limpar', ['--lote' => 2])->assertSuccessful();

        $sobraram = DatabaseNotification::query()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$naoLidaRecente, $lidaRecente], $sobraram);
        $this->assertNotContains($lidaVelha, $sobraram);
        $this->assertNotContains($naoLidaVelha, $sobraram);
        $this->assertNotContains($extra, $sobraram);
    }

    public function test_jobs_falhos_com_mais_de_15_dias_saem_na_limpeza_agendada(): void
    {
        $base = ['connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'erro'];
        DB::table('failed_jobs')->insert([
            $base + ['uuid' => (string) Str::uuid(), 'failed_at' => now()->subDays(16)],
            $base + ['uuid' => (string) Str::uuid(), 'failed_at' => now()->subDays(2)],
        ]);

        $this->artisan('queue:prune-failed', ['--hours' => 360])->assertSuccessful();

        $this->assertSame(1, DB::table('failed_jobs')->count());
    }
}
