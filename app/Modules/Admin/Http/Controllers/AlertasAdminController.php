<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Models\AlertaAdmin;
use App\Modules\Admin\Support\AlertasAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sistema › Alertas (pedido 2026-10-16): os alertas abertos, o histórico e
 * quais tipos de alerta estão ligados. Admin-only.
 */
class AlertasAdminController extends Controller
{
    public function index(AlertasAdmin $alertas): Response
    {
        return Inertia::render('Admin/Alertas/Index', [
            'abertos' => AlertaAdmin::query()->abertos()->latest('ultima_em')->get()->map->paraTela(),
            'historico' => AlertaAdmin::query()->whereNotNull('resolvido_em')->latest('resolvido_em')->limit(50)->get()->map->paraTela(),
            'tipos' => collect(AlertasAdmin::TIPOS)->map(fn ($tipo, $chave) => $tipo + [
                'chave' => $chave,
                'ativo' => $alertas->ativo($chave),
            ])->values(),
        ]);
    }

    public function resolver(Request $request, AlertasAdmin $alertas, AlertaAdmin $alerta): RedirectResponse
    {
        if ($alerta->resolvido_em === null) {
            $alerta->forceFill(['resolvido_em' => now(), 'resolvido_por' => $request->user()->name])->save();
            $alertas->tirarDoSino([$alerta->id]);
        }

        return back()->with('success', 'Alerta marcado como resolvido. Se o problema voltar, ele abre de novo.');
    }

    public function tipo(Request $request, AlertasAdmin $alertas, string $tipo): RedirectResponse
    {
        abort_unless(isset(AlertasAdmin::TIPOS[$tipo]), 404);
        $ativo = $request->boolean('ativo');
        $alertas->definirAtivo($tipo, $ativo);

        if (! $ativo) {
            $abertos = AlertaAdmin::query()->abertos()->where('tipo', $tipo);
            $alertas->tirarDoSino($abertos->pluck('id')->all());
            $abertos->update(['resolvido_em' => now(), 'resolvido_por' => $request->user()->name.' (desligou o alerta)', 'updated_at' => now()]);
        }

        return back()->with('success', $ativo ? 'Alerta ligado.' : 'Alerta desligado.');
    }
}
