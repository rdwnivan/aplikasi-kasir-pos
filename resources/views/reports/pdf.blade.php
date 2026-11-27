{{-- A report for dompdf (App\Exports\ReportPdf). Plain CSS 2.1 tables; no remote assets. --}}
@php
    use App\Exports\ReportPdf;
    use App\Exports\Spreadsheet;

    $numeric = fn (array $column) => in_array($column['format'] ?? null, [Spreadsheet::RUPIAH, Spreadsheet::RUPIAH_CENTS, Spreadsheet::NUMBER, Spreadsheet::PERCENT], true);
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $document->name }} — {{ $store['name'] }}</title>
    <style>
        @page { size: A4 {{ $document->landscape ? 'landscape' : 'portrait' }}; margin: 28px 28px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 8pt; color: #111; }
        .store { font-size: 10pt; font-weight: bold; }
        .muted { color: #555; }
        h1 { font-size: 13pt; margin: 10px 0 2px; }
        p { margin: 0 0 2px; }
        h2 { font-size: 10pt; margin: 14px 0 4px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 3px 4px; border-bottom: 0.5px solid #ccc; vertical-align: top; text-align: left; word-wrap: break-word; }
        th { background: #e5e7eb; font-weight: bold; }
        .num { text-align: right; }
        td.num { white-space: nowrap; }
        tr.total td { font-weight: bold; border-top: 1px solid #111; border-bottom: none; }
        .empty { color: #555; font-style: italic; }
    </style>
</head>
<body>
    <div class="store">{{ $store['name'] }}</div>
    <div class="muted">
        {{ $store['address'] }}@if (! empty($store['phone'])) · {{ $store['phone'] }}@endif
        @if (! empty($store['npwp']))<br>NPWP {{ $store['npwp'] }}@endif
    </div>

    <h1>{{ $document->name }}</h1>
    <p>{{ $document->period }}</p>
    <p class="muted">{{ $document->note }}</p>
    <p class="muted">Dicetak {{ $printedAt }} WIB oleh {{ $printedBy }}</p>

    @foreach ($document->sheets() as $sheet)
        @php
            // Fixed column widths (from the workbook's) keep the chunks below aligned, and
            // short tables keep dompdf fast: one long table costs it far more than many.
            $widths = array_map(fn (array $column) => $column['width'] ?? 14, $sheet['columns']);
            $total = array_sum($widths);
            $chunks = $sheet['rows'] === [] ? [[]] : array_chunk($sheet['rows'], ReportPdf::ROWS_PER_TABLE);
        @endphp
        @if ($sheet['name'] !== $document->name)
            <h2>{{ $sheet['name'] }}</h2>
        @endif
        @foreach ($chunks as $chunk)
            <table>
                <colgroup>
                    @foreach ($widths as $width)
                        <col style="width: {{ round($width / $total * 100, 2) }}%">
                    @endforeach
                </colgroup>
                {{-- Every chunk has the header, and dompdf repeats it when a chunk spans a page break. --}}
                <thead>
                    <tr>
                        @foreach ($sheet['columns'] as $column)
                            <th @class(['num' => $numeric($column)])>{{ $column['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($chunk as $row)
                        <tr>
                            @foreach ($sheet['columns'] as $i => $column)
                                <td @class(['num' => $numeric($column)])>{{ ReportPdf::cell($row[$i] ?? null, $column) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td class="empty" colspan="{{ count($sheet['columns']) }}">Tidak ada data pada periode ini.</td></tr>
                    @endforelse
                    @if ($loop->last && $sheet['footer'] !== null)
                        <tr class="total">
                            @foreach ($sheet['columns'] as $i => $column)
                                <td @class(['num' => $numeric($column)])>{{ ReportPdf::cell($sheet['footer'][$i] ?? null, $column) }}</td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
        @endforeach
    @endforeach
</body>
</html>
