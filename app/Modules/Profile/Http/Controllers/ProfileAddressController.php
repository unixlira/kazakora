<?php

namespace App\Modules\Profile\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Models\Address;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Endereços do próprio cliente no "Meu Perfil" (pedido 2026-10-14):
 * cadastrar, editar, escolher o principal e excluir. Endereço que já tem
 * pedido não pode ser excluído; editar pode, porque o pedido guarda a
 * própria cópia do endereço (shipping_*).
 */
class ProfileAddressController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $this->validated($request);

        DB::transaction(function () use ($user, $data) {
            $principal = $data['is_default'] || ! $user->addresses()->exists();

            if ($principal) {
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create([...$data, 'is_default' => $principal]);
        });

        return back()->with('success', 'Endereço cadastrado.');
    }

    public function update(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwner($request, $address);
        $data = $this->validated($request);

        DB::transaction(function () use ($request, $address, $data) {
            if ($data['is_default']) {
                $request->user()->addresses()->whereKeyNot($address->id)->update(['is_default' => false]);
            }

            // O principal só deixa de ser principal quando outro é escolhido.
            $address->update([...$data, 'is_default' => $data['is_default'] || $address->is_default]);
        });

        return back()->with('success', 'Endereço atualizado.');
    }

    public function destroy(Request $request, Address $address): RedirectResponse
    {
        $this->authorizeOwner($request, $address);

        if ($address->orders()->exists()) {
            return back()->with('error', 'Este endereço já foi usado em um pedido e não pode ser excluído. Você pode editá-lo.');
        }

        DB::transaction(function () use ($request, $address) {
            $address->delete();

            if ($address->is_default) {
                $request->user()->addresses()->first()?->update(['is_default' => true]);
            }
        });

        return back()->with('success', 'Endereço excluído.');
    }

    private function authorizeOwner(Request $request, Address $address): void
    {
        if ((int) $address->user_id !== (int) $request->user()->id) {
            throw new HttpException(403, 'Este endereço não é da sua conta.');
        }
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'recipient_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'zip' => ['required', 'string', 'regex:/^\d{5}-?\d{3}$/'],
            'street' => ['required', 'string', 'max:255'],
            'number' => ['required', 'string', 'max:20'],
            'complement' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'state' => ['required', 'string', 'size:2'],
            'is_default' => ['boolean'],
        ], [
            'zip.regex' => 'Informe um CEP válido (00000-000).',
            'state.size' => 'A UF tem 2 letras (ex.: SP).',
        ], [
            'recipient_name' => 'nome de quem recebe',
            'phone' => 'telefone',
            'zip' => 'CEP',
            'street' => 'rua',
            'number' => 'número',
            'neighborhood' => 'bairro',
            'city' => 'cidade',
            'state' => 'UF',
        ]);

        return [...$data, 'is_default' => (bool) ($data['is_default'] ?? false)];
    }
}
