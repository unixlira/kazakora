{{-- Botão dos e-mails: @include('emails.partials.botao', ['url' => ..., 'texto' => ..., 'cor' => '#0FB930']) --}}
<table role="presentation" cellpadding="0" cellspacing="0" align="{{ $alinhar ?? 'center' }}" style="margin: 24px auto;">
    <tr>
        <td style="border-radius: 999px; background-color: {{ $cor ?? '#f27a2a' }};">
            <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 14px 34px; font-family: Arial, Helvetica, sans-serif; font-size: 15px; font-weight: 700; color: #ffffff; text-decoration: none; border-radius: 999px;">
                {{ $texto }}
            </a>
        </td>
    </tr>
</table>
