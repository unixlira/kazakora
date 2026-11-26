{{-- Link do material gratuito (Guia de Viagem de Bolso) por e-mail, depois do cadastro na FerraKora (26/11/2026). --}}
@extends('emails.layout')

@section('title', $titulo)
@section('preheader', 'Seu guia gratuito está aqui. Baixe quando quiser.')

@section('content')
    @include('emails.partials.titulo', ['icone' => '🚗', 'texto' => "Seu guia chegou, ".\Illuminate\Support\Str::of($lead->nome)->explode(' ')->first()."!"])

    <p style="margin: 0 0 8px; text-align: center; color: #374151;">
        Obrigado por baixar o <strong>{{ $titulo }}</strong>. Se o download não começou na hora, ou se quiser
        guardar no celular, é só tocar no botão abaixo.
    </p>

    @include('emails.partials.botao', ['url' => $link, 'texto' => 'Baixar o guia em PDF', 'cor' => $marcaEmail['cor']])

    <p style="margin: 0 0 8px; text-align: center; color: #6b7280; font-size: 13px;">
        O link vale por {{ (int) config('materiais.dias_link', 30) }} dias.
    </p>

    @if ($oferta)
        <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 20px 0 0; background-color: #f3f2fb; border-radius: 10px; width: 100%;">
            <tr>
                <td style="padding: 16px 18px; font-family: Arial, Helvetica, sans-serif; font-size: 14px; color: #111827; text-align: center;">
                    <strong>Antes de pegar a estrada:</strong> confira a calibragem dos pneus. Com um compressor portátil
                    no porta-malas, você calibra onde estiver, sem depender do posto.<br>
                    <a href="{{ $oferta }}" target="_blank" style="color: {{ $marcaEmail['cor'] }}; font-weight: 700;">{{ $ofertaTexto }}</a>
                </td>
            </tr>
        </table>
    @endif

    <p style="margin: 20px 0 0; text-align: center; color: #9ca3af; font-size: 12px;">
        Você recebeu este e-mail porque se cadastrou para baixar o guia no site da FerraKora.
        <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('api.ferrakora.materiais.sair', ['lead' => $lead->id]) }}" style="color: #9ca3af;">Não quero mais receber</a>.
    </p>
@endsection
