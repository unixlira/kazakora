{{-- Alerta do admin por e-mail (AlertaPorEmail, 25/11/2026): logo, título colorido pelo tipo, texto e botão. --}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $titulo }}</title>
    <style>
        body { margin: 0; padding: 0; background-color: #f2f5f4; }
        @media screen and (max-width: 600px) { .cx { width: 100% !important; } .pd { padding-left: 20px !important; padding-right: 20px !important; } }
    </style>
</head>
<body style="margin:0;padding:0;background-color:#f2f5f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f5f4;">
    <tr><td align="center" style="padding:28px 12px;">
        <table role="presentation" class="cx" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background-color:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 10px rgba(20,32,46,.08);">
            <tr><td align="center" style="padding:22px 24px;background-color:#ffffff;border-bottom:4px solid #f27a2a;">
                <img src="{{ $marca['logo'] }}" alt="KazaKora" width="170" style="display:block;width:170px;max-width:60%;height:auto;">
            </td></tr>
            <tr><td class="pd" style="padding:26px 32px 8px;font-family:Arial,Helvetica,sans-serif;">
                <span style="display:inline-block;padding:4px 10px;border-radius:999px;background-color:{{ $fundo }};color:{{ $cor }};font-size:12px;font-weight:bold;letter-spacing:.3px;text-transform:uppercase;">{{ $tipoNome }}</span>
                <h1 style="margin:14px 0 0;font-size:22px;line-height:1.3;color:{{ $cor }};">{{ $icone }} {{ $titulo }}</h1>
            </td></tr>
            <tr><td class="pd" style="padding:14px 32px 6px;font-family:Arial,Helvetica,sans-serif;">
                <div style="background-color:{{ $fundo }};border-left:4px solid {{ $cor }};border-radius:10px;padding:16px 18px;font-size:15px;line-height:1.6;color:#14202e;">
                    {!! nl2br(e($mensagem)) !!}
                </div>
            </td></tr>
            @if ($link)
            <tr><td align="center" class="pd" style="padding:22px 32px 8px;">
                <a href="{{ $link }}" style="display:inline-block;background-color:#f27a2a;color:#ffffff;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;padding:13px 26px;border-radius:999px;">Abrir no Gerenciador</a>
            </td></tr>
            @endif
            <tr><td class="pd" style="padding:14px 32px 26px;font-family:Arial,Helvetica,sans-serif;font-size:13px;color:#6b7280;">
                O mesmo aviso está no sino do painel, em Sistema › Alertas. Marque como resolvido depois de agir.
            </td></tr>
            <tr><td style="background-color:#f8f6f3;padding:16px 32px;text-align:center;font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#8089a0;">
                KazaKora · aviso automático do painel
            </td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
