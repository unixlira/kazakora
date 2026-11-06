<?php

namespace App\Modules\Checkout\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Support\LeadsDigitados;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /finalizacao/lead-campo (Leads, pedido do Lira 2026-11-06): o
 * checkout manda os campos (nome, email, cpf, telefone, cep) a cada campo
 * preenchido. Com CSRF e limite por minuto. Responde sempre 204 sem corpo:
 * não revela nada e uma falha aqui nunca aparece para o cliente.
 */
class LeadCampoController extends Controller
{
    public function store(Request $request, LeadsDigitados $leads): Response
    {
        $campos = [];
        foreach (Lead::CAMPOS as $campo) {
            $valor = $request->input($campo);
            $campos[$campo] = is_string($valor) ? mb_substr($valor, 0, 300) : null;
        }

        $ultimo = $request->input('campo');
        $leads->registrar($request, $campos, is_string($ultimo) ? $ultimo : null);

        return response()->noContent();
    }
}
