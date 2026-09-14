<?php

namespace App\Services\Logs;

use Generator;
use Illuminate\Support\Carbon;

/**
 * Leitor dos arquivos de storage/logs pra tela "Log" do admin.
 *
 * Por que não é um file()/Storage::get() com array_filter: em produção um
 * arquivo diário passa de 300 MB (laravel-2026-09-09.log tinha 320 MB em
 * 2026-09-14, a pasta inteira 865 MB). Carregar isso em memória derruba o
 * PHP do compartilhado. Então a leitura é REVERSA e em blocos: começa no
 * fim do arquivo — que é onde estão as linhas novas, as que interessam — e
 * para assim que junta a página pedida. Um filtro "de" também encerra o
 * arquivo cedo: como as entradas saem em ordem decrescente, a primeira
 * entrada mais velha que o "de" significa que o resto do arquivo é mais
 * velho ainda.
 *
 * Formato Monolog padrão do Laravel:
 *   [2026-09-14 09:44:47] production.WARNING: mensagem {"contexto":...}
 * Uma entrada pode ter várias linhas (stack trace) — só linha começando com
 * "[data]" abre entrada nova, o resto é continuação da anterior.
 *
 * Arquivo que não é Monolog (queue-cron.log, saída crua do
 * `schedule:run`/`queue:work`) cai no modo "linha a linha": cada linha vira
 * uma entrada e a data é procurada dentro da própria linha.
 */
class LogFileReader
{
    /** Níveis do Monolog, do mais fraco pro mais forte. */
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    /** Leitura reversa em blocos de 256 KB. */
    private const CHUNK_BYTES = 262144;

    /** Teto de varredura por requisição — evita segurar o PHP num arquivo de 300 MB. */
    private const MAX_SCAN_BYTES = 67108864;

    /** Uma entrada (stack trace incluso) nunca passa disso. */
    private const MAX_ENTRY_BYTES = 131072;

    /** Amostra lida do fim do arquivo pra decidir se ele é Monolog ou texto cru. */
    private const SAMPLE_BYTES = 8192;

    private int $scanned = 0;

    private bool $truncated = false;

    /**
     * Todos os .log de storage/logs, do mais recente pro mais antigo.
     *
     * @return array<int, array{name: string, path: string, channel: string, date: ?string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = [];

        foreach (glob(storage_path('logs/*.log')) ?: [] as $path) {
            if (! is_file($path) || ! is_readable($path)) {
                continue;
            }

            $name = basename($path);

            // laravel-2026-09-14.log -> canal "laravel", dia 2026-09-14.
            // queue-cron.log -> canal "queue-cron", sem dia.
            preg_match('/^(?<channel>.+?)(?:-(?<date>\d{4}-\d{2}-\d{2}))?\.log$/', $name, $matches);

            $files[] = [
                'name' => $name,
                'path' => $path,
                'channel' => $matches['channel'] ?? $name,
                'date' => ($matches['date'] ?? '') !== '' ? $matches['date'] : null,
                'size' => (int) filesize($path),
                'modified' => (int) filemtime($path),
            ];
        }

        usort($files, fn (array $a, array $b) => [$b['date'] ?? '', $b['modified']] <=> [$a['date'] ?? '', $a['modified']]);

        return $files;
    }

    /**
     * @param  array{arquivo?: ?string, canal?: ?string, niveis?: array<int, string>, de?: ?Carbon, ate?: ?Carbon, q?: ?string, pagina?: int, porPagina?: int}  $filters
     * @return array{entries: array<int, array<string, mixed>>, hasMore: bool, truncated: bool, scanned: int, filesScanned: int}
     */
    public function search(array $filters = []): array
    {
        $this->scanned = 0;
        $this->truncated = false;

        $perPage = max(10, min(200, (int) ($filters['porPagina'] ?? 50)));
        $page = max(1, (int) ($filters['pagina'] ?? 1));
        $needed = $page * $perPage + 1; // o +1 só existe pra saber se há próxima página

        $candidates = $this->candidateFiles($filters);

        /** @var array<int, Generator> $streams */
        $streams = [];
        foreach ($candidates as $meta) {
            $stream = $this->fileEntries($meta, $filters);
            if ($stream->valid()) {
                $streams[] = $stream;
            }
        }

        // Merge das N leituras reversas: cada arquivo já entrega em ordem
        // decrescente, aqui só se escolhe a entrada mais nova entre eles.
        // Sem isso, "todos os arquivos" devolveria um arquivo inteiro antes
        // de encostar no próximo, e a lista sairia fora de ordem.
        $collected = [];
        while (count($collected) < $needed && $streams !== []) {
            $bestKey = null;
            foreach ($streams as $key => $stream) {
                if ($bestKey === null || ($stream->current()['timestamp'] ?? 0) > ($streams[$bestKey]->current()['timestamp'] ?? 0)) {
                    $bestKey = $key;
                }
            }

            $collected[] = $streams[$bestKey]->current();
            $streams[$bestKey]->next();

            if (! $streams[$bestKey]->valid()) {
                unset($streams[$bestKey]);
            }
        }

        $slice = array_slice($collected, ($page - 1) * $perPage, $perPage);

        return [
            'entries' => array_values($slice),
            'hasMore' => count($collected) > $page * $perPage,
            'truncated' => $this->truncated,
            'scanned' => $this->scanned,
            'filesScanned' => count($candidates),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function candidateFiles(array $filters): array
    {
        $arquivo = $filters['arquivo'] ?? null;
        $canal = $filters['canal'] ?? null;
        $de = $filters['de'] ?? null;
        $ate = $filters['ate'] ?? null;

        return array_values(array_filter($this->files(), function (array $meta) use ($arquivo, $canal, $de, $ate) {
            if ($arquivo && $meta['name'] !== $arquivo) {
                return false;
            }

            if ($canal && $meta['channel'] !== $canal) {
                return false;
            }

            // Arquivo com o dia no nome fora da janela nem chega a ser aberto.
            if ($meta['date']) {
                if ($de && $meta['date'] < $de->toDateString()) {
                    return false;
                }

                if ($ate && $meta['date'] > $ate->toDateString()) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Entradas de um arquivo, da mais nova pra mais velha, já filtradas.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $filters
     * @return Generator<int, array<string, mixed>>
     */
    private function fileEntries(array $meta, array $filters): Generator
    {
        $handle = @fopen($meta['path'], 'rb');

        if (! $handle) {
            return;
        }

        try {
            $size = (int) $meta['size'];
            $plain = ! $this->looksLikeMonolog($handle, $size);
            $pos = $size;
            $pending = '';

            while ($pos > 0) {
                if ($this->scanned >= self::MAX_SCAN_BYTES) {
                    $this->truncated = true;

                    return;
                }

                $read = (int) min(self::CHUNK_BYTES, $pos);
                $pos -= $read;
                fseek($handle, $pos);
                $buffer = (string) fread($handle, $read).$pending;
                $this->scanned += $read;

                [$raws, $pending] = $this->splitBuffer($buffer, $pos === 0, $plain);

                foreach ($raws as $raw) {
                    // Só o cabeçalho é lido de cada entrada (data e nível).
                    // Montar a entrada inteira antes de filtrar custava caro
                    // demais: "só warning" varria 27 MB e levava 7s em
                    // produção porque formatava 100 mil entradas pra
                    // descartar 99.990 delas.
                    $header = $this->header($raw);

                    // A lista vem decrescente: passou do "de", o resto do
                    // arquivo é mais velho ainda e não precisa ser lido.
                    if (isset($filters['de']) && $filters['de'] && $header['timestamp'] !== null
                        && $header['timestamp'] < $filters['de']->getTimestamp()) {
                        return;
                    }

                    if ($this->matches($header, $raw, $filters)) {
                        yield $this->parse($raw, $meta, $header);
                    }
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Quebra o bloco lido em entradas cruas, da mais nova pra mais velha.
     *
     * O que sobra na frente do bloco (`pending`) é o começo de uma entrada
     * que nasce antes dele — volta pro próximo ciclo, que lê mais atrás.
     *
     * @return array{0: array<int, string>, 1: string}
     */
    private function splitBuffer(string $buffer, bool $atFileStart, bool $plain): array
    {
        if ($plain) {
            $lines = explode("\n", $buffer);
            $pending = $atFileStart ? '' : (string) array_shift($lines);
            $lines = array_values(array_filter($lines, fn (string $line) => trim($line) !== ''));

            return [array_reverse($lines), $pending];
        }

        preg_match_all(
            '/(?:^|\n)\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/',
            $buffer,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        $starts = [];
        foreach ($matches[0] as [$text, $offset]) {
            // Casamento em offset 0 sem o \n só vale no começo do arquivo:
            // no meio, o byte anterior está no bloco seguinte e a decisão
            // fica pro próximo ciclo (o trecho segue em `pending`).
            if ($offset === 0 && $text[0] !== "\n" && ! $atFileStart) {
                continue;
            }

            $starts[] = $text[0] === "\n" ? $offset + 1 : $offset;
        }

        if ($starts === []) {
            // Bloco inteiro é continuação. Se crescer demais (stack trace
            // gigante, arquivo sem cabeçalho nenhum), corta aqui pra não
            // segurar o arquivo todo em memória.
            if (strlen($buffer) > self::MAX_ENTRY_BYTES) {
                return [[$buffer], ''];
            }

            return [[], $buffer];
        }

        $entries = [];
        $count = count($starts);
        foreach ($starts as $index => $start) {
            $end = $index + 1 < $count ? $starts[$index + 1] : strlen($buffer);
            $entries[] = substr($buffer, $start, $end - $start);
        }

        return [array_reverse($entries), substr($buffer, 0, $starts[0])];
    }

    /**
     * Lê o fim do arquivo pra saber se ele segue o formato do Monolog.
     */
    private function looksLikeMonolog($handle, int $size): bool
    {
        $read = (int) min(self::SAMPLE_BYTES, $size);

        if ($read <= 0) {
            return true;
        }

        fseek($handle, $size - $read);
        $sample = (string) fread($handle, $read);

        return preg_match('/(?:^|\n)\[\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $sample) === 1;
    }

    /**
     * Data, nível e tamanho do prefixo — lendo só o começo da entrada.
     *
     * @return array{timestamp: ?int, nivel: ?string, prefixo: int}
     */
    private function header(string $raw): array
    {
        $head = substr($raw, 0, 96);

        if (preg_match('/^\[(?<ts>[^\]]{8,40})\][ \t]*(?:(?<env>[^\s:\[\]]+)\.(?<level>[A-Z]+):)?[ \t]*/', $head, $matches)) {
            $level = ($matches['level'] ?? '') !== '' ? strtolower($matches['level']) : null;

            return [
                'timestamp' => $this->toTimestamp($matches['ts']),
                'nivel' => $level ?? $this->guessLevel($raw),
                'prefixo' => strlen($matches[0]),
            ];
        }

        // Linha crua (queue-cron e afins): a data está dentro da linha.
        $timestamp = preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $head, $matches)
            ? $this->toTimestamp($matches[0])
            : null;

        return ['timestamp' => $timestamp, 'nivel' => $this->guessLevel($raw), 'prefixo' => 0];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array{timestamp: ?int, nivel: ?string, prefixo: int}  $header
     * @return array<string, mixed>
     */
    private function parse(string $raw, array $meta, array $header): array
    {
        $raw = rtrim($raw, "\r\n");
        $cortada = strlen($raw) > self::MAX_ENTRY_BYTES;

        if ($cortada) {
            $raw = substr($raw, 0, self::MAX_ENTRY_BYTES);
        }

        $level = $header['nivel'];
        $timestamp = $header['timestamp'];
        $rest = $header['prefixo'] > 0 ? substr($raw, $header['prefixo']) : $raw;

        $lines = explode("\n", $rest);
        $mensagem = trim((string) array_shift($lines));
        $detalhe = trim(implode("\n", $lines));

        return [
            'arquivo' => $meta['name'],
            'canal' => $meta['channel'],
            'nivel' => $level,
            'timestamp' => $timestamp,
            'dataHora' => $timestamp ? Carbon::createFromTimestamp($timestamp, config('app.timezone'))->format('d/m/Y H:i:s') : null,
            'mensagem' => $this->clean($mensagem === '' ? '(sem mensagem)' : $mensagem, 400, '…'),
            'detalhe' => $this->clean($detalhe, 8000, "\n… (corte de exibição — o arquivo tem o texto completo)"),
            'cortada' => $cortada,
        ];
    }

    /**
     * Log tem byte inválido (payload de marketplace cortado no meio de um
     * caractere, por exemplo) e um único deles faz o json_encode do Inertia
     * falhar — a tela inteira viraria erro 500 por causa de uma linha.
     */
    private function clean(string $value, int $limit, string $marker): string
    {
        $value = (string) mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        return mb_strimwidth($value, 0, $limit, $marker);
    }

    /**
     * strtotime em vez de Carbon::parse de propósito: isto roda uma vez por
     * entrada lida (dezenas de milhares numa varredura) e o Carbon custa
     * ordens de grandeza mais. O fuso é o mesmo — o Laravel já aplica
     * config('app.timezone') no date_default_timezone_set do boot.
     */
    private function toTimestamp(string $value): ?int
    {
        $timestamp = strtotime(trim($value));

        return $timestamp === false ? null : $timestamp;
    }

    /**
     * Arquivo fora do formato Monolog não traz nível — dá pra inferir o que
     * interessa (falha) pela própria linha, em vez de mostrar tudo como "—".
     */
    private function guessLevel(string $raw): ?string
    {
        $raw = substr($raw, 0, 2000);

        if (preg_match('/\b(ERROR|FAIL|FAILED|EXCEPTION|FATAL)\b/i', $raw)) {
            return 'error';
        }

        if (preg_match('/\b(WARN|WARNING)\b/i', $raw)) {
            return 'warning';
        }

        return null;
    }

    /**
     * @param  array{timestamp: ?int, nivel: ?string, prefixo: int}  $header
     * @param  array<string, mixed>  $filters
     */
    private function matches(array $header, string $raw, array $filters): bool
    {
        $niveis = $filters['niveis'] ?? [];

        if ($niveis !== [] && ! in_array($header['nivel'], $niveis, true)) {
            return false;
        }

        $de = $filters['de'] ?? null;
        $ate = $filters['ate'] ?? null;

        if (($de || $ate) && $header['timestamp'] === null) {
            return false;
        }

        if ($de && $header['timestamp'] < $de->getTimestamp()) {
            return false;
        }

        if ($ate && $header['timestamp'] > $ate->getTimestamp()) {
            return false;
        }

        $q = trim((string) ($filters['q'] ?? ''));

        if ($q !== '' && stripos($raw, $q) === false) {
            return false;
        }

        return true;
    }
}
