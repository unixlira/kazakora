<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Notifications\TikTokRelatorioNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Arquivos financeiros do TikTok para a auditoria (2026-10-11): chegam pela
 * API (app de relatórios) ou pela tela Financeiro > Arquivos do TikTok.
 * O mesmo arquivo (sha256) não é guardado duas vezes.
 */
class RelatoriosTikTok
{
    public const EXTENSOES = ['xlsx', 'xls', 'csv', 'zip', 'json', 'pdf', 'txt', 'bin'];

    public const MAX_BYTES = 50 * 1024 * 1024;

    /** @return array{id: int, nome: string, sha256: string, duplicado: bool} */
    public function guardar(string $nome, string $conteudo, ?string $mime, ?string $ip): array
    {
        $nome = Str::limit(basename(str_replace('\\', '/', $nome)), 200, '');
        $extensao = strtolower(pathinfo($nome, PATHINFO_EXTENSION));

        if ($extensao !== '' && ! in_array($extensao, self::EXTENSOES, true)) {
            throw new RuntimeException("Tipo de arquivo não aceito: .{$extensao}");
        }
        if (strlen($conteudo) > self::MAX_BYTES) {
            throw new RuntimeException('Arquivo maior que 50 MB.');
        }
        if ($conteudo === '') {
            throw new RuntimeException('Arquivo vazio.');
        }

        $sha = hash('sha256', $conteudo);
        $existente = DB::table('tiktok_report_uploads')->where('sha256', $sha)->first();

        if ($existente) {
            return ['id' => $existente->id, 'nome' => $existente->nome_original, 'sha256' => $sha, 'duplicado' => true];
        }

        $caminho = 'tiktok-reports/'.now()->format('Y-m-d').'/'.substr($sha, 0, 12).'-'.Str::slug(pathinfo($nome, PATHINFO_FILENAME)).($extensao ? ".{$extensao}" : '');
        Storage::disk('local')->put($caminho, $conteudo);

        $id = DB::table('tiktok_report_uploads')->insertGetId([
            'nome_original' => $nome, 'caminho' => $caminho, 'sha256' => $sha, 'tamanho' => strlen($conteudo),
            'mime' => $mime ? Str::limit($mime, 120, '') : null, 'tipo' => $this->tipo($nome), 'ip' => $ip,
            'status' => 'recebido', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'nome' => $nome, 'sha256' => $sha, 'duplicado' => false] + $this->processar($id);
    }

    /**
     * Demonstrativo do TikTok (.xlsx): importa na hora para a auditoria. Outros
     * tipos ficam só guardados.
     *
     * @return array{importado?: array<string, mixed>, erro_importacao?: string}
     */
    public function processar(int $uploadId): array
    {
        $upload = DB::table('tiktok_report_uploads')->find($uploadId);
        if (! $upload || ! str_ends_with(strtolower($upload->nome_original), '.xlsx')) {
            return [];
        }

        try {
            $resumo = app(ImportadorDemonstrativoTikTok::class)->importar(Storage::disk('local')->path($upload->caminho), $upload->nome_original);
            DB::table('tiktok_report_uploads')->where('id', $uploadId)->update(['status' => 'importado', 'tipo' => 'liquidacao', 'updated_at' => now()]);

            return ['importado' => $resumo];
        } catch (\Throwable $e) {
            DB::table('tiktok_report_uploads')->where('id', $uploadId)->update(['status' => 'erro', 'updated_at' => now()]);

            return ['erro_importacao' => mb_substr($e->getMessage(), 0, 300)];
        }
    }

    /** Texto do alerta a partir do que guardar() devolveu. */
    public function descrever(array $r): string
    {
        if (isset($r['erro_importacao'])) {
            return "{$r['nome']}: {$r['erro_importacao']}";
        }
        if (! isset($r['importado'])) {
            return $r['nome'];
        }
        $i = $r['importado'];
        $texto = "{$r['nome']}: {$i['linhas']} linhas, {$i['demonstrativos']} demonstrativos ({$i['de']} a {$i['ate']}), líquido R$ ".number_format($i['liquido'], 2, ',', '.');

        return $i['divergencias'] ? $texto.'. DIVERGÊNCIA: '.implode('; ', $i['divergencias']) : $texto;
    }

    public function avisar(bool $sucesso, string $detalhe): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        rescue(fn () => Notification::send($admins, new TikTokRelatorioNotification($sucesso, $detalhe)), report: false);
    }

    public function tipo(string $nome): ?string
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
