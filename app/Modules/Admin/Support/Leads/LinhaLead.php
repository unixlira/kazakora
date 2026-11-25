<?php

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Support\SiteAtual;
use Illuminate\Support\Carbon;

/**
 * Uma linha da tela única de Leads: a mesma sessão de checkout vista pelas
 * três fontes de hoje (leads, lead_checkout e a sequência). Qualquer uma
 * pode faltar; a chave diz qual é a principal: "l{id}" (leads), "c{id}"
 * (lead_checkout sem lead digitado) ou "s{id}" (só na sequência).
 */
class LinhaLead
{
    /** @var array{codigo: string, rotulo: string, tom: string, motivo: ?string, disparos: ?int} */
    public array $situacao = [];

    public bool $podeAntecipar = false;

    public function __construct(
        public ?Lead $lead = null,
        public ?LeadCheckout $checkout = null,
        public ?Registro $registro = null,
        public ?Order $pedido = null,
    ) {
    }

    public function chave(): string
    {
        return match (true) {
            $this->lead !== null => 'l'.$this->lead->id,
            $this->checkout !== null => 'c'.$this->checkout->id,
            default => 's'.$this->registro->id,
        };
    }

    public function site(): string
    {
        return (string) ($this->lead?->site ?? $this->checkout?->site ?? $this->registro?->site ?? SiteAtual::KAZAKORA);
    }

    public function nome(): ?string
    {
        return $this->primeiro('nome');
    }

    public function email(): ?string
    {
        return $this->primeiro('email');
    }

    public function telefone(): ?string
    {
        return $this->primeiro('telefone');
    }

    public function cpf(): ?string
    {
        return $this->lead?->cpf ?: ($this->checkout?->cpf ?: null);
    }

    public function cep(): ?string
    {
        return $this->lead?->cep ?: null;
    }

    /** @return list<array<string, mixed>> */
    public function itens(): array
    {
        foreach ([$this->lead?->itens, $this->checkout?->itens, $this->registro?->itens] as $itens) {
            if (! empty($itens)) {
                return array_values($itens);
            }
        }

        return [];
    }

    public function total(): float
    {
        foreach ([$this->lead, $this->checkout, $this->registro] as $fonte) {
            if ($fonte && ! empty($fonte->itens)) {
                return (float) $fonte->subtotal;
            }
        }

        return 0.0;
    }

    /** Última vez que o cliente mexeu no checkout (a sequência não conta). */
    public function ultimaAtividade(): ?Carbon
    {
        $datas = array_filter([$this->lead?->updated_at, $this->checkout?->updated_at]);
        if ($datas === []) {
            return $this->registro?->abandonado_em;
        }

        return collect($datas)->sortDesc()->first();
    }

    public function criadoEm(): ?Carbon
    {
        return collect([$this->lead?->created_at, $this->checkout?->created_at, $this->registro?->created_at])->filter()->sort()->first();
    }

    public function convertido(): bool
    {
        return $this->lead?->status === Lead::STATUS_CONVERTIDO || $this->checkout?->status === LeadCheckout::STATUS_CONVERTIDO;
    }

    /** @return array{origem: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string} */
    public function origem(): array
    {
        $fonte = $this->lead ?? $this->checkout;

        return [
            'origem' => $fonte?->origem,
            'utm_source' => $fonte?->utm_source,
            'utm_medium' => $fonte?->utm_medium,
            'utm_campaign' => $fonte?->utm_campaign,
        ];
    }

    public function proximoContato(): ?Carbon
    {
        return $this->registro && ! $this->registro->parado ? $this->registro->proximo_disparo_em : null;
    }

    /** Texto para a busca: nome, e-mail, telefone, CPF e CEP das três fontes. */
    public function textoDeBusca(): string
    {
        $partes = [];
        foreach ([$this->lead, $this->checkout, $this->registro] as $fonte) {
            if ($fonte) {
                $partes[] = $fonte->nome;
                $partes[] = $fonte->email;
                $partes[] = $fonte->telefone;
                $partes[] = $fonte->cpf ?? null;
                $partes[] = $fonte->cep ?? null;
            }
        }

        return mb_strtolower(implode(' ', array_filter($partes)));
    }

    /** @return list<string> telefone, CPF e CEP das três fontes, só dígitos (cada um separado). */
    public function digitosDeBusca(): array
    {
        $numeros = [];
        foreach ([$this->lead, $this->checkout, $this->registro] as $fonte) {
            foreach (['telefone', 'cpf', 'cep'] as $campo) {
                if ($fonte && filled($fonte->{$campo} ?? null)) {
                    $numeros[] = (string) preg_replace('/\D/', '', (string) $fonte->{$campo});
                }
            }
        }

        return $numeros;
    }

    private function primeiro(string $campo): ?string
    {
        foreach ([$this->lead, $this->checkout, $this->registro] as $fonte) {
            if ($fonte && filled($fonte->{$campo})) {
                return (string) $fonte->{$campo};
            }
        }

        return null;
    }
}
