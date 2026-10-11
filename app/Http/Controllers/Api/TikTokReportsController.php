<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\FiscalAlertNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Recebe os relatórios do TikTok (Income/Settlement, pedidos, anúncios) do
 * app "KazaKora TikTok Reports" para a auditoria financeira (2026-10-11).
 *
 * POST /api/tiktok/reports/import, header Authorization: Bearer <token>
 * (TIKTOK_REPORTS_TOKEN). Aceita um ou vários arquivos em multipart (qualquer
 * nome de campo) ou o arquivo cru no corpo (nome em X-File-Name). O mesmo
 * arquivo (sha256) não é guardado duas vezes.
 */
class TikTokReportsController extends Controller
{
    private const EXTENSOES = ['xlsx', 'xls', 'csv', 'zip', 'json', 'pdf', 'txt'];

    public function import(Request $request): JsonResponse
    {
        $esperado = (string) config('services.tiktok_reports.token');
        $recebido = (string) Str::after((string) $request->header('Authorization'), 'Bearer ');

        if ($esperado === '' || ! hash_equals($esperado, $recebido)) {
            return response()->json(['ok' => false, 'erro' => 'Token inválido.'], 401);
        }

        $arquivos = collect($request->allFiles())->flatten()->filter(fn ($f) => $f instanceof UploadedFile && $f->isValid());
        $resultado = [];

        foreach ($arquivos as $arquivo) {
            $resultado[] = $this->guardar($arquivo->getClientOriginalName(), (string) file_get_contents($arquivo->getRealPath()), $arquivo->getClientMimeType(), $request);
        }

        if ($resultado === [] && strlen($request->getContent()) > 0) {
            $nome = (string) ($request->header('X-File-Name') ?: 'relatorio-'.now()->format('Ymd-His').'.bin');
            $resultado[] = $this->guardar($nome, $request->getContent(), $request->header('Content-Type'), $request);
        }

        if ($resultado === []) {
            return response()->json(['ok' => false, 'erro' => 'Nenhum arquivo recebido.'], 422);
        }

        $novos = collect($resultado)->where('duplicado', false)->count();
        if ($novos > 0) {
            $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
            rescue(fn () => Notification::send($admins, new FiscalAlertNotification("TikTok: {$novos} relatório(s) novo(s) recebido(s) para a auditoria financeira.")), report: false);
        }

        return response()->json(['ok' => true, 'arquivos' => $resultado]);
    }

    /** @return array{id: int, nome: string, sha256: string, duplicado: bool} */
    private function guardar(string $nome, string $conteudo, ?string $mime, Request $request): array
    {
        $nome = Str::limit(basename(str_replace('\\', '/', $nome)), 200, '');
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        abort_if($extensao !== '' && ! in_array($extensao, self::EXTENSOES, true) && $extensao !== 'bin', 422, "Tipo de arquivo não aceito: .{$extensao}");
        abort_if(strlen($conteudo) > 50 * 1024 * 1024, 413, 'Arquivo maior que 50 MB.');

        $sha = hash('sha256', $conteudo);
        $existente = DB::table('tiktok_report_uploads')->where('sha256', $sha)->first();

        if ($existente) {
            return ['id' => $existente->id, 'nome' => $existente->nome_original, 'sha256' => $sha, 'duplicado' => true];
        }

        $caminho = 'tiktok-reports/'.now()->format('Y-m-d').'/'.substr($sha, 0, 12).'-'.Str::slug(pathinfo($nome, PATHINFO_FILENAME)).($extensao ? ".{$extensao}" : '');
        Storage::disk('local')->put($caminho, $conteudo);

        $id = DB::table('tiktok_report_uploads')->insertGetId([
            'nome_original' => $nome,
            'caminho' => $caminho,
            'sha256' => $sha,
            'tamanho' => strlen($conteudo),
            'mime' => $mime ? Str::limit($mime, 120, '') : null,
            'tipo' => $this->tipo($nome),
            'ip' => $request->ip(),
            'status' => 'recebido',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['id' => $id, 'nome' => $nome, 'sha256' => $sha, 'duplicado' => false];
    }

    private function tipo(string $nome): ?string
    {
        $n = mb_strtolower($nome);

        return match (true) {
            str_contains($n, 'income') || str_contains($n, 'settlement') || str_contains($n, 'liquid') => 'liquidacao',
            str_contains($n, 'order') || str_contains($n, 'pedido') => 'pedidos',
            str_contains($n, 'ads') || str_contains($n, 'campaign') || str_contains($n, 'gmv') => 'anuncios',
            str_contains($n, 'return') || str_contains($n, 'refund') || str_contains($n, 'devol') => 'devolucoes',
            default => null,
        };
    }
}
