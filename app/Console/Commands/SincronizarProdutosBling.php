<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Services\Bling\BlingClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Cadastra no Bling todo produto da KazaKora que ainda não existe lá e
 * completa o dado fiscal e de logística dos que existem (pedido 2026-10-11).
 *
 * Por quê: a NF-e do TikTok Shop (e da Amazon) é emitida pelo Bling. Item de
 * venda cujo código não tem produto no Bling — ou cujo produto está sem NCM,
 * origem, unidade ou peso — trava a nota (pedido #2946, nota 468 pendente).
 *
 * Regra de não duplicar: o código do produto no Bling é o NOSSO SKU (é o que
 * o TikTok manda no pedido). Procura pelo código antes; achou 1 → só
 * completa campo VAZIO (nunca sobrescreve o que o contador ajustou lá);
 * achou mais de 1 → não mexe e avisa; não achou → cria.
 *
 * Entram só produtos ATIVOS (inativos são cópias/rascunhos de importação,
 * ex.: SHOPEE-58212998407) e nunca o produto-pai que só agrupa variações —
 * quem é vendido é a variação, que vai com o nome "Pai - Variação".
 *
 * Sem --aplicar só mostra o que faria.
 */
class SincronizarProdutosBling extends Command
{
    protected $signature = 'bling:sincronizar-produtos
        {--aplicar : Grava no Bling (sem isso, só mostra)}
        {--sku= : Só este SKU}
        {--incluir-inativos : Inclui produtos inativos (por padrão ficam de fora: cópias e rascunhos de importação)}';

    protected $description = 'Cria no Bling os produtos que faltam e completa fiscal/logística (sem duplicar)';

    /** Bling: 3 requisições/s para a conta inteira, dividida com nota e etiqueta. */
    private const PAUSA_US = 400_000;

    public function handle(BlingClient $bling): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $resumo = ['criados' => 0, 'completados' => 0, 'ok' => 0, 'duplicados' => 0, 'erros' => 0];

        $produtos = Product::query()
            ->with(['fiscalData', 'parent:id,name'])
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->unless($this->option('incluir-inativos'), fn ($q) => $q->where('is_active', true))
            // Pai que só agrupa variações não é vendido sozinho.
            ->whereDoesntHave('children', fn ($q) => $q->where('is_active', true))
            ->when($this->option('sku'), fn ($q, $sku) => $q->where('sku', $sku))
            ->orderBy('id')
            ->get();

        $this->info(($aplicar ? 'APLICANDO' : 'SIMULAÇÃO (use --aplicar para gravar)').' — '.$produtos->count().' produto(s).');

        foreach ($produtos as $produto) {
            $sku = trim((string) $produto->sku);

            try {
                $achados = $bling->get('produtos', ['codigo' => $sku])['data'] ?? [];
                // O filtro do Bling por código pode trazer parecidos: só vale o código idêntico.
                $achados = array_values(array_filter($achados, fn ($p) => (string) ($p['codigo'] ?? '') === $sku));
                usleep(self::PAUSA_US);

                if (count($achados) > 1) {
                    $resumo['duplicados']++;
                    $this->warn("  {$sku}: {$this->n(count($achados))} produtos com este código no Bling — não mexi (resolver lá).");

                    continue;
                }

                if (! $achados) {
                    if ($aplicar) {
                        $bling->post('produtos', $this->novoProduto($produto));
                        usleep(self::PAUSA_US);
                    }
                    $resumo['criados']++;
                    $this->line("  {$sku}: ".($aplicar ? 'criado' : 'seria criado').' — '.mb_strimwidth($produto->name, 0, 50, '…'));

                    continue;
                }

                $atual = $bling->get('produtos/'.$achados[0]['id'])['data'] ?? null;
                usleep(self::PAUSA_US);
                if (! $atual) {
                    continue;
                }

                [$completo, $campos] = $this->completar($atual, $produto);
                if (! $campos) {
                    $resumo['ok']++;

                    continue;
                }

                if ($aplicar) {
                    // PUT do Bling substitui o recurso: manda o produto inteiro.
                    $bling->put('produtos/'.$atual['id'], $completo);
                    usleep(self::PAUSA_US);
                }
                $resumo['completados']++;
                $this->line("  {$sku}: ".($aplicar ? 'completado' : 'seria completado').' — '.implode(', ', $campos));
            } catch (Throwable $erro) {
                $resumo['erros']++;
                $this->error("  {$sku}: ".mb_substr($erro->getMessage(), 0, 160));
            }
        }

        $this->info(sprintf('%s: %d criado(s), %d completado(s), %d já certo(s), %d duplicado(s) no Bling, %d erro(s).',
            $aplicar ? 'Concluído' : 'Simulação', $resumo['criados'], $resumo['completados'], $resumo['ok'], $resumo['duplicados'], $resumo['erros']));

        return $resumo['erros'] ? self::FAILURE : self::SUCCESS;
    }

    /** Variação vai com o nome do pai: "Quebra-Cabeça Magnético - Menino". */
    private function nome(Product $produto): string
    {
        $nome = trim((string) $produto->name);
        $pai = trim((string) $produto->parent?->name);

        if ($pai === '' || str_starts_with(mb_strtolower($nome), mb_strtolower(mb_substr($pai, 0, 20)))) {
            return $nome;
        }

        return $pai.' - '.(trim((string) $produto->variation) ?: $nome);
    }

    private function n(int $n): string
    {
        return (string) $n;
    }

    /** Payload de criação (API v3 do Bling). */
    private function novoProduto(Product $produto): array
    {
        $f = $produto->fiscalData;

        return array_filter([
            'nome' => mb_substr($this->nome($produto), 0, 120),
            'codigo' => $produto->sku,
            'preco' => round((float) $produto->price, 2),
            'precoCusto' => $produto->cost_price !== null ? round((float) $produto->cost_price, 2) : null,
            'tipo' => 'P',
            'situacao' => 'A',
            'formato' => 'S',
            'unidade' => $f?->unidade_tributavel ?: 'UN',
            'marca' => $produto->brand ?: null,
            'gtin' => $f?->gtin ?: null,
            'pesoBruto' => $f?->peso_bruto ? (float) $f->peso_bruto : null,
            'pesoLiquido' => $f?->peso_liquido ? (float) $f->peso_liquido : ($f?->peso_bruto ? (float) $f->peso_bruto : null),
            'dimensoes' => $this->dimensoes($f),
            'tributacao' => array_filter([
                'origem' => (int) ($f?->origem ?? 0),
                'ncm' => $f?->ncm ? preg_replace('/\D/', '', (string) $f->ncm) : null,
                'cest' => $f?->cest ? preg_replace('/\D/', '', (string) $f->cest) : null,
            ], fn ($v) => $v !== null),
        ], fn ($v) => $v !== null && $v !== []);
    }

    private function dimensoes($f): ?array
    {
        if (! $f || ! (float) $f->largura_cm || ! (float) $f->altura_cm || ! (float) $f->profundidade_cm) {
            return null;
        }

        return [
            'largura' => (float) $f->largura_cm,
            'altura' => (float) $f->altura_cm,
            'profundidade' => (float) $f->profundidade_cm,
            'unidadeMedida' => 1, // centímetros
        ];
    }

    /**
     * Completa só o que está vazio no Bling.
     *
     * @return array{0: array, 1: list<string>}
     */
    private function completar(array $atual, Product $produto): array
    {
        $f = $produto->fiscalData;
        $campos = [];
        $vazio = fn ($v) => $v === null || $v === '' || (is_numeric($v) && (float) $v == 0.0);

        if ($f?->ncm && $vazio($atual['tributacao']['ncm'] ?? null)) {
            $atual['tributacao']['ncm'] = preg_replace('/\D/', '', (string) $f->ncm);
            $campos[] = 'NCM';
        }
        if ($f?->cest && $vazio($atual['tributacao']['cest'] ?? null)) {
            $atual['tributacao']['cest'] = preg_replace('/\D/', '', (string) $f->cest);
            $campos[] = 'CEST';
        }
        if ($vazio($atual['unidade'] ?? null)) {
            $atual['unidade'] = $f?->unidade_tributavel ?: 'UN';
            $campos[] = 'unidade';
        }
        if ($f?->peso_bruto && $vazio($atual['pesoBruto'] ?? null)) {
            $atual['pesoBruto'] = (float) $f->peso_bruto;
            $campos[] = 'peso bruto';
        }
        if (($f?->peso_liquido || $f?->peso_bruto) && $vazio($atual['pesoLiquido'] ?? null)) {
            $atual['pesoLiquido'] = (float) ($f->peso_liquido ?: $f->peso_bruto);
            $campos[] = 'peso líquido';
        }
        $dim = $this->dimensoes($f);
        if ($dim && ($vazio($atual['dimensoes']['largura'] ?? null) || $vazio($atual['dimensoes']['altura'] ?? null) || $vazio($atual['dimensoes']['profundidade'] ?? null))) {
            $atual['dimensoes'] = $dim;
            $campos[] = 'medidas';
        }
        if ($f?->gtin && $vazio($atual['gtin'] ?? null)) {
            $atual['gtin'] = $f->gtin;
            $campos[] = 'GTIN';
        }

        return [$atual, $campos];
    }
}
