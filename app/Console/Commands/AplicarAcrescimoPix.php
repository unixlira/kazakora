<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\DescontoPix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pedido 2026-10-09: o preço da loja passa a ter o +5% do Pix embutido.
 * Sobe uma vez só o preço (e o desconto em R$) de TODOS os produtos — ativos
 * e inativos, pra regra valer igual pra todo produto (os marketplaces usam o
 * preço sem o acréscimo; um produto sem ele mandaria preço 5% menor pro
 * canal). Grava backup em storage/app/private/backups antes e marca que já rodou.
 * Sem --executar só mostra o que faria; --restaurar=arquivo desfaz.
 */
class AplicarAcrescimoPix extends Command
{
    private const MARCA = 'loja.acrescimo_pix_aplicado_em';

    protected $signature = 'produtos:acrescimo-pix
        {--executar : grava de verdade}
        {--restaurar= : caminho do backup (relativo a storage/app/private) pra voltar os preços}';

    protected $description = 'Sobe +5% (desconto do Pix) no preço da loja de todos os produtos, com backup.';

    public function handle(): int
    {
        if ($arquivo = $this->option('restaurar')) {
            return $this->restaurar($arquivo);
        }

        if ($aplicadoEm = Setting::get(self::MARCA)) {
            $this->error("O acréscimo do Pix já foi aplicado em {$aplicadoEm}. Pra rodar de novo, restaure o backup antes.");

            return self::FAILURE;
        }

        $fator = DescontoPix::fator();
        $produtos = DB::table('products')->select('id', 'sku', 'price', 'discount_amount')->orderBy('id')->get();

        $this->info(sprintf('%d produto(s) vão subir %s%% no preço da loja.', $produtos->count(), DescontoPix::percentual()));

        foreach ($produtos->take(5) as $p) {
            $this->line(sprintf('  %s: %s → %s', $p->sku, number_format((float) $p->price, 2, ',', '.'), number_format(round((float) $p->price * $fator, 2), 2, ',', '.')));
        }

        if (! $this->option('executar')) {
            $this->comment('Nada foi gravado. Rode com --executar pra aplicar.');

            return self::SUCCESS;
        }

        $caminho = 'backups/precos-antes-acrescimo-pix-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($caminho, $produtos->toJson(JSON_PRETTY_PRINT));

        DB::transaction(function () use ($produtos, $fator) {
            // Query direta (sem eventos do model) pra não disparar sync de
            // preço com os marketplaces — pra eles o preço não muda.
            foreach ($produtos as $p) {
                DB::table('products')->where('id', $p->id)->update([
                    'price' => round((float) $p->price * $fator, 2),
                    'discount_amount' => $p->discount_amount !== null ? round((float) $p->discount_amount * $fator, 2) : null,
                ]);
            }

            Setting::set(self::MARCA, now()->toDateTimeString());
        });

        $this->info('Preços atualizados. Backup: '.Storage::disk('local')->path($caminho));
        $this->line("Pra desfazer: php artisan produtos:acrescimo-pix --restaurar={$caminho}");

        return self::SUCCESS;
    }

    private function restaurar(string $arquivo): int
    {
        if (! Storage::disk('local')->exists($arquivo)) {
            $this->error('Backup não encontrado: '.Storage::disk('local')->path($arquivo));

            return self::FAILURE;
        }

        $produtos = json_decode(Storage::disk('local')->get($arquivo), true);

        DB::transaction(function () use ($produtos) {
            foreach ($produtos as $p) {
                DB::table('products')->where('id', $p['id'])->update([
                    'price' => $p['price'],
                    'discount_amount' => $p['discount_amount'],
                ]);
            }

            Setting::set(self::MARCA, null);
        });

        $this->info(count($produtos).' produto(s) voltaram ao preço do backup.');

        return self::SUCCESS;
    }
}
