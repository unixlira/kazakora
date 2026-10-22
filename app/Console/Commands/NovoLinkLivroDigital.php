<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\LivroDigital;
use App\Modules\Catalog\Models\LivroDownloadToken;
use App\Modules\Checkout\Models\Order;
use Illuminate\Console\Command;

/**
 * Atendimento dos livros de brinde (Lira, 2026-10-22): o link de download vale
 * para um download só. Se o cliente perdeu o PDF ou o link travou, este
 * comando revoga o link antigo e mostra um novo para mandar a ele.
 */
class NovoLinkLivroDigital extends Command
{
    protected $signature = 'livros:novo-link {pedido : id do pedido} {--livro= : só este livro (id); sem a opção, todos do pedido}';

    protected $description = 'Revoga o link de download dos livros digitais do pedido e gera um novo';

    public function handle(): int
    {
        $order = Order::query()->find($this->argument('pedido'));

        if (! $order) {
            $this->error('Pedido não encontrado.');

            return self::FAILURE;
        }

        $livros = LivroDigital::doPedido($order)
            ->when($this->option('livro'), fn ($c, $id) => $c->where('id', (int) $id));

        if ($livros->isEmpty()) {
            $this->error('O pedido não tem livro digital'.($this->option('livro') ? ' com esse id.' : '.'));

            return self::FAILURE;
        }

        foreach ($livros as $livro) {
            $this->line($livro->titulo.': '.LivroDownloadToken::renovar($order, $livro)->url());
        }

        return self::SUCCESS;
    }
}
