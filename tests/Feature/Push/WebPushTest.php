<?php

namespace Tests\Feature\Push;

use App\Modules\Notifications\Push\WebPush;
use Tests\TestCase;

/** Criptografia e VAPID do Web Push conferidos com as RFCs (2026-11-19). */
class WebPushTest extends TestCase
{
    public function test_criptografia_bate_com_o_exemplo_da_rfc_8291(): void
    {
        $b = fn (string $v) => WebPush::base64urlDecode($v);

        $corpo = (new WebPush)->criptografar(
            'When I grow up, I want to be a watermelon',
            $b('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4'),
            $b('BTBZMqHH6r4Tts7J_aSIgg'),
            [
                'privada' => $b('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw'),
                'publica' => $b('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8'),
            ],
            $b('DGv6ra1nlYgDCS1FRnbzlw'),
        );

        $this->assertSame(
            'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN',
            WebPush::base64urlEncode($corpo),
        );
    }

    public function test_jwt_vapid_assinado_com_a_chave_e_aud_da_origem_do_endpoint(): void
    {
        $chaves = WebPush::gerarChaves();
        $push = new WebPush($chaves['publica'], $chaves['privada'], 'mailto:korashopecom@gmail.com');

        $jwt = $push->jwt('https://web.push.apple.com/QGuQyavXutnMcGP5vKkp5g', 1_800_000_000);
        [$cabecalho, $dados, $assinatura] = explode('.', $jwt);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::base64urlDecode($cabecalho), true));
        $this->assertSame(
            ['aud' => 'https://web.push.apple.com', 'exp' => 1_800_000_000 + 43200, 'sub' => 'mailto:korashopecom@gmail.com'],
            json_decode(WebPush::base64urlDecode($dados), true),
        );

        // r||s de volta para DER e conferido com a chave pública do .env.
        $rs = WebPush::base64urlDecode($assinatura);
        $this->assertSame(64, strlen($rs));
        $inteiro = function (string $v): string {
            $v = ltrim($v, "\0");
            if ($v === '' || ord($v[0]) & 0x80) {
                $v = "\0".$v;
            }

            return "\x02".chr(strlen($v)).$v;
        };
        $seq = $inteiro(substr($rs, 0, 32)).$inteiro(substr($rs, 32));
        $der = "\x30".chr(strlen($seq)).$seq;

        $spki = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00".WebPush::base64urlDecode($chaves['publica']);
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";

        $this->assertSame(1, openssl_verify($cabecalho.'.'.$dados, $der, $pem, OPENSSL_ALGO_SHA256));
    }

    public function test_chaves_geradas_tem_o_tamanho_certo(): void
    {
        $chaves = WebPush::gerarChaves();

        $this->assertSame(65, strlen(WebPush::base64urlDecode($chaves['publica'])));
        $this->assertSame(32, strlen(WebPush::base64urlDecode($chaves['privada'])));
    }
}
