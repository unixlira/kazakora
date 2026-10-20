<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\App\Services\CatalogoApp;
use App\Modules\App\Services\CheckoutApp;
use App\Modules\Checkout\Support\CartStockChangedException;
use App\Modules\Checkout\Support\CompletarEnderecoPeloCep;
use App\Services\MercadoPago\CredenciaisCheckout;
use App\Support\DescontoPix;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rotas públicas do app nativo (2026-10-18): configuração, home,
 * departamentos, produtos, CEP e frete. Contrato em kazakora-app/docs/api.md.
 */
class LojaController extends Controller
{
    public function __construct(private readonly CatalogoApp $catalogo) {}

    public function config(): JsonResponse
    {
        $site = rtrim((string) config('app.url'), '/');
        $whatsapp = (string) config('app_mobile.whatsapp_numero');
        $pix = DescontoPix::maiorPercentual();

        return response()->json([
            'loja' => [
                'nome' => 'KazaKora',
                'whatsapp_numero' => $whatsapp,
                'whatsapp_link' => 'https://wa.me/'.$whatsapp,
                'politica_url' => $site.'/politica-de-privacidade',
                'termos_url' => $site.'/termos-de-uso',
                'site_url' => $site,
            ],
            // Só a chave PÚBLICA (tokeniza o cartão no aparelho); a privada nunca sai do servidor.
            'mercadopago_public_key' => CredenciaisCheckout::publicKey(),
            'desconto_pix_percentual' => floor($pix) == $pix ? (int) $pix : $pix,
            'versao_minima' => config('app_mobile.versao_minima'),
        ]);
    }

    public function inicio(): JsonResponse
    {
        return response()->json($this->catalogo->inicio());
    }

    public function departamentos(): JsonResponse
    {
        return response()->json(['departamentos' => $this->catalogo->departamentos()]);
    }

    public function produtos(Request $request): JsonResponse
    {
        $dados = $request->validate([
            'busca' => ['nullable', 'string', 'max:100'],
            'departamento' => ['nullable', 'string', 'max:120'],
            'ordem' => ['nullable', 'in:'.implode(',', CatalogoApp::ORDENS)],
            'pagina' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'por_pagina' => ['nullable', 'integer', 'min:1', 'max:48'],
        ], [
            'ordem.in' => 'Ordem inválida.',
            'por_pagina.max' => 'No máximo 48 produtos por página.',
        ]);

        return response()->json($this->catalogo->produtos(
            $dados['busca'] ?? null,
            $dados['departamento'] ?? null,
            $dados['ordem'] ?? 'relevancia',
            (int) ($dados['pagina'] ?? 1),
            (int) ($dados['por_pagina'] ?? 20),
        ));
    }

    public function produto(string $slug): JsonResponse
    {
        $produto = $this->catalogo->produto($slug);

        return $produto
            ? response()->json(['produto' => $produto])
            : response()->json(['message' => 'Produto não encontrado.'], 404);
    }

    public function cep(string $cep, CompletarEnderecoPeloCep $completar): JsonResponse
    {
        $numeros = preg_replace('/\D/', '', $cep);
        if (strlen($numeros) !== 8) {
            return response()->json(['message' => 'Informe um CEP com 8 números.', 'errors' => ['cep' => ['Informe um CEP com 8 números.']]], 422);
        }

        $endereco = $completar->completar(['zip' => $numeros]);

        return blank($endereco['city'] ?? null)
            ? response()->json(['message' => 'CEP não encontrado.'], 404)
            : response()->json([
                'cep' => $numeros,
                'rua' => $endereco['street'] ?? null,
                'bairro' => $endereco['neighborhood'] ?? null,
                'cidade' => $endereco['city'],
                'uf' => $endereco['state'] ?? null,
            ]);
    }

    /** Opções de entrega do carrinho para o CEP: o mesmo cálculo do resumo do checkout. */
    public function frete(Request $request, CheckoutApp $checkout): JsonResponse
    {
        $dados = $request->validate([
            ...CheckoutAppController::regrasDosItens(),
            'cep' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
        ], ['cep.required' => 'Informe o CEP.', 'cep.regex' => 'Informe um CEP com 8 números.']);
        $cep = preg_replace('/\D/', '', $dados['cep']);

        try {
            $resumo = $checkout->resumo($dados['itens'], $cep);
        } catch (CartStockChangedException $e) {
            return response()->json(['message' => $e->getMessage(), 'estoque' => true], 409);
        }

        return response()->json([
            'opcoes' => $resumo['entrega']['opcoes'],
            'escolhida' => $resumo['entrega']['escolhida'],
            'prazo_dias' => $resumo['prazo_dias'],
        ]);
    }
}
