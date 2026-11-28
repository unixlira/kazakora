{{--
    Topo dos e-mails de pedido (28/11/2026): número do pedido pequeno, título
    alinhado à esquerda e a faixa de andamento em 4 etapas. A cor da marca só
    pinta as etapas feitas.

    @include('emails.partials.status-pedido', [
        'numero' => $order->numero, 'titulo' => '...', 'cor' => $cor,
        'passo' => 0..4,   // etapas concluídas (Pago, Em preparo, Enviado, Entregue); null = sem faixa
        'atual' => bool,   // a etapa seguinte aparece "em andamento" (meio preenchida)
        'aviso' => ?string, // selo no lugar da faixa (ex.: Cancelado)
    ])
--}}
@php
    $etapas = ['Pago', 'Em preparo', 'Enviado', 'Entregue'];
    $passo ??= null;
    $atual ??= true;
@endphp
<div style="font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; color: #6b7280; margin: 0 0 6px;">
    Pedido {{ $numero }}
</div>
<h1 style="margin: 0 0 18px; font-family: Arial, Helvetica, sans-serif; font-size: 24px; line-height: 30px; font-weight: 800; color: #14202e;">
    {{ $titulo }}
</h1>
@if (! empty($aviso))
    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 20px;">
        <tr>
            <td style="padding: 6px 12px; border-radius: 999px; background-color: #f3f4f6; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 16px; font-weight: 700; color: #374151;">{{ $aviso }}</td>
        </tr>
    </table>
@elseif ($passo !== null)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 0 0 22px;">
        <tr>
            @foreach ($etapas as $i => $etapa)
                @php
                    $feita = $i < $passo;
                    $andamento = $atual && $i === $passo;
                    $barra = $feita ? $cor : ($andamento ? $cor : '#e5e7eb');
                    $opacidade = $andamento ? 'opacity: 0.35;' : '';
                @endphp
                <td width="25%" valign="top" style="width: 25%; padding: 0 {{ $loop->last ? '0' : '4px' }} 0 {{ $loop->first ? '0' : '4px' }};">
                    <div style="height: 5px; line-height: 5px; font-size: 1px; border-radius: 3px; background-color: {{ $barra }}; {{ $opacidade }}">&nbsp;</div>
                    <div style="padding-top: 7px; font-family: Arial, Helvetica, sans-serif; font-size: 12px; line-height: 15px; font-weight: {{ $feita || $andamento ? '700' : '400' }}; color: {{ $feita || $andamento ? '#14202e' : '#9ca3af' }};">{{ $etapa }}</div>
                </td>
            @endforeach
        </tr>
    </table>
@endif
