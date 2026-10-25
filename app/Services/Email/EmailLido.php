<?php

namespace App\Services\Email;

use Illuminate\Support\Carbon;

/**
 * E-mail cru (RFC 822) aberto: cabeçalhos decodificados e o texto do corpo
 * (text/plain; sem ele, o text/html sem as tags). Cobre multipart, base64,
 * quoted-printable e charset; anexos são ignorados.
 */
class EmailLido
{
    /** @param array<string, string> $cabecalhos */
    private function __construct(public readonly array $cabecalhos, public readonly string $texto) {}

    public static function de(string $cru): self
    {
        [$cab, $corpo] = self::separar($cru);
        $partes = self::texto($cab, $corpo);

        return new self($cab, $partes['plain'] ?? (isset($partes['html']) ? self::semHtml($partes['html']) : ''));
    }

    public function cabecalho(string $nome): ?string
    {
        return $this->cabecalhos[strtolower($nome)] ?? null;
    }

    public function assunto(): string
    {
        return trim((string) $this->cabecalho('subject'));
    }

    public function messageId(): ?string
    {
        $id = trim((string) $this->cabecalho('message-id'));

        return $id !== '' ? trim($id, '<> ') : null;
    }

    public function data(): ?Carbon
    {
        $d = $this->cabecalho('date');
        try {
            return $d ? Carbon::parse(preg_replace('/\s*\([^)]*\)\s*$/', '', $d))->setTimezone(config('app.timezone')) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{nome: ?string, email: ?string} */
    public function remetente(string $cabecalho = 'from'): array
    {
        $v = trim((string) $this->cabecalho($cabecalho));
        if (preg_match('/^(.*)<([^>]+)>\s*$/', $v, $m)) {
            return ['nome' => trim($m[1], " \"'") ?: null, 'email' => strtolower(trim($m[2]))];
        }

        return ['nome' => null, 'email' => $v !== '' ? strtolower($v) : null];
    }

    /** @return array{0: array<string, string>, 1: string} */
    private static function separar(string $cru): array
    {
        $cru = str_replace("\r\n", "\n", $cru);
        $pos = strpos($cru, "\n\n");
        $bruto = $pos === false ? $cru : substr($cru, 0, $pos);
        $corpo = $pos === false ? '' : substr($cru, $pos + 2);

        $cab = [];
        $ultimo = null;
        foreach (explode("\n", $bruto) as $l) {
            if ($ultimo !== null && ($l !== '' && ($l[0] === ' ' || $l[0] === "\t"))) {
                $cab[$ultimo] .= ' '.trim($l);

                continue;
            }
            if (str_contains($l, ':')) {
                [$k, $v] = explode(':', $l, 2);
                $ultimo = strtolower(trim($k));
                // Mantém o primeiro (Received e afins repetem; os que importam não).
                $cab[$ultimo] ??= trim($v);
            }
        }
        foreach ($cab as $k => $v) {
            $cab[$k] = self::decodificarCabecalho($v);
        }

        return [$cab, $corpo];
    }

    private static function decodificarCabecalho(string $v): string
    {
        if (! str_contains($v, '=?')) {
            return $v;
        }
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $d !== false ? $d : mb_decode_mimeheader($v);
    }

    /**
     * @param  array<string, string>  $cab
     * @return array{plain?: string, html?: string}
     */
    private static function texto(array $cab, string $corpo, int $nivel = 0): array
    {
        $tipo = strtolower($cab['content-type'] ?? 'text/plain');

        if (str_starts_with($tipo, 'multipart/') && $nivel < 5 && preg_match('/boundary="?([^";]+)"?/i', $cab['content-type'], $m)) {
            $achado = [];
            foreach (explode('--'.$m[1], $corpo) as $parte) {
                if (trim($parte) === '' || str_starts_with($parte, '--')) {
                    continue;
                }
                [$c, $b] = self::separar(ltrim($parte, "\r\n"));
                if (str_contains(strtolower($c['content-disposition'] ?? ''), 'attachment')) {
                    continue;
                }
                $achado += self::texto($c, $b, $nivel + 1);
            }

            return $achado;
        }

        if (! str_starts_with($tipo, 'text/')) {
            return [];
        }

        $codificacao = strtolower(trim($cab['content-transfer-encoding'] ?? ''));
        $corpo = match ($codificacao) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $corpo)),
            'quoted-printable' => quoted_printable_decode($corpo),
            default => $corpo,
        };

        $charset = preg_match('/charset="?([^";\s]+)"?/i', $tipo, $m) ? strtoupper($m[1]) : 'UTF-8';
        if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
            $conv = @mb_convert_encoding($corpo, 'UTF-8', $charset);
            $corpo = $conv !== false ? $conv : $corpo;
        }

        return [str_starts_with($tipo, 'text/html') ? 'html' : 'plain' => $corpo];
    }

    private static function semHtml(string $html): string
    {
        $html = preg_replace('/<(br|\/p|\/div|\/tr|\/li)[^>]*>/i', "\n", $html);
        $texto = html_entity_decode(strip_tags(preg_replace('/<(style|script)\b.*?<\/\1>/is', '', $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $texto)));
    }
}
