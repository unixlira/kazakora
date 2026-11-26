<?php

namespace App\Console\Commands;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Troca para a sequência de 4 contatos (pedido do Lira 26/11/2026: "tá
 * disparando mensagem demais"). Nos carrinhos ATIVOS:
 * - já recebeu 4 ou mais disparos, ou já passou do D+7: encerra agora, sem
 *   mensagem nenhuma (motivo "sequência nova 26/11");
 * - no meio: segue só com o que falta da sequência nova (sem repetir etapa e
 *   nunca 2 no mesmo dia), com a data recalculada.
 *
 * --seco mostra o que faria e desfaz tudo. Sem --seco, guarda antes uma
 * cópia de todos os registros em storage/app/backups/ (para desfazer:
 * loja:carrinho-sequencia-nova --desfazer=<arquivo>).
 */
class CarrinhoSequenciaNova extends Command
{
    protected $signature = 'loja:carrinho-sequencia-nova
        {--seco : Só mostra o que faria, sem gravar}
        {--desfazer= : Volta os registros do arquivo de cópia (caminho em storage/app)}';

    protected $description = 'Encerra os carrinhos que já passaram da sequência nova (D+1, D+3, D+5, D+7) e recalcula os outros';

    public function handle(): int
    {
        if ($arquivo = $this->option('desfazer')) {
            return $this->desfazer((string) $arquivo);
        }

        $seco = (bool) $this->option('seco');
        $ativos = Registro::query()->where('parado', false)->orderBy('id')->get();

        if (! $seco) {
            $arquivo = 'backups/carrinho-sequencia-antes-'.now()->format('Y-m-d_His').'.json';
            Storage::disk('local')->put($arquivo, Registro::query()->orderBy('id')->get()->map(fn (Registro $r) => $r->makeVisible(['email', 'telefone'])->getAttributes())->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $this->info("Cópia de antes: storage/app/{$arquivo}");
        }

        DB::beginTransaction();
        $linhas = [];
        $encerrados = 0;
        foreach ($ativos as $r) {
            $passou = $r->inicio_sequencia_em && now()->greaterThan($r->inicio_sequencia_em->copy()->addDays(CalendarioSequencia::dia(CalendarioSequencia::TOTAL)));
            $antes = $r->proximo_disparo_em?->copy()->setTimezone(CalendarioSequencia::FUSO)->format('d/m H:i') ?? '-';

            if ($r->disparos_enviados >= CalendarioSequencia::TOTAL || $passou) {
                $r->anotar(['evento' => 'sequencia_nova', 'acao' => 'encerrado', 'disparos_antes' => $r->disparos_enviados]);
                $r->parar(Registro::PAROU_SEQUENCIA_NOVA);
                $encerrados++;
                $linhas[] = [$r->id, $r->nome, $r->disparos_enviados, $antes, 'ENCERRADO (sem mensagem)'];

                continue;
            }

            $novo = CalendarioSequencia::proximoEm($r);
            if ($novo?->toIso8601String() !== $r->proximo_disparo_em?->toIso8601String()) {
                $r->anotar(['evento' => 'sequencia_nova', 'acao' => 'recalculado', 'proximo_antes' => $r->proximo_disparo_em?->toIso8601String()]);
                $r->forceFill(['proximo_disparo_em' => $novo])->save();
            }
            $linhas[] = [$r->id, $r->nome, $r->disparos_enviados, $antes,
                'segue: '.($r->disparos_enviados + 1).'º D+'.CalendarioSequencia::dia($r->disparos_enviados + 1).' em '.($novo?->copy()->setTimezone(CalendarioSequencia::FUSO)->format('d/m H:i') ?? '-')];
        }

        $this->table(['id', 'cliente', 'disparos já feitos', 'próximo antes', 'agora'], $linhas);
        $this->info("Encerrados: {$encerrados}. Seguem: ".(count($linhas) - $encerrados).'.');
        $this->newLine();
        $this->info('Prévia do que falta (sequência nova):');
        $this->call('loja:carrinho-abandonado-sequencia', ['--previa' => true]);

        if ($seco) {
            DB::rollBack();
            $this->comment('Seco: nada foi gravado.');
        } else {
            DB::commit();
        }

        return self::SUCCESS;
    }

    private function desfazer(string $arquivo): int
    {
        if (! Storage::disk('local')->exists($arquivo)) {
            $this->error("Arquivo não encontrado em storage/app: {$arquivo}");

            return self::FAILURE;
        }

        $voltou = 0;
        foreach (json_decode((string) Storage::disk('local')->get($arquivo), true) ?: [] as $linha) {
            $voltou += DB::table('adm_abandoned_carts')->where('id', $linha['id'])->update(collect($linha)->except(['id', 'created_at'])->all());
        }
        $this->info("Registros devolvidos ao estado da cópia: {$voltou}.");

        return self::SUCCESS;
    }
}
