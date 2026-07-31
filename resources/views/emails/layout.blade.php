<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>@yield('title', 'KazaKora')</title>
    <style>
        body, table, td, a { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }
        table, td { mso-table-lspace: 0pt; mso-table-rspace: 0pt; }
        img { -ms-interpolation-mode: bicubic; border: 0; height: auto; line-height: 100%; outline: none; text-decoration: none; }
        body { margin: 0; padding: 0; width: 100% !important; height: 100% !important; background-color: #f2f5f4; }

        @media screen and (max-width: 600px) {
            .email-container { width: 100% !important; }
            .email-padding { padding-left: 20px !important; padding-right: 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f2f5f4;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f2f5f4;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <table role="presentation" class="email-container" width="600" cellpadding="0" cellspacing="0" style="width: 600px; max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden;">
                    <tr>
                        <td style="background-color: #1b3a5c; padding: 28px 32px; text-align: center;">
                            <span style="font-family: Georgia, 'Times New Roman', serif; font-size: 22px; font-weight: 600; color: #ffffff;">
                                Kaza<span style="color: #f27a2a;">Kora</span>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="email-padding" style="padding: 32px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; line-height: 1.6; color: #14202e;">
                            @yield('content')
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color: #f8f6f3; padding: 20px 32px; text-align: center; font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #8089a0;">
                            KazaKora &middot; Este é um e-mail automático, não é necessário responder.
                            @hasSection('footer-extra')
                                <br>@yield('footer-extra')
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
