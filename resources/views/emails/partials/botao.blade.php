{{--
    Botão dos e-mails: @include('emails.partials.botao', ['url' => ..., 'texto' => ..., 'cor' => '#0FB930'])
    'largo' => true (28/11/2026): ocupa a largura toda, mais fácil de tocar no celular.
--}}
@if (! empty($largo))
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: {{ $margem ?? '24px 0' }};">
        <tr>
            <td align="center" style="border-radius: 10px; background-color: {{ $cor ?? '#f27a2a' }};">
                <a href="{{ $url }}" target="_blank" style="display: block; padding: 15px 20px; font-family: Arial, Helvetica, sans-serif; font-size: 16px; line-height: 20px; font-weight: 700; color: #ffffff; text-decoration: none; text-align: center; border-radius: 10px;">
                    {{ $texto }}
                </a>
            </td>
        </tr>
    </table>
@else
<table role="presentation" cellpadding="0" cellspacing="0" align="{{ $alinhar ?? 'center' }}" style="margin: 24px auto;">
    <tr>
        <td style="border-radius: 999px; background-color: {{ $cor ?? '#f27a2a' }};">
            <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 34px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 999px;">
                {{ $texto }}
            </a>
        </td>
    </tr>
</table>
@endif
