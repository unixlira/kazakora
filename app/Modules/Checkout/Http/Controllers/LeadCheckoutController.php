<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\LeadsDoCheckout;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /finalizacao/lead (pedido 2026-10-24): o "Ir para Entrega" do
 * checkout manda os dados da Identificação, sem esperar a resposta. Com
 * CSRF e limite por minuto. Responde sempre 204 e sem corpo: não diz se o
 * e-mail ou o CPF já existem, e uma falha aqui nunca aparece para o cliente.
 */
class LeadCheckoutController extends Controller
{
    public function store(Request $request, LeadsDoCheckout $leads): Response
    {
        $texto = fn (string $campo, int $max) => is_string($valor = $request->input($campo)) ? mb_substr($valor, 0, $max) : null;

        $leads->registrar($request, [
            'nome' => $texto('nome', 255),
            'email' => $texto('email', 255),
            'cpf' => $texto('cpf', 20),
            'telefone' => $texto('telefone', 20),
        ]);

        return response()->noContent();
    }
}
