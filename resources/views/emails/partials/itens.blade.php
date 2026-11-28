{{--
    Lista de produtos dos e-mails (refeita em 28/11/2026, pedido do Lira: no
    celular o nome comprido virava uma coluna estreita e alta ao lado da foto).
    Cada linha: foto quadrada fixa de 64 px à esquerda e, ao lado, o nome em
    até 2 linhas (com reticências onde o programa de e-mail aceita) e, embaixo,
    a quantidade à esquerda e o preço à direita, na mesma linha.

    $linhas = [[
        'nome' => string, 'quantidade' => int, 'valor' => float, 'imagem' => ?string,
        'icone' => ?string,     // no lugar da foto (ex.: livro digital)
        'detalhe' => ?string,   // texto cinza antes da quantidade
        'desconto' => ?string,  // linha verde embaixo (ex.: 2ª unidade com cupom)
    ]]
--}}
@php
    $dinheiro = fn ($valor) => 'R$ '.number_format((float) $valor, 2, ',', '.');
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse; margin: 0;">
    @foreach ($linhas as $linha)
        @php $borda = $loop->first ? '' : 'border-top: 1px solid #eceef1;'; @endphp
        <tr>
            <td width="64" valign="top" style="width: 64px; padding: 14px 14px 14px 0; {{ $borda }}">
                @if (! empty($linha['imagem']))
                    <img src="{{ $linha['imagem'] }}" alt="" width="64" height="64" style="display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid #eceef1; background-color: #f6f7f9;">
                @elseif (! empty($linha['icone']))
                    <div style="width: 64px; height: 64px; line-height: 64px; text-align: center; font-size: 28px; border-radius: 10px; background-color: #f6f7f9;">{{ $linha['icone'] }}</div>
                @else
                    <div style="width: 64px; height: 64px; border-radius: 10px; background-color: #f6f7f9;"></div>
                @endif
            </td>
            <td valign="top" style="padding: 14px 0; {{ $borda }} font-family: Arial, Helvetica, sans-serif;">
                <div style="font-size: 14px; line-height: 20px; font-weight: 700; color: #14202e; max-height: 40px; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; text-overflow: ellipsis;">{{ $linha['nome'] }}</div>
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 6px;">
                    <tr>
                        <td valign="bottom" style="font-family: Arial, Helvetica, sans-serif; font-size: 13px; line-height: 18px; color: #6b7280;">
                            @if (! empty($linha['detalhe'])){{ $linha['detalhe'] }} &middot; @endif Qtd: {{ $linha['quantidade'] }}
                        </td>
                        <td valign="bottom" align="right" style="font-family: Arial, Helvetica, sans-serif; font-size: 14px; line-height: 18px; font-weight: 700; color: #14202e; white-space: nowrap; text-align: right;">
                            {{ $dinheiro($linha['valor']) }}
                        </td>
                    </tr>
                </table>
                @if (! empty($linha['desconto']))
                    <div style="margin-top: 4px; font-size: 12px; line-height: 16px; font-weight: 700; color: #0a8a23;">{{ $linha['desconto'] }}</div>
                @endif
            </td>
        </tr>
    @endforeach
</table>
