<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Support\CatalogoMarketplaces;
use App\Modules\Checkout\Models\Order;
use App\Support\Rbac\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Menu Marketplaces (pedido do Lira, 17/10/2026): lista dos marketplaces
 * integrados e, em cada um, as abas Oportunidades, Promoção, Imagens,
 * Videoclipes, Cupom de desconto e Gerar campanha.
 *
 * Aba com tela pronta chama o MESMO controller da tela antiga e devolve a
 * MESMA página Vue, só acrescentando a prop `marketplaceNav` (o
 * AdminLayout desenha as abas em cima quando ela existe). Assim o
 * endereço antigo continua funcionando e nada é copiado.
 */
class MarketplacesController extends Controller
{
    public function index(): Response
    {
        $desde = Carbon::now()->subDays(30);
        $pedidos = Order::query()
            ->whereIn('origin', array_column(CatalogoMarketplaces::todos(), 'canal'))
            ->where('created_at', '>=', $desde)
            ->selectRaw('origin, count(*) as total')
            ->groupBy('origin')
            ->pluck('total', 'origin');

        $lista = [];
        foreach (CatalogoMarketplaces::todos() as $slug => $m) {
            $prontas = count(array_filter($m['abas'], fn (array $aba) => isset($aba['tela'])));
            $lista[] = [
                'slug' => $slug,
                'nome' => $m['nome'],
                'logo' => $m['logo'],
                'integracao' => $m['integracao'],
                'href' => CatalogoMarketplaces::url($slug),
                'pedidos30d' => (int) ($pedidos[$m['canal']] ?? 0),
                'abasProntas' => $prontas,
                'abasTotal' => count($m['abas']),
            ];
        }

        return Inertia::render('Admin/Marketplaces/Index', ['marketplaces' => $lista]);
    }

    public function show(Request $request, string $marketplace, ?string $aba = null)
    {
        $m = CatalogoMarketplaces::encontrar($marketplace);
        abort_if($m === null, 404);

        $aba ??= CatalogoMarketplaces::ABA_PADRAO;
        abort_unless(isset(CatalogoMarketplaces::ABAS[$aba]), 404);

        $conf = $m['abas'][$aba];
        $nav = $this->nav($marketplace, $m, $aba, $conf);

        if (isset($conf['tela']) && $this->pode($request, $conf['permissao'])) {
            // Filtro fixo da aba (ex.: Fotos de anúncio só do marketplace),
            // sem passar por cima do que veio na URL.
            foreach ($conf['query'] ?? [] as $chave => $valor) {
                if (! $request->query->has($chave)) {
                    $request->query->set($chave, $valor);
                }
            }

            [$classe, $metodo] = $conf['tela'];
            $resposta = app()->call([app($classe), $metodo], $conf['params'] ?? []);

            return $resposta->with('marketplaceNav', $nav);
        }

        $atalho = $conf['atalho'] ?? null;
        if ($atalho && ! $this->pode($request, $atalho['permissao'])) {
            $atalho = null;
        }

        return Inertia::render('Admin/Marketplaces/Aba', [
            'marketplaceNav' => $nav,
            'estado' => isset($conf['tela']) ? 'sem-permissao' : 'em-breve',
            'texto' => $conf['em_breve'] ?? null,
            'atalho' => $atalho ? collect($atalho)->only(['nome', 'href', 'texto'])->all() : null,
        ]);
    }

    private function pode(Request $request, string $permissao): bool
    {
        $user = $request->user();

        return $permissao === 'admin'
            ? (bool) $user?->isAdmin()
            : Permissions::has($user, $permissao);
    }

    /**
     * @param  array<string, mixed>  $m
     * @param  array<string, mixed>  $conf
     * @return array<string, mixed>
     */
    private function nav(string $slug, array $m, string $aba, array $conf): array
    {
        $abas = [];
        foreach (CatalogoMarketplaces::ABAS as $chave => $nome) {
            $abas[] = [
                'slug' => $chave,
                'nome' => $nome,
                'href' => CatalogoMarketplaces::url($slug, $chave),
                'pronta' => isset($m['abas'][$chave]['tela']),
            ];
        }

        return [
            'slug' => $slug,
            'nome' => $m['nome'],
            'logo' => $m['logo'],
            'aba' => $aba,
            'abaNome' => CatalogoMarketplaces::ABAS[$aba],
            'url' => CatalogoMarketplaces::url($slug, $aba),
            'abas' => $abas,
            'origem' => $conf['origem'] ?? null,
            'aviso' => $conf['aviso'] ?? null,
        ];
    }
}
