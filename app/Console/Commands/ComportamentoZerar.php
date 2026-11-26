<?php

namespace App\Console\Commands;

use App\Modules\Infoprodutos\Models\ComportamentoCorte;
use App\Modules\Infoprodutos\Models\InfoprodutoEvento;
use Illuminate\Console\Command;

/**
 * "Começar do zero" uma página da tela Comportamento pelo terminal (o mesmo
 * botão "Zerar a partir de agora" da tela). Não apaga nada.
 *
 *   php artisan comportamento:zerar kz/livro-falante-bilingue-kits --motivo="layout novo"
 */
class ComportamentoZerar extends Command
{
    protected $signature = 'comportamento:zerar {produto* : kz/<slug>, fk/<slug>, kzp/... ou o slug do infoproduto}
        {--pagina=produto} {--motivo=Layout novo}';

    protected $description = 'Marca o corte de uma página na tela Comportamento (mede só daqui para frente)';

    public function handle(): int
    {
        foreach ((array) $this->argument('produto') as $produto) {
            $corte = ComportamentoCorte::zerar($produto, (string) $this->option('pagina'), (string) $this->option('motivo'));
            $antes = InfoprodutoEvento::query()->where('produto', $corte->produto)->where('tipo', 'pageview')->count();
            $this->line("ok {$corte->produto} {$corte->pagina} desde {$corte->desde->format('d/m H:i')} ({$antes} aberturas antigas ficam guardadas, fora da tela)");
        }

        return self::SUCCESS;
    }
}
