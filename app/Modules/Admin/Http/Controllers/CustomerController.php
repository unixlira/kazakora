<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\CustomerAggregator;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Cliente" aqui não é uma tabela própria — é Order agrupado por
 * documento (CPF/CNPJ) do comprador, unificando loja e canais externos.
 * Ver CustomerAggregator pro porquê disso e como o agrupamento funciona.
 */
class CustomerController extends Controller
{
    public function index(CustomerAggregator $customers): Response
    {
        return Inertia::render('Admin/Customers/Index', [
            'customers' => $customers->list(),
        ]);
    }

    public function show(string $document, CustomerAggregator $customers): Response|RedirectResponse
    {
        $analytics = $customers->analytics($document);

        if (! $analytics) {
            return redirect()->route('admin.clientes.listar')->with('error', 'Cliente não encontrado.');
        }

        return Inertia::render('Admin/Customers/Show', $analytics);
    }
}
