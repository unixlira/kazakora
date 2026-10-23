<?php

namespace App\Modules\Checkout\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * CAPTCHA do rastreio (pedido 2026-10-22): depois de 3 buscas com erro do
 * mesmo IP na última hora, a busca só passa com o código da imagem. Contra
 * robô que fica chutando número de pedido.
 *
 * Imagem feita aqui mesmo com o GD (sem serviço de fora, sem chave). O
 * código fica na sessão, vale 10 minutos e serve para uma tentativa só:
 * errou, gera outro.
 */
class CaptchaRastreio
{
    public const FALHAS_PARA_PEDIR = 3;

    private const JANELA_SEGUNDOS = 3600;

    private const VALIDADE_SEGUNDOS = 600;

    private const SESSAO = 'rastreio_captcha';

    // Sem 0/O, 1/I/L, 5/S, 2/Z, 8/B: confundem na imagem.
    private const LETRAS = 'ACDEFGHJKMNPQRTUVWXY34679';

    public static function exigido(Request $request): bool
    {
        return RateLimiter::attempts(self::chave($request)) >= self::FALHAS_PARA_PEDIR;
    }

    public static function registrarFalha(Request $request): void
    {
        RateLimiter::hit(self::chave($request), self::JANELA_SEGUNDOS);
    }

    public static function limparFalhas(Request $request): void
    {
        RateLimiter::clear(self::chave($request));
    }

    /** Confere o digitado e apaga o código: cada imagem vale uma tentativa. */
    public static function confere(Request $request, ?string $digitado): bool
    {
        $salvo = $request->session()->pull(self::SESSAO);
        if (! is_array($salvo) || ($salvo['expira'] ?? 0) < time()) {
            return false;
        }

        $digitado = strtoupper(preg_replace('/\s+/', '', (string) $digitado));

        return $digitado !== '' && hash_equals($salvo['codigo'], $digitado);
    }

    /** Gera um código novo, guarda na sessão e devolve o PNG. */
    public static function imagem(Request $request): string
    {
        $codigo = '';
        for ($i = 0; $i < 5; $i++) {
            $codigo .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
        }
        $request->session()->put(self::SESSAO, ['codigo' => $codigo, 'expira' => time() + self::VALIDADE_SEGUNDOS]);

        return self::desenhar($codigo);
    }

    private static function chave(Request $request): string
    {
        return 'rastreio-falhas:'.$request->ip();
    }

    private static function desenhar(string $codigo): string
    {
        $largura = 200;
        $altura = 64;
        $img = imagecreatetruecolor($largura, $altura);
        imagefill($img, 0, 0, imagecolorallocate($img, 246, 244, 240));

        // Fundo com riscos e pontos para atrapalhar leitura automática.
        for ($i = 0; $i < 8; $i++) {
            $cor = imagecolorallocate($img, random_int(150, 210), random_int(150, 210), random_int(150, 210));
            imageline($img, random_int(0, $largura), random_int(0, $altura), random_int(0, $largura), random_int(0, $altura), $cor);
        }
        for ($i = 0; $i < 400; $i++) {
            $cor = imagecolorallocate($img, random_int(120, 220), random_int(120, 220), random_int(120, 220));
            imagesetpixel($img, random_int(0, $largura - 1), random_int(0, $altura - 1), $cor);
        }

        // Cada letra na fonte embutida do GD, ampliada e girada (não depende de TTF).
        $passo = intdiv($largura - 20, strlen($codigo));
        foreach (str_split($codigo) as $i => $letra) {
            // Máscara: letra branca no preto, girada; depois pinta ampliado
            // só onde a máscara é clara (o GD perde a transparência ao girar).
            $mascara = imagecreatetruecolor(13, 17);
            imagestring($mascara, 5, 2, 1, $letra, imagecolorallocate($mascara, 255, 255, 255));
            $girada = imagerotate($mascara, random_int(-18, 18), 0);
            $sw = imagesx($girada);
            $sh = imagesy($girada);

            $w = random_int(34, 40);
            $h = random_int(44, 52);
            $x0 = 8 + $i * $passo + random_int(-2, 3);
            $y0 = random_int(0, max(0, $altura - $h));
            $cor = imagecolorallocate($img, random_int(20, 90), random_int(20, 70), random_int(60, 120));
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    if ((imagecolorat($girada, intdiv($x * $sw, $w), intdiv($y * $sh, $h)) >> 16 & 0xFF) > 127) {
                        imagesetpixel($img, $x0 + $x, $y0 + $y, $cor);
                    }
                }
            }
            imagedestroy($mascara);
            imagedestroy($girada);
        }

        // Um risco fino por cima das letras.
        imageline($img, 0, random_int(15, $altura - 15), $largura, random_int(15, $altura - 15), imagecolorallocate($img, 90, 70, 120));

        ob_start();
        imagepng($img);
        imagedestroy($img);

        return (string) ob_get_clean();
    }
}
