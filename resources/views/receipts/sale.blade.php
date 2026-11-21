@php
    $rp = fn (int $n) => number_format($n, 0, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk {{ $sale->number }}</title>
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
        .item td { padding-top: 1mm; }
        .muted { font-size: .9em; }
        .toolbar { position: fixed; top: 8px; right: 8px; display: flex; gap: 6px; font-family: system-ui, sans-serif; }
        .toolbar a, .toolbar button { font: inherit; font-size: 13px; padding: 6px 10px; border: 1px solid #ccc; background: #f6f6f6; border-radius: 6px; color: #000; text-decoration: none; cursor: pointer; }
        @media screen { html { background: #e5e5e5; } body { margin: 16px auto; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="?w=58{{ $isCopy ? '&copy=1' : '' }}">58 mm</a>
        <a href="?w=80{{ $isCopy ? '&copy=1' : '' }}">80 mm</a>
        <button type="button" onclick="window.print()">Cetak</button>
    </div>

    <div class="c">
        <div class="b big">{{ $store['name'] }}</div>
        <div>{{ $store['address'] }}</div>
        @if ($store['phone']) <div>Telp. {{ $store['phone'] }}</div> @endif
        @if ($store['npwp']) <div>NPWP {{ $store['npwp'] }}</div> @endif
    </div>
    @if ($isCopy)
        <div class="c b" style="margin-top:1mm">*** SALINAN / CETAK ULANG ***</div>
    @endif
    <hr>
    <table class="muted">
        <tr><td>No</td><td class="r">{{ $sale->number }}</td></tr>
        <tr><td>Tgl</td><td class="r">{{ $sale->completed_at->translatedFormat('d M Y H:i') }}</td></tr>
        <tr><td>Kasir</td><td class="r">{{ $sale->user->name }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($sale->items as $item)
            <tr class="item"><td colspan="2">{{ $item->product_name }}</td></tr>
            <tr>
                <td>{{ $item->qty }} {{ $item->unit_name }} x {{ $rp($item->unit_price) }}</td>
                <td class="r">{{ $rp($item->qty * $item->unit_price) }}</td>
            </tr>
            @if ($item->discount_amount > 0)
                <tr class="muted">
                    <td>&nbsp;Diskon{{ $item->discount_type?->value === 'percent' ? ' '.$item->discount_value.'%' : '' }}</td>
                    <td class="r">-{{ $rp($item->discount_amount) }}</td>
                </tr>
            @endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Subtotal</td><td class="r">{{ $rp($sale->subtotal) }}</td></tr>
        @if ($sale->discount_amount > 0)
            <tr><td>Diskon{{ $sale->discount_type?->value === 'percent' ? ' '.$sale->discount_value.'%' : '' }}</td><td class="r">-{{ $rp($sale->discount_amount) }}</td></tr>
        @endif
        @if ($sale->tax_amount > 0 && ! $sale->prices_include_tax)
            <tr><td>PPN</td><td class="r">{{ $rp($sale->tax_amount) }}</td></tr>
        @endif
        <tr class="b big"><td>TOTAL</td><td class="r">{{ $rp($sale->total) }}</td></tr>
        <tr><td>Tunai</td><td class="r">{{ $rp($sale->paid_amount) }}</td></tr>
        <tr><td>Kembali</td><td class="r">{{ $rp($sale->change_amount) }}</td></tr>
    </table>
    @if ($sale->tax_amount > 0)
        <hr>
        <table class="muted">
            @if ($sale->prices_include_tax)
                <tr><td colspan="2">Harga sudah termasuk PPN</td></tr>
            @endif
            <tr><td>DPP</td><td class="r">{{ $rp($sale->dpp_amount) }}</td></tr>
            <tr><td>PPN {{ rtrim(rtrim($sale->tax_rate, '0'), '.') }}%</td><td class="r">{{ $rp($sale->tax_amount) }}</td></tr>
        </table>
    @endif
    <hr>
    <div class="c">{{ $store['receipt_footer'] }}</div>

    @if ($autoPrint)
        <script>window.addEventListener('load', () => { window.print(); });</script>
    @endif
</body>
</html>
