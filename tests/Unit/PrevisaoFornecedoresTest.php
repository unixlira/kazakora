<?php

namespace Tests\Unit;

use App\Modules\Financeiro\Support\PrevisaoFornecedores;
use PHPUnit\Framework\TestCase;

class PrevisaoFornecedoresTest extends TestCase
{
    public function test_calendar_rolls_weekends_holidays_sequences_and_year(): void
    {
        $s = new PrevisaoFornecedores;
        foreach (['2026-11-10' => '2026-11-23', '2026-11-11' => '2026-11-23', '2026-11-12' => '2026-11-23', '2026-11-13' => '2026-11-23', '2026-12-22' => '2027-01-04', '2027-01-15' => '2027-01-26', '2027-06-29' => '2027-07-12', '2027-03-16' => '2027-03-29'] as $paid => $due) {
            self::assertSame($due, $s->vencimento($paid));
        }
        self::assertFalse($s->elegivel('2026-11-19'));
        self::assertFalse($s->elegivel('2026-11-22'));
        self::assertTrue($s->elegivel('2026-11-23'));
    }

    public function test_cohort_cost_manual_total_missing_and_grouping(): void
    {
        $s = new PrevisaoFornecedores;
        $base = ['status' => 'paid', 'origin' => 'shopee', 'created_at' => '2026-11-13', 'product_name' => 'A', 'quantity' => 3, 'cost_price' => 10, 'supplier' => 'Fornecedor', 'sku' => 'A', 'variation' => 'Azul'];
        $r = $s->montar('2026-11-23', [
            $base, $base,
            array_replace($base, ['cost_price' => null, 'manual_cost_price' => 45]),
            array_replace($base, ['supplier' => null]),
            array_replace($base, ['cost_price' => null]),
            array_replace($base, ['status' => 'cancelled']),
            array_replace($base, ['created_at' => '2025-01-01']),
            array_replace($base, ['created_at' => '2026-11-14']),
            array_replace($base, ['created_at' => '2025-01-01', 'paid_at' => '2026-11-13']),
            array_replace($base, ['return_review' => true]),
        ]);
        self::assertSame(13500, $r['total_centavos']);
        self::assertCount(3, $r['pendencias']);
        self::assertSame(6, $r['fornecedores']['Fornecedor'][0]['quantity']);
        self::assertSame(4500, $r['fornecedores']['Fornecedor'][1]['total_centavos']);
        self::assertNull($r['pendencias'][1]['total_centavos']);
        self::assertStringContainsString('não é repasse', $s->texto($r));
    }
}
