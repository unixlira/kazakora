<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar nova senha - FerraKora</title>
</head>
<body style="margin: 0; padding: 24px 12px; background-color: #f4f4f5; font-family: Arial, Helvetica, sans-serif; color: #374151;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; background-color: #ffffff; border-radius: 12px;">
                    <tr>
                        <td style="padding: 28px 28px 8px; text-align: center; font-size: 24px; font-weight: bold; color: #111827;">
                            Ferra<span style="color: #f27a2a;">Kora</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 8px 28px 0; text-align: center; font-size: 15px; line-height: 1.5;">
                            Olá{{ $cliente->name ? ', '.$cliente->name : '' }}! Recebemos um pedido para criar uma nova senha da sua conta na FerraKora.
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding: 22px 28px;">
                            <a href="{{ $link }}" style="display: inline-block; padding: 12px 26px; background-color: #f27a2a; color: #ffffff; text-decoration: none; font-weight: bold; border-radius: 8px;">Criar nova senha</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 0 28px 8px; text-align: center; font-size: 13px; color: #6b7280;">
                            O link vale por {{ $minutos }} minutos. Se você não pediu, ignore este e-mail: sua senha continua a mesma.
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 12px 28px 28px; font-size: 12px; color: #9ca3af; word-break: break-all;">
                            Se o botão não funcionar, copie e cole no navegador:<br>
                            <a href="{{ $link }}" style="color: #f27a2a;">{{ $link }}</a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
