<?php

namespace App\Modules\Catalog\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Coupon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Clube de Avaliações (aprovado pelo Lira em 2026-10-20): cada avaliação do
 * site vale 10 pontos e mais 10 com comentário de 20 letras ou mais. Ao
 * alcançar um nível o cliente ganha, uma única vez, um cupom pessoal de uso
 * único (só a conta dele usa) com validade de 60 dias.
 */
class ClubeDeAvaliacoes
{
    public const PONTOS_NOTA = 10;

    public const PONTOS_COMENTARIO = 10;

    public const COMENTARIO_MINIMO = 20;

    public const VALIDADE_DIAS = 60;

    public const NIVEIS = [
        ['chave' => 'bronze', 'nome' => 'Bronze', 'pontos' => 30, 'desconto' => 5],
        ['chave' => 'prata', 'nome' => 'Prata', 'pontos' => 80, 'desconto' => 8],
        ['chave' => 'ouro', 'nome' => 'Ouro', 'pontos' => 150, 'desconto' => 12],
    ];

    public static function pontosDaAvaliacao(?int $nota, ?string $comentario): int
    {
        if (! $nota) {
            return 0;
        }

        return self::PONTOS_NOTA + (mb_strlen(trim((string) $comentario)) >= self::COMENTARIO_MINIMO ? self::PONTOS_COMENTARIO : 0);
    }

    public function pontos(User $user): int
    {
        return (int) Review::query()->where('user_id', $user->id)->get(['rating', 'comment'])
            ->sum(fn (Review $r) => self::pontosDaAvaliacao($r->rating, $r->comment));
    }

    /**
     * Gera o cupom de cada nível alcançado que o cliente ainda não ganhou.
     * Devolve os cupons novos.
     *
     * @return Collection<int, Coupon>
     */
    public function conceder(User $user): Collection
    {
        $pontos = $this->pontos($user);

        return DB::transaction(function () use ($user, $pontos) {
            $ganhos = DB::table('recompensas_avaliacao')->where('user_id', $user->id)->lockForUpdate()->pluck('nivel')->all();
            $novos = collect();

            foreach (self::NIVEIS as $nivel) {
                if ($pontos < $nivel['pontos'] || in_array($nivel['chave'], $ganhos, true)) {
                    continue;
                }

                $cupom = Coupon::create([
                    'code' => $this->codigoNovo($nivel['chave']),
                    'user_id' => $user->id,
                    'name' => "Clube de Avaliações · {$nivel['nome']} (cliente #{$user->id})",
                    'discount_type' => Coupon::TYPE_PERCENTAGE,
                    'discount_value' => $nivel['desconto'],
                    'max_uses' => 1,
                    'one_per_customer' => true,
                    'starts_at' => now(),
                    'expires_at' => now()->addDays(self::VALIDADE_DIAS)->endOfDay(),
                    'is_active' => true,
                ]);

                DB::table('recompensas_avaliacao')->insert([
                    'user_id' => $user->id, 'nivel' => $nivel['chave'], 'pontos' => $pontos,
                    'coupon_id' => $cupom->id, 'created_at' => now(), 'updated_at' => now(),
                ]);

                $novos->push($cupom);
            }

            return $novos;
        });
    }

    /** Níveis com o cupom já ganho (código, validade e se já foi usado). */
    public function resumo(User $user): array
    {
        $pontos = $this->pontos($user);
        $cupomDoNivel = DB::table('recompensas_avaliacao')->where('user_id', $user->id)->pluck('coupon_id', 'nivel');
        $cupons = Coupon::query()->whereIn('id', $cupomDoNivel->filter())->comUsos()->get()->keyBy('id');

        return [
            'pontos' => $pontos,
            'regras' => ['nota' => self::PONTOS_NOTA, 'comentario' => self::PONTOS_COMENTARIO, 'comentario_minimo' => self::COMENTARIO_MINIMO, 'validade_dias' => self::VALIDADE_DIAS],
            'niveis' => collect(self::NIVEIS)->map(fn (array $nivel) => $nivel + [
                'cupom' => ($c = $cupons->get($cupomDoNivel->get($nivel['chave']))) ? [
                    'codigo' => $c->code,
                    'validade' => $c->expires_at?->toIso8601String(),
                    'usado' => $c->totalUsos() > 0,
                    'expirado' => (bool) $c->expires_at?->isPast(),
                ] : null,
            ])->values()->all(),
        ];
    }

    private function codigoNovo(string $nivel): string
    {
        do {
            $codigo = 'AVAL'.mb_strtoupper($nivel).'-'.Str::upper(Str::random(6));
        } while (Coupon::query()->where('code', $codigo)->exists());

        return $codigo;
    }
}
