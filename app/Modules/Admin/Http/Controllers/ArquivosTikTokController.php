<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Financeiro > Arquivos do TikTok: envio manual dos relatórios baixados no Seller Center (2026-10-11). */
class ArquivosTikTokController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/ArquivosTikTok/Index', [
            'arquivos' => DB::table('tiktok_report_uploads')->orderByDesc('id')->limit(100)->get()
                ->map(fn ($a) => [
                    'id' => $a->id, 'nome' => $a->nome_original, 'tipo' => $a->tipo, 'status' => $a->status,
                    'tamanho_kb' => (int) ceil($a->tamanho / 1024), 'recebido_em' => $a->created_at,
                    'origem' => $a->ip ? 'API' : 'Tela',
                ]),
        ]);
    }

    public function store(Request $request, RelatoriosTikTok $relatorios): RedirectResponse
    {
        $request->validate(['arquivos' => ['required', 'array', 'max:20'], 'arquivos.*' => ['file', 'max:51200']]);

        $novos = [];
        $repetidos = 0;

        try {
            foreach ($request->file('arquivos') as $arquivo) {
                $r = $relatorios->guardar($arquivo->getClientOriginalName(), (string) file_get_contents($arquivo->getRealPath()), $arquivo->getClientMimeType(), null);
                $r['duplicado'] ? $repetidos++ : $novos[] = $r['nome'];
            }
        } catch (Throwable $e) {
            $relatorios->avisar(false, mb_substr($e->getMessage(), 0, 200));

            return back()->with('error', 'Erro importação arquivo financeiro TikTok: '.$e->getMessage());
        }

        if ($novos !== []) {
            $relatorios->avisar(true, implode(', ', $novos));
        }

        return back()->with('success', count($novos).' arquivo(s) recebido(s)'.($repetidos ? ", {$repetidos} já existia(m)" : '').'.');
    }
}
