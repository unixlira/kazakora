<?php

namespace App\Modules\Admin\Support\Leads;

use App\Models\User;
use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use Illuminate\Support\Carbon;

/**
 * Uma linha da tela única de Leads desde 25/11/2026 (decisão do Lira): a
 * PESSOA, com todas as sessões de checkout dela na mesma loja (mesmo e-mail,
 * CPF ou telefone). A situação é a mais avançada entre as sessões, pela
 * prioridade do LeadStatusResolver; os dados vêm da sessão principal e, no
 * que faltar, das outras.
 */
class PessoaLead
{
    /** @var list<LinhaLead> sessões, a principal primeiro e depois da mais recente para a mais antiga */
    public array $sessoes;

    /** @var array{codigo: string, rotulo: string, tom: string, motivo: ?string, disparos: ?int} */
    public array $situacao;

    public ?Order $pedido;

    public ?Registro $registro;

    /** Carrinho que o botão "Antecipar" usa (null = nenhuma sessão elegível). */
    public ?LeadCheckout $checkout;

    public bool $podeAntecipar;

    /**
     * @param  list<LinhaLead>  $sessoes
     * @param  array<int, User>  $usuarios  id => usuário (telefone e CPF do cadastro)
     */
    public function __construct(array $sessoes, public array $usuarios = [])
    {
        $prioridade = array_flip(LeadStatusResolver::PRIORIDADE);
        usort($sessoes, fn (LinhaLead $a, LinhaLead $b) => [$prioridade[$a->situacao['codigo']], -($a->ultimaAtividade()?->getTimestamp() ?? 0)]
            <=> [$prioridade[$b->situacao['codigo']], -($b->ultimaAtividade()?->getTimestamp() ?? 0)]);
        $principal = array_shift($sessoes);
        usort($sessoes, fn (LinhaLead $a, LinhaLead $b) => ($b->ultimaAtividade()?->getTimestamp() ?? 0) <=> ($a->ultimaAtividade()?->getTimestamp() ?? 0));
        $this->sessoes = [$principal, ...$sessoes];

        $this->situacao = $principal->situacao;
        $this->pedido = $principal->pedido;
        // A sequência é uma por pessoa e loja: a da principal, senão a ativa, senão a mais recente.
        $comSequencia = array_values(array_filter($this->sessoes, fn (LinhaLead $s) => $s->registro !== null));
        $this->registro = $principal->registro
            ?? (collect($comSequencia)->first(fn (LinhaLead $s) => ! $s->registro->parado) ?? ($comSequencia[0] ?? null))?->registro;
        $elegivel = collect($this->sessoes)->first(fn (LinhaLead $s) => $s->podeAntecipar);
        $this->podeAntecipar = $elegivel !== null;
        $this->checkout = $elegivel?->checkout;
    }

    public function principal(): LinhaLead
    {
        return $this->sessoes[0];
    }

    /** Chave da sessão principal ("l12", "c5", "s3"); qualquer chave de sessão acha a pessoa. */
    public function chave(): string
    {
        return $this->principal()->chave();
    }

    /** @return list<string> */
    public function chaves(): array
    {
        return array_map(fn (LinhaLead $s) => $s->chave(), $this->sessoes);
    }

    public function site(): string
    {
        return $this->principal()->site();
    }

    public function nome(): ?string
    {
        return $this->primeiro(fn (LinhaLead $s) => $s->nome());
    }

    public function email(): ?string
    {
        return $this->valores('email')[0]['valor'] ?? null;
    }

    public function telefone(): ?string
    {
        return $this->telefones()[0]['numero'] ?? null;
    }

    public function cpf(): ?string
    {
        return $this->valores('cpf')[0]['valor'] ?? null;
    }

    public function cep(): ?string
    {
        return $this->valores('cep')[0]['valor'] ?? null;
    }

    /** @return list<array<string, mixed>> */
    public function itens(): array
    {
        return $this->principal()->itens();
    }

    public function total(): float
    {
        return $this->principal()->total();
    }

    public function ultimaAtividade(): ?Carbon
    {
        return collect($this->sessoes)->map(fn (LinhaLead $s) => $s->ultimaAtividade())->filter()->sortDesc()->first();
    }

    public function criadoEm(): ?Carbon
    {
        return $this->principal()->criadoEm();
    }

    /** @return array{origem: ?string, utm_source: ?string, utm_medium: ?string, utm_campaign: ?string} */
    public function origem(): array
    {
        return $this->principal()->origem();
    }

    public function proximoContato(): ?Carbon
    {
        return $this->registro && ! $this->registro->parado ? $this->registro->proximo_disparo_em : null;
    }

    /**
     * Todos os telefones da pessoa, sem repetir o mesmo número (com ou sem 55
     * e nono dígito), cada um com de onde veio. O primeiro é o principal.
     *
     * @return list<array{numero: string, origens: list<string>}>
     */
    public function telefones(): array
    {
        $achados = [];
        foreach ($this->sessoes as $s) {
            $usuario = $this->usuarioDa($s);
            foreach ([
                [$s->lead?->telefone, 'digitado no checkout'],
                [$s->checkout?->telefone, 'carrinho (Ir para Entrega)'],
                [$s->registro?->telefone, 'sequência de remarketing'],
                [$usuario?->phone, 'cadastro do cliente'],
                [$s->pedido?->shipping_phone, 'pedido (telefone)'],
                [$s->pedido?->shipping_whatsapp, 'pedido (WhatsApp)'],
            ] as [$numero, $origem]) {
                $digitos = (string) preg_replace('/\D/', '', (string) $numero);
                if ($digitos === '') {
                    continue;
                }
                $normal = WhatsAppPhone::normalize($digitos);
                $chave = $normal ? WhatsAppPhone::chave($normal) : $digitos;
                $achados[$chave] ??= ['numero' => $normal ? substr($normal, 2) : $digitos, 'origens' => []];
                if (! in_array($origem, $achados[$chave]['origens'], true)) {
                    $achados[$chave]['origens'][] = $origem;
                }
            }
        }

        return array_values($achados);
    }

    /**
     * Tudo o que foi digitado de um campo (nome, email, cpf, cep), sem repetir, com a origem.
     *
     * @return list<array{valor: string, origens: list<string>}>
     */
    public function valores(string $campo): array
    {
        $achados = [];
        foreach ($this->sessoes as $s) {
            $usuario = $this->usuarioDa($s);
            $fontes = [
                [$s->lead?->{$campo}, 'digitado no checkout'],
                [in_array($campo, ['nome', 'email', 'cpf'], true) ? $s->checkout?->{$campo} : null, 'carrinho (Ir para Entrega)'],
                [in_array($campo, ['nome', 'email'], true) ? $s->registro?->{$campo} : null, 'sequência de remarketing'],
                [match ($campo) { 'nome' => $usuario?->name, 'email' => $usuario?->email, 'cpf' => $usuario?->cpf, default => null }, 'cadastro do cliente'],
                [match ($campo) { 'nome' => $s->pedido?->shipping_name, 'email' => $s->pedido?->shipping_email, 'cep' => $s->pedido?->shipping_zip, default => null }, 'pedido'],
            ];
            foreach ($fontes as [$valor, $origem]) {
                $valor = trim((string) $valor);
                if ($valor === '') {
                    continue;
                }
                $chave = match ($campo) {
                    'email', 'nome' => mb_strtolower($valor),
                    default => (string) preg_replace('/\D/', '', $valor),
                };
                if ($chave === '') {
                    continue;
                }
                $achados[$chave] ??= ['valor' => in_array($campo, ['cpf', 'cep'], true) ? $chave : $valor, 'origens' => []];
                if (! in_array($origem, $achados[$chave]['origens'], true)) {
                    $achados[$chave]['origens'][] = $origem;
                }
            }
        }

        return array_values($achados);
    }

    public function textoDeBusca(): string
    {
        return implode(' ', array_map(fn (LinhaLead $s) => $s->textoDeBusca(), $this->sessoes));
    }

    /** @return list<string> */
    public function digitosDeBusca(): array
    {
        return array_merge(...array_map(fn (LinhaLead $s) => $s->digitosDeBusca(), $this->sessoes));
    }

    public function usuarioDa(LinhaLead $s): ?User
    {
        foreach ([$s->lead?->user_id, $s->checkout?->user_id, $s->registro?->user_id, $s->pedido?->user_id] as $id) {
            if ($id && isset($this->usuarios[$id])) {
                return $this->usuarios[$id];
            }
        }

        return null;
    }

    private function primeiro(callable $valor): ?string
    {
        foreach ($this->sessoes as $s) {
            if (filled($v = $valor($s))) {
                return $v;
            }
        }

        return null;
    }
}
