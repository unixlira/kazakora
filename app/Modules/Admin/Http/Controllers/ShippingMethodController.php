<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Operacional\Models\ShippingMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ShippingMethodController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Logistica/Index', [
            'shippingMethods' => ShippingMethod::query()->orderBy('price')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        ShippingMethod::create($this->validated($request));

        return back()->with('success', 'Método de envio criado.');
    }

    public function update(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->update($this->validated($request));

        return back()->with('success', 'Método de envio atualizado.');
    }

    public function destroy(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->delete();

        return back()->with('success', 'Método de envio removido.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'estimated_days' => ['required', 'integer', 'min:0'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
