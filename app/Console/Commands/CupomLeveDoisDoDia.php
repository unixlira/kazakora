<?php

namespace App\Console\Commands;

use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Support\LeveDois;
use Illuminate\Console\Command;

/**
 * Cupom do dia do "Compre 1, o 2º sai por R$ X" (pedido do Lira em
 * 27/11/2026): LEVE2 + DDMM, válido só no dia (horário de São Paulo), só na
 * KazaKora. Roda à meia-noite e de hora em hora: se a virada falhar, a hora
 * seguinte cria. Não reativa o cupom de hoje que alguém desligou no admin;
 * os dos dias anteriores ficam desligados.
 */
class CupomLeveDoisDoDia extends Command
{
    protected $signature = 'loja:cupom-leve2 {--seco : Só mostra o que faria}';

    protected $description = 'Cria o cupom do dia (LEVE2+DDMM) do "Compre 1, o 2º sai por R$ X" e desliga os dos dias anteriores';

    public function handle(): int
    {
        $hoje = now()->setTimezone('America/Sao_Paulo');
        $codigo = LeveDois::codigoDoDia($hoje);
        $seco = (bool) $this->option('seco');

        $antigos = Coupon::query()->where('segunda_unidade', true)->where('is_active', true)
            ->when(LeveDois::ativo(), fn ($q) => $q->where('code', '!=', $codigo))
            ->get();
        foreach ($antigos as $antigo) {
            $this->line("Desliga {$antigo->code}");
            if (! $seco) {
                $antigo->update(['is_active' => false]);
            }
        }

        if (! LeveDois::ativo()) {
            $this->info('Promoção desligada (LEVE2_ATIVO=false): nenhum cupom criado.');

            return self::SUCCESS;
        }

        $inicio = $hoje->copy()->startOfDay();
        $cupom = Coupon::buscar($codigo);
        // Já criado hoje: fica como está (inclusive desligado à mão).
        if ($cupom && $cupom->starts_at?->equalTo($inicio)) {
            $this->info("{$codigo} já existe para hoje.");

            return self::SUCCESS;
        }

        $dados = [
            'code' => $codigo,
            'name' => 'Compre 1, o 2º sai por R$ X ('.$hoje->format('d/m').')',
            'discount_type' => Coupon::TYPE_PERCENTAGE,
            'discount_value' => 0,
            'segunda_unidade' => true,
            'so_kazakora' => true,
            'one_per_customer' => false,
            'max_uses' => null,
            'min_order_value' => null,
            'produtos' => null,
            'starts_at' => $inicio,
            'expires_at' => $hoje->copy()->endOfDay(),
            'is_active' => true,
        ];

        if ($seco) {
            $this->info("Criaria {$codigo} ({$inicio->format('d/m/Y')}).");

            return self::SUCCESS;
        }

        // Mesmo código do ano passado: reaproveita a linha com as datas de hoje.
        $cupom ? $cupom->update($dados) : Coupon::create($dados);
        $this->info("{$codigo} criado, vale hoje ({$inicio->format('d/m/Y')}).");

        return self::SUCCESS;
    }
}
