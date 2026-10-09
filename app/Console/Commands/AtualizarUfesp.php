<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Modules\Fiscal\Services\UfespService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use App\Notifications\FiscalAlertNotification;
use Throwable;

/**
 * Atualiza a UFESP do ano (ver UfespService). Roda todo dia, mas só
 * consulta as fontes enquanto o valor do ano não estiver gravado — na
 * prática, entre a publicação em dezembro e o começo de janeiro.
 */
class AtualizarUfesp extends Command
{
    protected $signature = 'fiscal:atualizar-ufesp {--ano= : ano a buscar (padrão: o atual e, a partir de dezembro, o próximo)}';

    protected $description = 'Busca o valor da UFESP do ano e grava depois de confirmar na página oficial da Fazenda de SP.';

    public function handle(UfespService $ufesp): int
    {
        $anos = $this->option('ano')
            ? [(int) $this->option('ano')]
            : array_values(array_unique([(int) now()->year, ...(now()->month === 12 ? [(int) now()->year + 1] : [])]));

        foreach ($anos as $ano) {
            if (! $this->option('ano') && $ufesp->registro($ano)) {
                continue;
            }

            try {
                $r = $ufesp->atualizar($ano);
                $this->info("UFESP {$ano}: R$ ".number_format($r['valor'], 2, ',', '.')." ({$r['base_legal']}).");
            } catch (Throwable $e) {
                $this->warn($e->getMessage());

                // Em janeiro sem valor do ano é problema: avisa (uma vez por dia).
                if ($ano === (int) now()->year && now()->day >= 5 && Cache::add("fiscal:ufesp:aviso:{$ano}", true, now()->addDay())) {
                    $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
                    Notification::send($admins, new FiscalAlertNotification(
                        "UFESP de {$ano} não foi atualizada sozinha: {$e->getMessage()} Lance o valor em Notas fiscais > Fechamento do mês.",
                        route('admin.notas-fiscais.fechamento'),
                    ));
                }
            }
        }

        return self::SUCCESS;
    }
}
