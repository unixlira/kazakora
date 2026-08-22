<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\ApiPartner;
use App\Support\Rbac\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Gestão de parceiros da API pública — pedido explícito 2026-08-21.
 * Hard-gated a Admin (ver `middleware('admin')` no grupo de rotas, mesmo
 * padrão de Usuários/Permissões e Auditoria) — emitir um token com acesso
 * de escrita não é algo que Manager/Subscriber deveria poder fazer, mesmo
 * que tivessem `configuracoes.*` liberado (essas permissões nem são
 * configuráveis pra esses papéis, ver Permissions::CONFIGURABLE).
 */
class ApiPartnerController extends Controller
{
    public function index(): Response
    {
        $partners = ApiPartner::query()
            ->withCount('tokens')
            ->latest('id')
            ->get()
            ->map(fn (ApiPartner $partner) => [
                'id' => $partner->id,
                'name' => $partner->name,
                'slug' => $partner->slug,
                'contact_email' => $partner->contact_email,
                // Nunca o hash em si (nem faria sentido pro admin) — só se
                // já existe uma senha configurada, pra tela mostrar
                // "login por senha ativo" sem reexibir/reenviar nada.
                'has_password' => $partner->password !== null,
                'abilities' => $partner->abilities ?? [],
                'rate_limit_per_minute' => $partner->rate_limit_per_minute,
                'is_active' => $partner->is_active,
                'last_used_at' => $partner->last_used_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'tokens_count' => $partner->tokens_count,
                'tokens' => $partner->tokens->map(fn ($token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'last_used_at' => $token->last_used_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                    'created_at' => $token->created_at->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                ]),
            ]);

        return Inertia::render('Admin/ApiPartners/Index', [
            'partners' => $partners,
            'availableAbilities' => Permissions::ALL,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            // Opcional na criação — parceiro pode nascer só com token
            // estático (fluxo original) e ganhar senha/login JWT depois,
            // ou vice-versa.
            'password' => ['nullable', 'string', 'min:8'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(Permissions::ALL)],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'notes' => ['nullable', 'string'],
        ]);

        $partner = ApiPartner::create([
            ...$validated,
            'slug' => $this->uniqueSlug($validated['name']),
            'rate_limit_per_minute' => $validated['rate_limit_per_minute'] ?? 60,
            'created_by' => $request->user()->id,
        ]);

        return back()->with('success', "Parceiro \"{$partner->name}\" criado. Gere um token ou avise o login (\"{$partner->slug}\") pra ele começar a usar a API.");
    }

    public function update(Request $request, ApiPartner $apiPartner): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'password' => ['nullable', 'string', 'min:8'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => [Rule::in(Permissions::ALL)],
            'rate_limit_per_minute' => ['nullable', 'integer', 'min:1', 'max:6000'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ]);

        // Campo deixado em branco no formulário de edição = "não mexer na
        // senha atual", não "apagar a senha" — sem isso, o cast 'hashed'
        // hasheia '' e derruba o login por senha de todo mundo toda vez
        // que o admin salva QUALQUER outra edição no parceiro.
        if (! filled($validated['password'] ?? null)) {
            unset($validated['password']);
        }

        $apiPartner->update($validated);

        // Pedido explícito: mudar as abilities de um parceiro NÃO revoga
        // tokens já emitidos automaticamente (eles carregam a lista
        // congelada de quando foram criados, comportamento padrão do
        // Sanctum) — o admin precisa gerar um token novo pro parceiro se
        // quiser que o acesso reflita a mudança nos tokens já em uso.
        // Desativar o parceiro (`is_active=false`) continua bloqueando
        // TODOS os tokens dele na hora, via EnsureApiPartnerIsActive.

        return back()->with('success', "Parceiro \"{$apiPartner->name}\" atualizado. Tokens já emitidos mantêm as abilities antigas até serem regerados.");
    }

    /**
     * Devolve o token em TEXTO PURO só nesta resposta — Sanctum nunca
     * grava o valor real no banco (só o hash), então essa é a ÚNICA
     * chance de o admin ver/copiar o token completo. flash de sessão de
     * propósito (não fica em nenhum lugar persistente do banco).
     */
    public function issueToken(Request $request, ApiPartner $apiPartner): RedirectResponse
    {
        $validated = $request->validate([
            'token_name' => ['nullable', 'string', 'max:100'],
        ]);

        abort_if(! $apiPartner->is_active, 422, 'Parceiro está inativo — ative antes de gerar um token novo.');

        $token = $apiPartner->createToken(
            $validated['token_name'] ?? 'token-'.now()->format('Ymd-His'),
            $apiPartner->allowedAbilities(),
        );

        return back()->with([
            'success' => 'Token gerado — copie agora, ele não será mostrado de novo.',
            'plainTextToken' => $token->plainTextToken,
        ]);
    }

    public function revokeToken(ApiPartner $apiPartner, int $token): RedirectResponse
    {
        $apiPartner->tokens()->whereKey($token)->delete();

        return back()->with('success', 'Token revogado — qualquer chamada usando ele passa a ser rejeitada imediatamente.');
    }

    /**
     * Remoção NUNCA apaga o histórico em api_request_logs (nullOnDelete,
     * ver a migração) — o parceiro some da tela de gestão, mas o que ele
     * já fez continua auditável. Todos os tokens dele são revogados junto
     * (Sanctum não tem FK/cascade automático pra isso — é polimórfico).
     */
    public function destroy(ApiPartner $apiPartner): RedirectResponse
    {
        $apiPartner->tokens()->delete();
        $apiPartner->delete();

        return back()->with('success', 'Parceiro removido — todos os tokens dele foram revogados.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $suffix = 1;

        while (ApiPartner::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-".++$suffix;
        }

        return $slug;
    }
}
