<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use App\Modules\WhatsApp\Services\GeminiUnavailableException;
use Illuminate\Console\Command;

/**
 * Gera (ou refaz) pelo Gemini o conteúdo da página dos produtos publicados
 * no FerraKora (pedido 2026-10-14): o Gemini lê até 6 fotos, o nome e a
 * descrição e monta benefícios + blocos, o mesmo da página do KazaKora, que
 * a API do FerraKora devolve em "conteudo". Os clonados da Kikool tiveram a
 * descrição escrita só com a 1ª foto; aqui o conteúdo passa a usar todas.
 *
 * Sem --forcar, pula quem já tem conteúdo do Gemini em dia. Cota do Gemini
 * (chave grátis): pausa entre produtos e, em 429/503, espera e tenta de novo
 * (espera dobrando); se continuar sem cota, para o lote sem gravar a regra
 * automática por cima.
 */
class GerarConteudoFerraKora extends Command
{
    protected $signature = 'ferrakora:gerar-conteudo
        {--limit=10 : máximo de produtos gerados nesta execução}
        {--produto= : id de um produto (pai) do FerraKora}
        {--forcar : refaz mesmo quem já tem conteúdo do Gemini em dia}
        {--dry-run : só lista o que seria gerado, sem chamar o Gemini}
        {--pausa=6 : segundos de pausa entre um produto e outro}
        {--tentativas=4 : tentativas por produto quando o Gemini responde 429/503}';

    protected $description = 'Gera pelo Gemini (fotos + nome + descrição) o conteúdo da página dos produtos do FerraKora, respeitando a cota.';

    public function handle(CatalogoFerraKora $catalogo, AnuncioConteudoService $servico): int
    {
        $forcar = (bool) $this->option('forcar');
        $simular = (bool) $this->option('dry-run');
        $limite = max(1, (int) $this->option('limit'));
        $pausa = max(0, (int) $this->option('pausa'));
        $tentativas = max(1, (int) $this->option('tentativas'));

        $query = $catalogo->paisPublicados();

        if ($id = $this->option('produto')) {
            $query->whereKey($id);
        }

        $feitos = 0;
        $gemini = 0;
        $falhas = 0;

        foreach ($query->lazy(50) as $product) {
            if ($feitos >= $limite) {
                break;
            }

            if (! $forcar && ! $this->precisa($servico, $product)) {
                continue;
            }

            $feitos++;
            $atual = $product->adContent?->fonte ?? 'sem conteúdo';
            $fotos = min(6, $product->images->count());

            if ($simular) {
                $this->line(sprintf('[dry-run] #%d %s (%d foto(s), hoje: %s)', $product->id, $product->name, $fotos, $atual));

                continue;
            }

            if ($feitos > 1 && $pausa > 0) {
                sleep($pausa);
            }

            $conteudo = null;

            for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
                try {
                    $conteudo = $servico->gerar($product, forcar: true, lancarSeIndisponivel: true);

                    break;
                } catch (GeminiUnavailableException $exception) {
                    if ($tentativa === $tentativas) {
                        $this->error(sprintf('#%d sem cota no Gemini depois de %d tentativas: %s', $product->id, $tentativas, $exception->getMessage()));
                        $this->warn('Lote interrompido para não gastar cota à toa. Rode de novo mais tarde.');
                        $this->resumo($feitos - 1, $gemini, $falhas + 1, $simular);

                        return self::FAILURE;
                    }

                    $espera = $pausa * (2 ** $tentativa);
                    $this->warn(sprintf('#%d Gemini sem cota/ocupado (tentativa %d de %d); esperando %ds.', $product->id, $tentativa, $tentativas, $espera));
                    sleep($espera);
                }
            }

            $ok = $conteudo->fonte === ProductAdContent::FONTE_GEMINI;
            $ok ? $gemini++ : $falhas++;
            $this->line(sprintf('#%d %s (%d foto(s)) → %s, %d bloco(s)%s', $product->id, $product->name, $fotos, $conteudo->fonte, count($conteudo->blocos ?? []), $conteudo->erro ? " ({$conteudo->erro})" : ''));
        }

        $this->resumo($feitos, $gemini, $falhas, $simular);

        return self::SUCCESS;
    }

    /** Falta conteúdo, ele não veio do Gemini ou o produto mudou depois. */
    private function precisa(AnuncioConteudoService $servico, Product $product): bool
    {
        return $product->adContent?->fonte !== ProductAdContent::FONTE_GEMINI || $servico->precisaGerar($product);
    }

    private function resumo(int $feitos, int $gemini, int $falhas, bool $simular): void
    {
        $this->info($simular
            ? "{$feitos} produto(s) seriam gerados."
            : "{$feitos} produto(s) processado(s): {$gemini} pelo Gemini, {$falhas} pela regra automática ou com erro.");
    }
}
