<?php

namespace App\Modules\Notifications\Push;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Web Push com VAPID (apps de vendas KazaKora e FerraKora, 2026-11-19).
 *
 * Feito aqui, só com OpenSSL, em vez do minishlink/web-push: a biblioteca
 * puxa web-token/jwt-library e brick/math (e quer GMP para ficar rápida),
 * e nem o homolog (Hostinger) nem o PC do Lira têm como conferir isso antes
 * do deploy. O que o protocolo pede é pouco e está nas RFCs:
 *
 * - RFC 8291 (criptografia da mensagem): ECDH P-256 + HKDF + AES-128-GCM;
 *   o teste WebPushTest confere byte a byte com o exemplo do Apêndice A.
 * - RFC 8188 (formato aes128gcm): cabeçalho salt|rs|idlen|chave pública.
 * - RFC 8292 (VAPID): JWT ES256 com aud = origem do endpoint.
 *
 * Chaves no .env: VAPID_PUBLIC_KEY (ponto não comprimido, 65 bytes) e
 * VAPID_PRIVATE_KEY (32 bytes), as duas em base64url, mais VAPID_SUBJECT.
 */
class WebPush
{
    /** Uma mensagem por registro: o texto do aviso cabe com folga em 4 KB. */
    private const TAMANHO_DO_REGISTRO = 4096;

    public function __construct(
        private readonly ?string $chavePublica = null,
        private readonly ?string $chavePrivada = null,
        private readonly ?string $assunto = null,
    ) {
    }

    public static function daConfig(): self
    {
        return new self(
            config('services.webpush.public_key'),
            config('services.webpush.private_key'),
            config('services.webpush.subject'),
        );
    }

    public function configurado(): bool
    {
        return filled($this->chavePublica) && filled($this->chavePrivada) && filled($this->assunto);
    }

    public function chavePublica(): ?string
    {
        return $this->chavePublica;
    }

    /**
     * Manda uma mensagem para uma inscrição. Devolve o status HTTP do
     * serviço de push (201 = aceito; 404/410 = inscrição morta).
     *
     * @param  array{endpoint: string, p256dh: string, auth: string}  $inscricao
     */
    public function enviar(array $inscricao, string $mensagem, int $ttl = 86400, string $urgencia = 'high', ?string $topico = null): int
    {
        if (! $this->configurado()) {
            throw new RuntimeException('Web Push sem chaves VAPID no .env.');
        }

        $corpo = $this->criptografar($mensagem, self::base64urlDecode($inscricao['p256dh']), self::base64urlDecode($inscricao['auth']));

        $cabecalhos = [
            'Content-Type' => 'application/octet-stream',
            'Content-Encoding' => 'aes128gcm',
            'TTL' => (string) $ttl,
            'Urgency' => $urgencia,
            'Authorization' => 'vapid t='.$this->jwt($inscricao['endpoint']).', k='.$this->chavePublica,
        ];
        if ($topico !== null) {
            // Topic: o serviço troca uma mensagem ainda não entregue pela nova.
            $cabecalhos['Topic'] = substr(preg_replace('/[^A-Za-z0-9_-]/', '', $topico), 0, 32);
        }

        return Http::withHeaders($cabecalhos)
            ->withBody($corpo, 'application/octet-stream')
            ->timeout(10)
            ->post($inscricao['endpoint'])
            ->status();
    }

    /**
     * Corpo aes128gcm (RFC 8291). $chaveEfemera e $salt só existem para o
     * teste com o exemplo da RFC; em uso normal são sorteados a cada envio.
     *
     * @param  array{privada: string, publica: string}|null  $chaveEfemera  bytes crus
     */
    public function criptografar(string $mensagem, string $publicaDoNavegador, string $segredo, ?array $chaveEfemera = null, ?string $salt = null): string
    {
        if (strlen($publicaDoNavegador) !== 65 || $publicaDoNavegador[0] !== "\x04") {
            throw new RuntimeException('Chave p256dh inválida.');
        }
        if (strlen($segredo) !== 16) {
            throw new RuntimeException('Segredo auth inválido.');
        }

        if ($chaveEfemera === null) {
            $par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            $detalhes = openssl_pkey_get_details($par)['ec'];
            $chaveEfemera = [
                'privada' => str_pad($detalhes['d'], 32, "\0", STR_PAD_LEFT),
                'publica' => "\x04".str_pad($detalhes['x'], 32, "\0", STR_PAD_LEFT).str_pad($detalhes['y'], 32, "\0", STR_PAD_LEFT),
            ];
        }
        $salt ??= random_bytes(16);

        $privada = openssl_pkey_get_private(self::pemPrivada($chaveEfemera['privada'], $chaveEfemera['publica']));
        $publica = openssl_pkey_get_public(self::pemPublica($publicaDoNavegador));
        $segredoEcdh = openssl_pkey_derive($publica, $privada, 32);
        if ($segredoEcdh === false) {
            throw new RuntimeException('ECDH falhou: '.openssl_error_string());
        }

        $infoChave = "WebPush: info\0".$publicaDoNavegador.$chaveEfemera['publica'];
        $ikm = hash_hkdf('sha256', $segredoEcdh, 32, $infoChave, $segredo);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag = '';
        // 0x02 = delimitador do último (e único) registro.
        $cifrado = openssl_encrypt($mensagem."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', self::TAMANHO_DO_REGISTRO).chr(65).$chaveEfemera['publica'].$cifrado.$tag;
    }

    /** JWT ES256 do VAPID (RFC 8292), válido por 12 horas. */
    public function jwt(string $endpoint, ?int $agora = null): string
    {
        $partes = parse_url($endpoint);
        $aud = $partes['scheme'].'://'.$partes['host'].(isset($partes['port']) ? ':'.$partes['port'] : '');

        $cabecalho = self::base64urlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $dados = self::base64urlEncode(json_encode([
            'aud' => $aud,
            'exp' => ($agora ?? time()) + 12 * 3600,
            'sub' => $this->assunto,
        ], JSON_UNESCAPED_SLASHES));

        $chave = openssl_pkey_get_private(self::pemPrivada(self::base64urlDecode($this->chavePrivada), self::base64urlDecode($this->chavePublica)));
        if (! openssl_sign($cabecalho.'.'.$dados, $assinaturaDer, $chave, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Assinatura VAPID falhou: '.openssl_error_string());
        }

        return $cabecalho.'.'.$dados.'.'.self::base64urlEncode(self::derParaRs($assinaturaDer));
    }

    /**
     * Par de chaves VAPID novo, em base64url. Usado pelo comando
     * push:gerar-chaves-vapid.
     *
     * @return array{publica: string, privada: string}
     */
    public static function gerarChaves(): array
    {
        $par = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($par)['ec'];

        return [
            'publica' => self::base64urlEncode("\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)),
            'privada' => self::base64urlEncode(str_pad($ec['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    public static function base64urlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function base64urlDecode(string $texto): string
    {
        $texto = strtr(trim($texto), '-_', '+/');

        return (string) base64_decode($texto.str_repeat('=', (4 - strlen($texto) % 4) % 4), true);
    }

    /** ECPrivateKey (SEC 1) da curva P-256, a partir dos bytes crus. */
    private static function pemPrivada(string $d, string $publica): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".$d
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$publica;

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** SubjectPublicKeyInfo da curva P-256, a partir do ponto não comprimido. */
    private static function pemPublica(string $ponto): string
    {
        $der = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
            ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".$ponto;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    /** Assinatura ECDSA em DER (o que o OpenSSL devolve) -> r||s de 64 bytes (o que o JWT quer). */
    private static function derParaRs(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $partes = '';
        for ($i = 0; $i < 2; $i++) {
            $tamanho = ord($der[$pos + 1]);
            $valor = substr($der, $pos + 2, $tamanho);
            $pos += 2 + $tamanho;
            $partes .= str_pad(ltrim($valor, "\0"), 32, "\0", STR_PAD_LEFT);
        }

        return $partes;
    }
}
