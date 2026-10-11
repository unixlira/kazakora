<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Modules\Marketplace\Support\RelatoriosTikTok;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Recebe os relatórios do TikTok do app "KazaKora TikTok Reports" para a
 * auditoria financeira (2026-10-11). POST /api/tiktok/reports/import com
 * Authorization: Bearer <TIKTOK_REPORTS_TOKEN>; um ou vários arquivos em
 * multipart ou o arquivo cru no corpo (nome em X-File-Name).
 */
class TikTokReportsController extends Controller
{
    public function __construct(private readonly RelatoriosTikTok $relatorios)
    {
    }

    public function import(Request $request): JsonResponse
    {
        $esperado = (string) config('services.tiktok_reports.token');
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');

        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            // Uma vez por hora no máximo: robô batendo na rota não enche o sino.
            if (Cache::add('tiktok_reports.alerta_token', true, now()->addHour())) {
                $this->relatorios->avisar(false, 'Envio recusado: token inválido ou ausente (confira o ApiToken do app).');
            }

            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $resultado = [];

        try {
            foreach (collect($request->allFiles())->flatten()->filter(fn ($f) => $f instanceof UploadedFile && $f->isValid()) as $arquivo) {
                $resultado[] = $this->relatorios->guardar($arquivo->getClientOriginalName(), (string) file_get_contents($arquivo->getRealPath()), $arquivo->getClientMimeType(), $request->ip());
            }

            if ($resultado === [] && strlen($request->getContent()) > 0) {
                $nome = (string) ($request->header('X-File-Name') ?: 'relatorio-'.now()->format('Ymd-His').'.bin');
                $resultado[] = $this->relatorios->guardar($nome, $request->getContent(), $request->header('Content-Type'), $request->ip());
            }
        } catch (Throwable $e) {
            $this->relatorios->avisar(false, mb_substr($e->getMessage(), 0, 200));

            return response()->json(['ok' => false, 'erro' => $e->getMessage()], 422);
        }

        if ($resultado === []) {
            $this->relatorios->avisar(false, 'Nenhum arquivo veio no envio.');

            return response()->json(['ok' => false, 'erro' => 'Nenhum arquivo recebido.'], 422);
        }

        foreach (collect($resultado)->where('duplicado', false) as $r) {
            $ok = ! isset($r['erro_importacao']) && empty($r['importado']['divergencias'] ?? []);
            $this->relatorios->avisar($ok, $this->relatorios->descrever($r));
        }

        return response()->json(['ok' => true, 'arquivos' => $resultado]);
    }

    /** O agente do TikTok avisa falha (sessão caída, tela mudou): bolinha vermelha no sino. */
    public function status(Request $request): JsonResponse
    {
        $esperado = (string) config('services.tiktok_reports.token');
        if ($esperado === '' || ! hash_equals($esperado, (string) Str::after((string) $request->header('Authorization'), 'Bearer '))) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $dados = $request->validate(['ok' => ['required', 'boolean'], 'mensagem' => ['required', 'string', 'max:500']]);
        $this->relatorios->avisar((bool) $dados['ok'], $dados['mensagem']);

        return response()->json(['ok' => true]);
    }
}
