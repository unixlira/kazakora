<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Console\Command;

/**
 * Preenche o texto do botão principal de compra (pedido do Lira 2026-11-24:
 * "Quero meu Livro Falante" no lugar do "Comprar agora") dos produtos ativos
 * que ainda estão sem ele, com a mesma geração do cadastro (Gemini em lotes,
 * regra própria se falhar). Nunca sobrescreve um texto já gravado, a não ser
 * com --forcar. --dry-run só lista o que seria gravado.
 *
 * --loja: kazakora (padrão; ativos fora do "só FerraKora"), ferrakora (os
 * "só FerraKora") ou todas. A tabela de produtos é a mesma das três lojas,
 * mas o botão só aparece na página do produto da kazakora.com.
 */
class GerarCtaCompra extends Command
{
    protected $signature = 'produtos:gerar-cta
        {--dry-run : só mostra o texto sugerido, sem gravar}
        {--loja=kazakora : kazakora, ferrakora ou todas}
        {--produto= : id de um produto}
        {--forcar : refaz também quem já tem texto}
        {--limite=0 : máximo de produtos (0 = todos)}';

    protected $description = 'Gera o texto do botão de compra da página do produto ("Quero meu ...") para os produtos sem ele.';

    public function handle(AnuncioConteudoService $servico): int
    {
        $loja = mb_strtolower((string) $this->option('loja'));

        if (! in_array($loja, ['kazakora', 'ferrakora', 'todas'], true)) {
            $this->error('--loja deve ser kazakora, ferrakora ou todas.');

            return self::INVALID;
        }

        $query = Product::query()->with('category')->orderBy('id');

        if ($id = $this->option('produto')) {
            $query->whereKey($id);
        } else {
            match ($loja) {
                'kazakora' => $query->naKazaKora(),
                'ferrakora' => $query->where('is_active', true)->where('so_ferrakora', true),
                default => $query->where('is_active', true),
            };
        }

        if (! $this->option('forcar')) {
            $query->where(fn ($q) => $q->whereNull('cta_compra')->orWhere('cta_compra', ''));
        }

        if (($limite = (int) $this->option('limite')) > 0) {
            $query->limit($limite);
        }

        $produtos = $query->get();

        if ($produtos->isEmpty()) {
            $this->info('Nenhum produto sem o texto do botão.');

            return self::SUCCESS;
        }

        $seco = (bool) $this->option('dry-run');
        $textos = $servico->textosBotaoCompra($produtos);
        $linhas = [];

        foreach ($produtos as $product) {
            $sugestao = $textos[$product->id];
            $linhas[] = [$product->id, $product->name, $sugestao['texto'], $sugestao['fonte']];

            if (! $seco) {
                // Direto no banco: não dispara auditoria nem os eventos de renomear.
                Product::query()->whereKey($product->id)->update(['cta_compra' => $sugestao['texto']]);
            }
        }

        $this->table(['id', 'produto', 'botão', 'fonte'], $linhas);
        $this->info(sprintf('%d produto(s) %s.', count($linhas), $seco ? 'seriam gravados (dry-run, nada foi gravado)' : 'gravados'));

        return self::SUCCESS;
    }
}
