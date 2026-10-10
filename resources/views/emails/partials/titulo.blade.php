{{-- Título dos e-mails, com um ícone grande opcional em cima. --}}
@isset($icone)
    <div style="text-align: center; font-size: 40px; line-height: 1; margin: 0 0 12px;">{{ $icone }}</div>
@endisset
<h1 style="margin: 0 0 16px; text-align: {{ $alinhar ?? 'center' }}; font-family: Arial, Helvetica, sans-serif; font-size: 24px; line-height: 1.3; font-weight: 800; color: #111827;">
    {{ $texto }}
</h1>
