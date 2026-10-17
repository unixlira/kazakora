<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Models\MercadoLivreCupom;
use App\Modules\Marketplace\Support\MercadoLivreCupomService;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Integrações › Mercado Livre › Criar cupom (pedido do Lira, 16/10/2026).
 * Abre com o modelo validado (50%, teto R$ 6, compra mínima = 2% do
 * ticket médio do ML), tudo editável. O envio cria cupom DE VERDADE no ML,
 * por isso: só admin, confirmação na tela e registro de quem enviou.
 */
class MercadoLivreCupomController extends Controller
{
    public function index(MercadoLivreCupomService $service): Response
    {
        $ticket = $service->ticketMedio();

        return Inertia::render('Admin/Integracoes/MercadoLivre/Cupom', [
            'modelo' => $service->modelo($ticket),
            'ticket' => $ticket,
            'envioHabilitado' => $service->envioHabilitado(),
            'historico' => MercadoLivreCupom::query()
                ->with('user:id,name')
                ->latest('id')
                ->limit(20)
                ->get()
                ->map(fn (MercadoLivreCupom $c) => [
                    'id' => $c->id,
                    'nome' => $c->nome,
                    'mlId' => $c->ml_promotion_id,
                    'status' => $c->status,
                    'codigo' => $c->resposta['coupon_code'] ?? null,
                    'percentual' => $c->payload['fixed_percentage'] ?? null,
                    'descontoMaximo' => $c->payload['max_purchase_amount'] ?? null,
                    'compraMinima' => $c->payload['min_purchase_amount'] ?? null,
                    'orcamento' => $c->payload['budget'] ?? null,
                    'inicio' => substr((string) ($c->payload['start_date'] ?? ''), 0, 10),
                    'fim' => substr((string) ($c->payload['finish_date'] ?? ''), 0, 10),
                    'itensAdicionados' => $c->itens_adicionados,
                    'itensComErro' => $c->itens_com_erro,
                    'erro' => $c->erro,
                    'usuario' => $c->user?->name,
                    'criadoEm' => $c->created_at,
                ]),
        ]);
    }

    public function store(Request $request, MercadoLivreCupomService $service): RedirectResponse
    {
        $hoje = Carbon::today()->toDateString();

        $dados = $request->validate([
            'nome' => ['required', 'string', 'max:60'],
            'percentual' => ['required', 'numeric', 'min:5', 'max:80'],
            'desconto_maximo' => ['required', 'numeric', 'gt:5', 'max:100000'],
            'compra_minima' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'orcamento' => ['required', 'numeric', 'gt:desconto_maximo', 'max:1000000'],
            'inicio' => ['required', 'date_format:Y-m-d', "after_or_equal:{$hoje}"],
            'fim' => ['required', 'date_format:Y-m-d', 'after_or_equal:inicio'],
            'codigo' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{1,5}$/'],
            'incluir_anuncios' => ['boolean'],
        ], [
            'desconto_maximo.gt' => 'O Mercado Livre exige desconto máximo acima de R$ 5.',
            'orcamento.gt' => 'O orçamento precisa ser maior que o desconto máximo.',
            'inicio.after_or_equal' => 'O início não pode ser antes de hoje.',
            'codigo.regex' => 'Código: até 5 letras ou números (o ML coloca o começo do apelido da loja na frente).',
        ]);

        // Regras do ML que dependem de mais de um campo.
        if (Carbon::parse($dados['inicio'])->diffInDays(Carbon::parse($dados['fim'])) > 30) {
            throw ValidationException::withMessages(['fim' => 'O Mercado Livre aceita no máximo 31 dias de cupom.']);
        }

        if ($dados['compra_minima'] * $dados['percentual'] / 100 >= $dados['desconto_maximo']) {
            $limite = number_format($dados['desconto_maximo'] * 100 / $dados['percentual'], 2, ',', '.');
            throw ValidationException::withMessages([
                'compra_minima' => "Com {$dados['percentual']}% e teto de R$ {$dados['desconto_maximo']}, o ML só aceita compra mínima abaixo de R$ {$limite}.",
            ]);
        }

        if (! $service->envioHabilitado()) {
            return back()->with('error', 'Envio desligado neste ambiente (homolog usa a conta real do Mercado Livre). Nada foi enviado.');
        }

        try {
            $cupom = $service->criar($dados, $request->user());
        } catch (MercadoLivreException $e) {
            return back()->with('error', 'O Mercado Livre recusou o cupom: '.$e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Não consegui criar o cupom: '.$e->getMessage());
        }

        $msg = "Cupom criado no Mercado Livre ({$cupom->ml_promotion_id}).";
        if ($dados['incluir_anuncios'] ?? false) {
            $msg .= " Anúncios incluídos: {$cupom->itens_adicionados}".($cupom->itens_com_erro ? ", com erro: {$cupom->itens_com_erro}" : '').'.';
        }

        return back()->with($cupom->itens_com_erro || $cupom->erro ? 'warning' : 'success', $msg);
    }
}
