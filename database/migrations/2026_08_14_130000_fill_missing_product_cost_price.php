<?php

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Migrations\Migration;

/**
 * Correção de dado, não de schema — pedido explícito 2026-08-14, achado
 * auditando o dashboard financeiro a pedido do usuário: 4 vendas do mês
 * (pedidos #188, #230, #238, #276) tinham produto sem cost_price
 * cadastrado, entrando como custo ZERO na conta de lucro líquido
 * (FinancialDashboardController::index(), productCostMonth) — inflava o
 * lucro mostrado sem ninguém perceber. Valores reais confirmados
 * diretamente com o usuário:
 *
 * - #23 Celimax Retinal Panthenol... (pedido #188): R$ 20,00
 * - #38 Saboneteira De Silicone Escoa Água (pedido #230): R$ 5,00
 * - #39 Carregador Turbo Samsung 25W (pedidos #238 e #276): R$ 10,00
 *
 * down() não zera de volta — reverter significaria reintroduzir de
 * propósito um dado que já foi confirmado incompleto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Product::query()->where('id', 23)->update(['cost_price' => 20.00]);
        Product::query()->where('id', 38)->update(['cost_price' => 5.00]);
        Product::query()->where('id', 39)->update(['cost_price' => 10.00]);
    }

    public function down(): void
    {
        // Ver comentário da classe — correção de dado real, não reversível
        // com sentido.
    }
};
