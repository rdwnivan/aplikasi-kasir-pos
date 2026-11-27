@php
    $rp = fn (int $n) => number_format($n, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bukti pelunasan {{ $payment->number }}</title>
    <style>
        @page { size: {{ $width }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; background: #fff; color: #000; }
        body {
            width: {{ $width }}mm;
            padding: 3mm {{ $width === 80 ? 4 : 2 }}mm 6mm;
            font: {{ $width === 80 ? '12px' : '10.5px' }}/1.35 "Courier New", ui-monospace, monospace;
        }
        .c { text-align: center; }
        .r { text-align: right; }
        .b { font-weight: 700; }
        .big { font-size: 1.25em; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 0; vertical-align: top; }
        .muted { font-size: .9em; }
        .toolbar { position: fixed; top: 8px; right: 8px; display: flex; gap: 6px; font-family: system-ui, sans-serif; }
        .toolbar a, .toolbar button { font: inherit; font-size: 13px; padding: 6px 10px; border: 1px solid #ccc; background: #f6f6f6; border-radius: 6px; color: #000; text-decoration: none; cursor: pointer; }
        @media screen { html { background: #e5e5e5; } body { margin: 16px auto; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="?w=58">58 mm</a>
        <a href="?w=80">80 mm</a>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="c">
        <div class="b big">{{ $store['name'] }}</div>
        <div>{{ $store['address'] }}</div>
        @if ($store['phone']) <div>Telp. {{ $store['phone'] }}</div> @endif
    </div>
    <hr>
    <div class="c b">BUKTI PELUNASAN KASBON</div>
    <hr>
    <table class="muted">
        <tr><td>No</td><td class="r">{{ $payment->number }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ $payment->receivedAt()?->translatedFormat('d M Y H:i') }}</td></tr>
        <tr><td>Kasir</td><td class="r">{{ $payment->user->name }}</td></tr>
        <tr><td>Pelanggan</td><td class="r">{{ $payment->customer->name }}</td></tr>
        <tr><td>Bayar</td><td class="r">{{ $payment->isQris() ? 'QRIS' : 'Tunai' }}</td></tr>
        @if ($payment->isQris() && $payment->payment_reference)
            <tr><td>Ref.</td><td class="r">{{ $payment->payment_reference }}</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <tr class="b big"><td>DIBAYAR</td><td class="r">{{ $rp($payment->amount) }}</td></tr>
    </table>
    <hr>
    <div class="muted">Untuk kasbon:</div>
    <table class="muted">
        @foreach ($allocations as $line)
            <tr><td colspan="2">{{ $line['number'] }}</td></tr>
            <tr>
                <td>&nbsp;dibayar {{ $rp($line['paid']) }}</td>
                <td class="r">{{ $line['left'] === 0 ? 'LUNAS' : 'sisa '.$rp($line['left']) }}</td>
            </tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr class="b"><td>Sisa utang</td><td class="r">{{ $rp($balanceAfter) }}</td></tr>
    </table>
    @if ($payment->note)
        <div class="muted">Catatan: {{ $payment->note }}</div>
    @endif
    <hr>
    <div class="c">Terima kasih.</div>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => { window.print(); });</script>
    @endif
</body>
</html>
