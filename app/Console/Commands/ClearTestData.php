<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Limpeza única dos dados de teste antes do hub multi-canal ir pra
 * produção — pedido explícito do usuário, confirmado antes de rodar.
 * Não mexe em usuários/admin, categorias, banners, cupons, configurações,
 * nem nas conexões de marketplace já feitas (marketplace_accounts,
 * mercado_livre_tokens ficam intactas).
 */
class ClearTestData extends Command
{
    protected $signature = 'app:clear-test-data {--force : Pula a confirmação interativa}';

    protected $description = 'Apaga produtos, pedidos, pagamentos, notas fiscais e movimentações de estoque de teste';

    private const TABLES = [
        'order_items',
        'payments',
        'invoices',
        'stock_movements',
        'product_images',
        'product_fiscal_data',
        'product_channel_listings',
        'orders',
        'products',
    ];

    public function handle(): int
    {
        foreach (self::TABLES as $table) {
            $this->line($table.': '.DB::table($table)->count().' registro(s)');
        }

        if (! $this->option('force') && ! $this->confirm('Confirma a limpeza das tabelas acima?')) {
            $this->info('Cancelado.');

            return self::SUCCESS;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        foreach (self::TABLES as $table) {
            DB::table($table)->truncate();
            $this->info($table.' truncada.');
        }

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        return self::SUCCESS;
    }
}
