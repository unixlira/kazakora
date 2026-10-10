@php
    // Layout de todos os e-mails da loja (pedido 2026-10-10): logo no topo e,
    // no rodapé, os selos, o CNPJ e o WhatsApp de contato.
    $empresa = config('loja.empresa');
    $logoTopo = \App\Support\Marca::urlAbsoluta('logo_nav');
    $logoRodape = \App\Support\Marca::urlAbsoluta('logo_rodape');
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
            .selo { display: block !important; padding: 3px 0 !important; }
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
                        <td style="background-color: #0FB930; padding: 12px 20px; text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 11px; font-weight: 700; letter-spacing: 0.03em; color: #ffffff; text-transform: uppercase;">
                            <span class="selo" style="white-space: nowrap;">&#128274; Compra Segura</span><span class="selo-ponto" style="opacity: 0.7;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span><span class="selo" style="white-space: nowrap;">&#128737;&#65039; Dados Protegidos</span><span class="selo-ponto" style="opacity: 0.7;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span><span class="selo" style="white-space: nowrap;">&#128230; Entrega Garantida</span><span class="selo-ponto" style="opacity: 0.7;">&nbsp;&nbsp;&middot;&nbsp;&nbsp;</span><span class="selo" style="white-space: nowrap; font-style: italic;">&#9889;Full</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #0b0b0b; padding: 26px 32px; text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 1.7; color: #b8bcc4;">
                            <img src="{{ $logoRodape }}" alt="KazaKora" width="150" style="display: inline-block; width: 150px; height: auto; margin-bottom: 14px;">
                            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin: 0 auto 14px;">
                                <tr>
                                    <td style="border-radius: 999px; background-color: #25D366;">
                                        <a href="{{ $empresa['whatsapp_link'] }}" target="_blank" style="display: inline-block; padding: 9px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 13px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 999px;">
                                            &#128172; Fale conosco no WhatsApp {{ $empresa['whatsapp_exibicao'] }}
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            {{-- Gmail/iPhone viram número e endereço em link sozinhos (pedido
                                 2026-10-10: texto branco, sem link). O caractere invisível
                                 (&#8203;) depois de cada separador quebra essa detecção. --}}
                            <span class="sem-link" style="color: #ffffff; text-decoration: none;">{{ $empresa['nome'] }} &middot; CNPJ {!! $semLink($empresa['cnpj']) !!}</span><br>
                            <span class="sem-link" style="color: #ffffff; text-decoration: none;">{!! $semLink($empresa['endereco']) !!}</span><br>
                            <a href="{{ url('/') }}" target="_blank" style="color: #f27a2a; text-decoration: none;">{{ preg_replace('#^https?://#', '', rtrim(url('/'), '/')) }}</a>
                            <div style="margin-top: 12px; font-size: 11px; color: #7d828c;">
                                Este é um e-mail automático, não é necessário responder.
                                @hasSection('footer-extra')
                                    <br>@yield('footer-extra')
                                @endif
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
