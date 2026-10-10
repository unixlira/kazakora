{{-- Lista de produtos com foto: $linhas = [['nome' => , 'quantidade' => , 'valor' => , 'imagem' => ?string]] --}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 0 0 8px;">
    @foreach ($linhas as $linha)
        <tr>
            <td width="72" style="padding: 10px 12px 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle;">
                @if ($linha['imagem'])
                    <img src="{{ $linha['imagem'] }}" alt="" width="64" height="64" style="display: block; width: 64px; height: 64px; object-fit: cover; border-radius: 10px; border: 1px solid #eef0f3;">
                @else
                    <div style="width: 64px; height: 64px; border-radius: 10px; background-color: #f3f4f6;"></div>
                @endif
            </td>
            <td style="padding: 10px 0; border-bottom: 1px solid #eef0f3; vertical-align: middle; font-size: 14px; color: #111827;">
                {{ $linha['nome'] }}
                <div style="font-size: 12px; color: #6b7280;">Quantidade: {{ $linha['quantidade'] }}</div>
            </td>
            <td style="padding: 10px 0 10px 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; text-align: right; white-space: nowrap; font-size: 14px; font-weight: 700; color: #111827;">
                R$ {{ number_format((float) $linha['valor'], 2, ',', '.') }}
            </td>
        </tr>
    @endforeach
</table>
