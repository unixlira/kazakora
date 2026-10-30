<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Models\MarketplaceReturn;
use App\Modules\Marketplace\Models\MarketplaceReturnEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Tira do histórico das devoluções as linhas que a sincronização repetiu
 * enquanto o 429 do Mercado Livre apagava o status (pedido do Lira 30/10).
 * Fica a primeira de cada texto por caso; linha da equipe e nota fiscal não
 * entram. Sem --aplicar só conta. Ao aplicar, guarda antes o que vai sair.
 */
class LimparHistoricoDevolucoes extends Command
{
    protected $signature = 'devolucoes:limpar-historico {--aplicar : Apaga de verdade, guardando cópia em storage/app/devolucoes}';

    protected $description = 'Remove do histórico das devoluções as linhas repetidas pela sincronização';

    public function handle(): int
    {
        $vistos = [];
        $repetidos = [];

        MarketplaceReturnEvent::query()->whereNull('user_id')->orderBy('happened_at')->orderBy('id')
            ->each(function (MarketplaceReturnEvent $evento) use (&$vistos, &$repetidos) {
                if (! $this->veioDaSincronizacao($evento->description)) {
                    return;
                }

                $chave = $evento->marketplace_return_id.'|'.$evento->description;

                if (isset($vistos[$chave])) {
                    $repetidos[] = $evento->toArray();
                }

                $vistos[$chave] = true;
            });

        $this->info(count($repetidos).' linha(s) repetida(s) em '.collect($repetidos)->pluck('marketplace_return_id')->unique()->count().' caso(s).');

        if (! $this->option('aplicar') || $repetidos === []) {
            return self::SUCCESS;
        }

        $arquivo = 'devolucoes/historico-removido-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($arquivo, json_encode($repetidos, JSON_UNESCAPED_UNICODE));

        foreach (array_chunk(array_column($repetidos, 'id'), 500) as $ids) {
            MarketplaceReturnEvent::query()->whereIn('id', $ids)->delete();
        }

        $this->info("Removidas. Cópia em storage/app/{$arquivo}.");

        return self::SUCCESS;
    }

    private function veioDaSincronizacao(string $texto): bool
    {
        return str_starts_with($texto, 'Dinheiro: ')
            || str_starts_with($texto, 'Envio de volta: ')
            || in_array($texto, MarketplaceReturn::SITUACOES, true);
    }
}
