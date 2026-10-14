<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\Diagnostico\EstrategiaShopee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Publicidade › Shopee › Estratégia (tarefas 568/569, 2026-10-14): lista os
 * anúncios de ADS da Shopee que dão prejuízo, com imagem, ROAS real contra
 * empate, meta sugerida (empate + 2), conversão do anúncio e a decisão do
 * Cauê. Só leitura: nada é alterado na Shopee por esta tela.
 *
 * A consulta à API da Shopee leva alguns segundos, por isso fica 3 horas
 * em cache; "Atualizar agora" busca de novo.
 */
class ShopeeEstrategiaController extends Controller
{
    public const PERIODOS = [7, 15, 30];

    public function index(Request $request): Response
    {
        $dias = $this->dias($request);
        $erro = null;
        try {
            $dados = Cache::remember(self::chave($dias), now()->addHours(3), fn () => app(EstrategiaShopee::class)->gerar($dias));
        } catch (Throwable $e) {
            report($e);
            $dados = null;
            $erro = 'Não foi possível consultar a Shopee agora. Tente "Atualizar agora" em alguns minutos.';
        }

        return Inertia::render('Admin/Publicidade/ShopeeEstrategia', [
            'dados' => $dados,
            'erro' => $erro,
            'dias' => $dias,
            'periodos' => self::PERIODOS,
        ]);
    }

    public function atualizar(Request $request): RedirectResponse
    {
        $dias = $this->dias($request);
        Cache::forget(self::chave($dias));

        return redirect()->route('admin.publicidade.shopee.estrategia', ['dias' => $dias]);
    }

    public static function chave(int $dias): string
    {
        return "publicidade:shopee:estrategia:{$dias}";
    }

    private function dias(Request $request): int
    {
        $dias = (int) $request->input('dias', 30);

        return in_array($dias, self::PERIODOS, true) ? $dias : 30;
    }
}
