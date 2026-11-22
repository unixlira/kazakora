<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Models\CartSnapshot;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Support\LeadsDoCheckout;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Support\SiteAtual;
use Illuminate\Support\Carbon;

/**
 * Traz para `adm_abandoned_carts` os carrinhos abertos (pedido do Lira
 * 21/11/2026): quem preencheu o checkout e não pagou (`lead_checkout`, a
 * fonte com telefone) e o carrinho de quem tem e-mail ou conta
 * (`CartSnapshot`). Abandonado = sem mexer há 60 minutos. Mais de 30 dias
 * parado não entra.
 *
 * Um registro por pessoa e loja: o mesmo e-mail, telefone ou usuário com dois
 * carrinhos no mesmo site vira UM registro, com o carrinho mais recente, e a
 * sequência continua de onde estava (nunca duas sequências para a mesma
 * pessoa). Quem pediu para parar ou saiu das promoções não volta.
 *
 * Início da sequência = a hora do abandono, mas nunca antes de "agora menos 1
 * dia": carrinho que já estava abandonado quando a sequência foi ligada
 * (estoque antigo) recebe o 1º disparo na primeira rodada.
 */
class ImportadorSequencia
{
    public const MINUTOS_ABANDONO = 60;

    public const DIAS_MAXIMO = 30;

    /** Sites com sequência (Beauty Kora fica de fora). */
    public const SITES = [SiteAtual::KAZAKORA, SiteAtual::FERRAKORA];

    /** @return array{novos: int, atualizados: int} */
    public function importar(): array
    {
        $candidatos = [...$this->dosLeads(), ...$this->dosCarrinhos()];
        // O mais recente por último: ele é que fica no registro da pessoa.
        usort($candidatos, fn ($a, $b) => $a['abandonado_em']->getTimestamp() <=> $b['abandonado_em']->getTimestamp());

        $contagem = ['novos' => 0, 'atualizados' => 0];
        foreach ($candidatos as $candidato) {
            $resultado = $this->gravar($candidato);
            if ($resultado !== null) {
                $contagem[$resultado]++;
            }
        }

        return $contagem;
    }

    /** @return list<array<string, mixed>> */
    private function dosLeads(): array
    {
        return LeadCheckout::query()
            ->whereIn('site', self::SITES)
            ->where('status', LeadCheckout::STATUS_ABERTO)
            ->where('updated_at', '<=', now()->subMinutes(self::MINUTOS_ABANDONO))
            ->where('updated_at', '>=', now()->subDays(self::DIAS_MAXIMO))
            ->get()
            ->map(function (LeadCheckout $lead) {
                $itens = collect($lead->itens ?? [])->map(fn ($item) => [
                    'product_id' => (int) ($item['product_id'] ?? 0),
                    'nome' => (string) ($item['nome'] ?? ''),
                    'quantidade' => (int) ($item['quantidade'] ?? 1),
                    'subtotal' => round((float) ($item['subtotal'] ?? 0), 2),
                ])->filter(fn ($item) => $item['product_id'] > 0)->values()->all();

                return [
                    'site' => $lead->site,
                    'origem' => Registro::ORIGEM_LEAD,
                    'origem_id' => $lead->id,
                    'user_id' => $lead->user_id,
                    'nome' => $lead->nome,
                    'email' => LeadsDoCheckout::email($lead->email),
                    'telefone' => WhatsAppPhone::normalize($lead->telefone),
                    'itens' => $itens,
                    'subtotal' => (float) $lead->subtotal,
                    'abandonado_em' => $lead->updated_at->copy(),
                ];
            })
            ->filter(fn ($c) => $c['itens'] !== [])
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function dosCarrinhos(): array
    {
        $carrinhos = CartSnapshot::query()
            ->with('user')
            ->where('lembretes_parados', false)
            ->whereNotNull('ultima_atividade_em')
            ->where('ultima_atividade_em', '<=', now()->subMinutes(self::MINUTOS_ABANDONO))
            ->where('ultima_atividade_em', '>=', now()->subDays(self::DIAS_MAXIMO))
            ->where(fn ($q) => $q->whereNotNull('email')->orWhereNotNull('user_id'))
            ->get();

        $ids = $carrinhos->flatMap(fn ($c) => array_keys($c->itens ?? []))->unique()->all();
        $produtos = Product::withTrashed()->whereIn('id', $ids)->get()->keyBy('id');

        return $carrinhos->map(function (CartSnapshot $carrinho) use ($produtos) {
            $itens = collect($carrinho->itens ?? [])->map(function ($quantidade, $id) use ($produtos) {
                $produto = $produtos->get((int) $id);

                return $produto ? [
                    'product_id' => (int) $id,
                    'nome' => (string) $produto->name,
                    'quantidade' => (int) $quantidade,
                    'subtotal' => round((float) $produto->final_price * (int) $quantidade, 2),
                ] : null;
            })->filter()->values()->all();

            return [
                // O carrinho da sessão é o do kazakora.com (a FerraKora chega pelo lead_checkout).
                'site' => SiteAtual::KAZAKORA,
                'origem' => Registro::ORIGEM_SNAPSHOT,
                'origem_id' => $carrinho->id,
                'user_id' => $carrinho->user_id,
                'nome' => $carrinho->user?->name,
                'email' => LeadsDoCheckout::email($carrinho->email ?: $carrinho->user?->email),
                'telefone' => WhatsAppPhone::normalize($carrinho->user?->phone),
                'itens' => $itens,
                'subtotal' => (float) $carrinho->total,
                'abandonado_em' => $carrinho->ultima_atividade_em->copy(),
            ];
        })->filter(fn ($c) => $c['itens'] !== [])->values()->all();
    }

    /** @return 'novos'|'atualizados'|null */
    private function gravar(array $c): ?string
    {
        $existente = Registro::query()->where('origem', $c['origem'])->where('origem_id', $c['origem_id'])->first()
            ?? $this->daMesmaPessoa($c['site'], $c['email'], $c['telefone'], $c['user_id']);

        $principal = collect($c['itens'])->sortByDesc('subtotal')->first();
        $dados = [
            'site' => $c['site'],
            'origem' => $c['origem'],
            'origem_id' => $c['origem_id'],
            'itens' => $c['itens'],
            'produto_id' => $principal['product_id'] ?? null,
            'subtotal' => round($c['subtotal'], 2),
            'abandonado_em' => $c['abandonado_em'],
        ];

        if (! $existente) {
            $registro = new Registro([
                ...$dados,
                'user_id' => $c['user_id'],
                'nome' => $c['nome'] ? mb_substr($c['nome'], 0, 255) : null,
                'email' => $c['email'],
                'telefone' => $c['telefone'],
                'inicio_sequencia_em' => self::inicio($c['abandonado_em']),
                'disparos_enviados' => 0,
                'historico' => [],
            ]);
            $registro->proximo_disparo_em = CalendarioSequencia::proximoEm($registro);
            $registro->save();

            return 'novos';
        }

        // Quem pediu para parar ou saiu das promoções não volta à sequência.
        if ($existente->parado && in_array($existente->motivo_parada, [Registro::PAROU_PEDIU, Registro::PAROU_SEM_PROMOCOES], true)) {
            return null;
        }

        $mesmaOrigem = $existente->origem === $c['origem'] && (int) $existente->origem_id === (int) $c['origem_id'];
        if (! $mesmaOrigem && $existente->abandonado_em && $c['abandonado_em']->lessThanOrEqualTo($existente->abandonado_em)) {
            // O registro já tem um carrinho mais novo dessa pessoa.
            return null;
        }
        if ($mesmaOrigem && $existente->abandonado_em && $c['abandonado_em']->equalTo($existente->abandonado_em)) {
            return null;
        }

        // O contato do carrinho mais novo vale; campo vazio nele não apaga o que já se sabia.
        $existente->fill(array_filter([
            'user_id' => $c['user_id'],
            'nome' => $c['nome'] ? mb_substr($c['nome'], 0, 255) : null,
            'email' => $c['email'],
            'telefone' => $c['telefone'],
        ], fn ($v) => $v !== null && $v !== ''));
        $existente->fill($dados);

        // Comprou e depois abandonou outro carrinho, ou ganhou contato: sequência nova.
        $desde = $existente->convertido_em ?? $existente->updated_at;
        $reabrir = $existente->parado
            && in_array($existente->motivo_parada, [Registro::PAROU_COMPROU, Registro::PAROU_SEM_CONTATO], true)
            && (! $desde || $c['abandonado_em']->greaterThan($desde));

        if ($reabrir) {
            $existente->anotar(['evento' => 'reaberto', 'motivo_anterior' => $existente->motivo_parada]);
            $existente->forceFill([
                'parado' => false,
                'motivo_parada' => null,
                'convertido_em' => null,
                'disparos_enviados' => 0,
                'ultimo_disparo_em' => null,
                'inicio_sequencia_em' => self::inicio($c['abandonado_em']),
            ]);
            $existente->proximo_disparo_em = CalendarioSequencia::proximoEm($existente);
        }

        $existente->save();

        return 'atualizados';
    }

    /** Registro da mesma pessoa no mesmo site (e-mail, telefone ou usuário), o mais recente. */
    public function daMesmaPessoa(string $site, ?string $email, ?string $telefone, ?int $userId): ?Registro
    {
        if (! $email && ! $telefone && ! $userId) {
            return null;
        }

        return Registro::query()
            ->where('site', $site)
            ->where(fn ($q) => $q
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->when($telefone, fn ($q) => $q->orWhere('telefone', $telefone))
                ->when($userId, fn ($q) => $q->orWhere('user_id', $userId)))
            ->orderByDesc('abandonado_em')
            ->orderByDesc('id')
            ->first();
    }

    public static function inicio(Carbon $abandonadoEm): Carbon
    {
        $limite = now()->subDay();

        return $abandonadoEm->greaterThan($limite) ? $abandonadoEm->copy() : $limite;
    }
}
