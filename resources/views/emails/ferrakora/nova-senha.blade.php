<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Criar nova senha - FerraKora</title>
</head>
<body style="margin: 0; padding: 24px 12px; background: #f3f4f6; font-family: Arial, Helvetica, sans-serif; color: #111827;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width: 520px; margin: 0 auto; background: #ffffff; border-radius: 12px; overflow: hidden;">
        <tr>
            <td style="background: #0b0b0b; padding: 18px 24px; text-align: center; font-size: 22px; font-weight: bold; color: #f27a2a;">FerraKora</td>
        </tr>
        <tr>
            <td style="padding: 28px 24px;">
                <h1 style="margin: 0 0 12px; font-size: 20px; text-align: center;">Criar nova senha</h1>
                <p style="margin: 0 0 20px; text-align: center; color: #374151; line-height: 1.5;">
                    Olá{{ $cliente->name ? ', '.$cliente->name : '' }}! Recebemos um pedido para criar uma nova senha da sua
                    conta no FerraKora. Clique no botão abaixo para escolher a senha.
                </p>
                <p style="margin: 0 0 20px; text-align: center;">
                    <a href="{{ $link }}" style="display: inline-block; background: #f27a2a; color: #0b0b0b; font-weight: bold; text-decoration: none; padding: 12px 28px; border-radius: 8px;">Criar nova senha</a>
                </p>
                <p style="margin: 0 0 8px; text-align: center; color: #6b7280; font-size: 13px; line-height: 1.5;">
                    Este link vale por {{ $minutos }} minutos. Se você não pediu, pode ignorar este e-mail: sua senha continua a mesma.
                </p>
                <p style="margin: 16px 0 0; word-break: break-all; font-size: 12px; color: #9ca3af;">
                    Se o botão não funcionar, copie e cole este link no navegador:<br>
                    <a href="{{ $link }}" style="color: #f27a2a;">{{ $link }}</a>
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
