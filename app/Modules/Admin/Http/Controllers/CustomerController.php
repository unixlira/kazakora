<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Support\CustomerAggregator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Cliente" aqui não é uma tabela própria — é Order agrupado por
 * documento (CPF/CNPJ) do comprador, unificando loja e canais externos.
 * Ver CustomerAggregator pro porquê disso e como o agrupamento funciona.
 */
class CustomerController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request, CustomerAggregator $customers): Response
    {
        // Paginado no servidor (ver CustomerAggregator::paginate()) — a busca
        // e a ordenação que antes rodavam no navegador sobre a lista inteira
        // viraram query param.
        $sort = in_array($request->string('sort')->toString(), CustomerAggregator::SORTABLE, true) ? $request->string('sort')->toString() : null;
        $direction = $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc';

        $paginator = $customers
            ->paginate(
                $request->string('search')->toString(),
                $sort,
                $sort ? $direction : 'desc',
                self::PER_PAGE,
                max(1, $request->integer('page', 1)),
                $request->url(),
            )
            ->withQueryString();

        return Inertia::render('Admin/Customers/Index', [
            'customers' => $paginator,
            'filters' => [
                'search' => $request->string('search')->toString(),
                'sort' => $sort,
                'direction' => $direction,
            ],
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
