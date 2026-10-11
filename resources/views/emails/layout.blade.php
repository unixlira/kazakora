@php
    // Layout de todos os e-mails da loja (pedido 2026-10-10): logo no topo e,
    // no rodapé, os selos, o CNPJ e o WhatsApp de contato.
    $empresa = config('loja.empresa');
    $logoTopo = \App\Support\Marca::urlAbsoluta('logo_nav');
    $semLink = fn (string $texto) => preg_replace('#([./\-, ])#u', '$1&#8203;', e($texto));
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>@yield('title', 'KazaKora')</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; background-color: #f2f5f4; }

        .sem-link a, a[x-apple-data-detectors], u + #corpo .sem-link a { color: #ffffff !important; text-decoration: none !important; font: inherit !important; pointer-events: none; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; }
            .email-padding { padding-left: 20px !important; padding-right: 20px !important; }
            .selos-par, .quebra-celular { display: block !important; }
            .whats-icone { width: 30px !important; height: 30px !important; }
            .so-computador { display: none !important; }
            .so-celular { display: inline !important; }
            .selo-ponto { display: none !important; }
        }
    </style>
</head>
<body id="corpo" style="margin: 0; padding: 0; background-color: #f2f5f4;">
    @hasSection('preheader')
        <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">@yield('preheader')</div>
    @endif
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f2f5f4;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 10px rgba(16, 24, 40, 0.06);">
                    <tr>
                        <td style="background-color: #ffffff; padding: 26px 32px 22px; text-align: center; border-bottom: 4px solid #f27a2a;">
                            <a href="{{ url('/') }}" target="_blank" style="text-decoration: none;">
                                <img src="{{ $logoTopo }}" alt="KazaKora" width="190" style="display: inline-block; width: 190px; max-width: 70%; height: auto;">
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 32px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6; color: #14202e;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        {{-- Selos: uma linha no computador; no celular, 2 de cada lado (2 linhas). --}}
                        <td style="background-color: #0FB930; padding: 9px 12px; text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 11px; line-height: 1.5; font-weight: 700; letter-spacing: 0.02em; color: #ffffff; text-transform: uppercase;">
                            <span class="selos-par" style="white-space: nowrap;">&#128274; Compra Segura&nbsp;&nbsp;&middot;&nbsp;&nbsp;&#128737;&#65039; Dados Protegidos</span><span class="selo-ponto" style="opacity: 0.7;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span><span class="selos-par" style="white-space: nowrap;">&#128230; Entrega Garantida&nbsp;&nbsp;&middot;&nbsp;&nbsp;<em>&#9889;Full</em></span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #0b0b0b; padding: 20px 24px; text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.7; color: #ffffff;">
                            {{-- Ícone + frase curta apontando pra ele: o cliente entende que é
                                 pra clicar. No celular o ícone diminui e a frase encurta. --}}
                            <a href="{{ $empresa['whatsapp_link'] }}" target="_blank" style="text-decoration: none; color: #ffffff; font-size: 14px; font-weight: 700; white-space: nowrap;">
                                <img class="whats-icone" src="{{ url('/images/marca/whatsapp-email.png') }}" alt="WhatsApp" width="40" height="40" style="display: inline-block; width: 40px; height: 40px; vertical-align: middle; border: 0;"><span class="so-computador" style="vertical-align: middle;">&nbsp;&#128072; Dúvidas? Clique e fale conosco</span><span class="so-celular" style="display: none; mso-hide: all; vertical-align: middle; font-size: 13px;">&nbsp;&#128072; Dúvidas? Toque aqui</span>
                            </a>
                            {{-- Gmail/iPhone viram número em link sozinho (pedido 2026-10-10:
                                 texto branco, sem link). O caractere invisível (&#8203;)
                                 depois de cada separador quebra essa detecção. --}}
                            <div class="sem-link" style="margin-top: 12px; color: #ffffff; text-decoration: none;">
                                {{ $empresa['nome'] }}<span class="selo-ponto"> &middot; </span><span class="quebra-celular">CNPJ {!! $semLink($empresa['cnpj']) !!}</span>
                            </div>
                            @hasSection('footer-extra')
                                <div style="margin-top: 8px; font-size: 11px; color: #9ca3af;">@yield('footer-extra')</div>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
