<?php

namespace App\Console\Commands;

use App\Modules\BeautyKora\Services\ConteudoProdutoBeautyKora;
use Illuminate\Console\Command;

/**
 * Deixa pronto o conteúdo da página de produto do Beauty Kora (Gemini) de
 * todos os produtos da vitrine da Hostinger, para o visitante não esperar a
 * geração — pedido 2026-10-12. Só refaz o que mudou (nome, descrição, fotos).
 */
class GerarConteudoBeautyKora extends Command
{
    protected $signature = 'beautykora:gerar-conteudo
        {--produto= : id do produto na Hostinger (prod_...)}
        {--forcar : gera de novo mesmo sem mudança}';

    protected $description = 'Gera destaques e descrição em blocos (Gemini) para as páginas de produto do Beauty Kora.';

    public function handle(ConteudoProdutoBeautyKora $servico): int
    {
        $ids = $this->option('produto') ? [(string) $this->option('produto')] : $servico->idsDaLoja();

        foreach ($ids as $id) {
            $conteudo = $servico->conteudo($id, (bool) $this->option('forcar'));
            $this->line($conteudo ? "{$id} → {$conteudo->fonte}".($conteudo->erro ? " ({$conteudo->erro})" : '') : "{$id} → não encontrado na loja");
        }

        $this->info(count($ids).' produto(s) conferido(s).');

        return self::SUCCESS;
    }
}
