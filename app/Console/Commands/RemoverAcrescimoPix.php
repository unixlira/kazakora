<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Support\DescontoPix;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Pedido 2026-10-18: o preço da loja passa a ser exatamente o valor digitado
 * no cadastro; o desconto do Pix fica só no checkout. Tira o +5% gravado em
 * 2026-10-09 (produtos:acrescimo-pix) do preço e do desconto em R$ dos
 * produtos informados em --ids. Só os ids: produto gravado direto no banco
 * depois de 09/10 não tem o acréscimo e não pode baixar. Backup antes;
 * sem --executar só mostra; --restaurar=arquivo desfaz.
 */
class RemoverAcrescimoPix extends Command
{
    private const MARCA = 'loja.acrescimo_pix_removido_em';

    protected $signature = 'produtos:remover-acrescimo-pix
        {--ids= : ids dos produtos que têm o +5% gravado, separados por vírgula}
        {--executar : grava de verdade}
        {--restaurar= : caminho do backup (relativo a storage/app/private) pra voltar os preços}';

    protected $description = 'Tira o +5% do Pix do preço da loja dos produtos informados, com backup.';

    public function handle(): int
    {
        if ($arquivo = $this->option('restaurar')) {
            return $this->restaurar($arquivo);
        }

        if ($removidoEm = Setting::get(self::MARCA)) {
            $this->error("O acréscimo do Pix já foi removido em {$removidoEm}. Pra rodar de novo, restaure o backup antes.");

            return self::FAILURE;
        }

        $ids = collect(explode(',', (string) $this->option('ids')))->map(fn ($id) => (int) trim($id))->filter()->unique()->values();
        if ($ids->isEmpty()) {
            $this->error('Informe --ids=1,2,3 com os produtos que têm o +5% gravado.');

            return self::FAILURE;
        }

        $fator = DescontoPix::fator();
        $produtos = DB::table('products')->whereIn('id', $ids)->select('id', 'sku', 'price', 'discount_amount')->orderBy('id')->get();

        if ($faltando = $ids->diff($produtos->pluck('id'))->all()) {
            $this->error('Produto(s) não encontrado(s): '.implode(', ', $faltando));

            return self::FAILURE;
        }

        $this->info(sprintf('%d produto(s) vão perder o +%s%% no preço da loja.', $produtos->count(), DescontoPix::percentual()));

        foreach ($produtos as $p) {
            $this->line(sprintf('  #%d %s: %s → %s', $p->id, $p->sku, number_format((float) $p->price, 2, ',', '.'), number_format(round((float) $p->price / $fator, 2), 2, ',', '.')));
        }

        if (! $this->option('executar')) {
            $this->comment('Nada foi gravado. Rode com --executar pra aplicar.');

            return self::SUCCESS;
        }

        $caminho = 'backups/precos-antes-remover-acrescimo-pix-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($caminho, $produtos->toJson(JSON_PRETTY_PRINT));

        DB::transaction(function () use ($produtos, $fator) {
            // Query direta (sem eventos do model), como no acréscimo: nada
            // é mandado pros marketplaces.
            foreach ($produtos as $p) {
                DB::table('products')->where('id', $p->id)->update([
                    'price' => round((float) $p->price / $fator, 2),
                    'discount_amount' => $p->discount_amount !== null ? round((float) $p->discount_amount / $fator, 2) : null,
                ]);
            }

            Setting::set(self::MARCA, now()->toDateTimeString());
        });

        $this->info('Preços atualizados. Backup: '.Storage::disk('local')->path($caminho));
        $this->line("Pra desfazer: php artisan produtos:remover-acrescimo-pix --restaurar={$caminho}");

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
