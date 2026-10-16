<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Financeiro\Support\AlertaColetaFinanceira;
use App\Notifications\AmazonRelatorioNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Relatórios de pagamento da Amazon para a auditoria (2026-10-12): chegam
 * pela API (agente do PC da operação). O mesmo arquivo (sha256) não é
 * guardado duas vezes. CSV/TXT/TSV são importados na hora; o resto só fica
 * guardado.
 */
class RelatoriosAmazon
{
    public const EXTENSOES = ['csv', 'txt', 'tsv', 'xlsx', 'zip', 'json', 'pdf', 'bin'];

    public const IMPORTAVEIS = ['csv', 'txt', 'tsv'];

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
        $existente = DB::table('amazon_report_uploads')->where('sha256', $sha)->first();

        if ($existente) {
            return ['id' => $existente->id, 'nome' => $existente->nome_original, 'sha256' => $sha, 'duplicado' => true];
        }

        $caminho = 'amazon-reports/'.now()->format('Y-m-d').'/'.substr($sha, 0, 12).'-'.Str::slug(pathinfo($nome, PATHINFO_FILENAME)).($extensao ? ".{$extensao}" : '');
        Storage::disk('local')->put($caminho, $conteudo);

        $id = DB::table('amazon_report_uploads')->insertGetId([
            'nome_original' => $nome, 'caminho' => $caminho, 'sha256' => $sha, 'tamanho' => strlen($conteudo),
            'mime' => $mime ? Str::limit($mime, 120, '') : null, 'tipo' => null, 'ip' => $ip,
            'status' => 'recebido', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['id' => $id, 'nome' => $nome, 'sha256' => $sha, 'duplicado' => false] + $this->processar($id);
    }

    /** @return array{importado?: array<string, mixed>, erro_importacao?: string} */
    public function processar(int $uploadId): array
    {
        $upload = DB::table('amazon_report_uploads')->find($uploadId);
        if (! $upload || ! in_array(strtolower(pathinfo($upload->nome_original, PATHINFO_EXTENSION)), self::IMPORTAVEIS, true)) {
            return [];
        }

        try {
            $resumo = app(ImportadorRelatorioAmazon::class)->importar(Storage::disk('local')->path($upload->caminho), $upload->nome_original);
            DB::table('amazon_report_uploads')->where('id', $uploadId)->update(['status' => 'importado', 'tipo' => $resumo['formato'], 'updated_at' => now()]);

            return ['importado' => $resumo];
        } catch (\Throwable $e) {
            DB::table('amazon_report_uploads')->where('id', $uploadId)->update(['status' => 'erro', 'updated_at' => now()]);

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
        $texto = "{$r['nome']}: {$i['linhas']} linhas, {$i['pedidos']} pedidos ({$i['de']} a {$i['ate']}), líquido R$ ".number_format($i['liquido'], 2, ',', '.').", {$i['taxas_reais']} com taxa real";

        return $i['divergencias'] ? $texto.'. DIVERGÊNCIA: '.implode('; ', $i['divergencias']) : $texto;
    }

    public function avisar(bool $sucesso, string $detalhe): void
    {
        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();
        rescue(fn () => Notification::send($admins, new AmazonRelatorioNotification($sucesso, $detalhe)), report: false);

        if (! $sucesso) {
            rescue(fn () => app(AlertaColetaFinanceira::class)->avisar('Amazon', $detalhe), report: false);
        }
    }
}
