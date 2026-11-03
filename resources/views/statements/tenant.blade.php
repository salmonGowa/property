@php
    $money = fn (string $amount): string => number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Statement - {{ $tenant->name }}</title>
    <style>
        :root { --ink: #1f2937; --muted: #6b7280; --rule: #e5e7eb; --accent: #4f46e5; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; color: var(--ink); margin: 0; background: #f3f4f6; font-size: 14px; }
        .page { max-width: 900px; margin: 24px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        header { display: flex; justify-content: space-between; gap: 24px; border-bottom: 3px solid var(--accent); padding-bottom: 16px; margin-bottom: 24px; flex-wrap: wrap; }
        h1 { margin: 0; font-size: 22px; }
        h2 { margin: 0 0 4px; font-size: 16px; }
        .muted { color: var(--muted); }
        .meta { text-align: right; }
        .summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 24px; }
        .summary div { border: 1px solid var(--rule); border-radius: 6px; padding: 12px; }
        .summary strong { display: block; font-size: 18px; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 8px 6px; border-bottom: 1px solid var(--rule); text-align: left; vertical-align: top; }
        th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        tfoot td { font-weight: 700; border-top: 2px solid var(--ink); }
        .toolbar { max-width: 900px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; padding: 0 16px; }
        .toolbar button, .toolbar a { background: var(--accent); color: #fff; border: 0; padding: 8px 16px; border-radius: 6px; font-size: 14px; cursor: pointer; text-decoration: none; }
        .toolbar a { background: #fff; color: var(--ink); border: 1px solid var(--rule); }
        footer { margin-top: 32px; font-size: 12px; color: var(--muted); }
        @media (max-width: 640px) { .page { padding: 20px; margin: 0; border-radius: 0; } .meta { text-align: left; } }
        @media print { body { background: #fff; } .toolbar { display: none; } .page { box-shadow: none; margin: 0; max-width: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <a href="javascript:history.back()">Back</a>
        <button type="button" onclick="window.print()">Print / Save as PDF</button>
    </div>
    <div class="page">
        <header>
            <div>
                <h1>{{ config('app.name') }}</h1>
                <div class="muted">Statement of Account</div>
            </div>
            <div class="meta">
                <h2>{{ $tenant->name }}</h2>
                <div>Account {{ $tenant->account_number }}</div>
                @if ($tenant->kra_pin)<div>KRA PIN {{ $tenant->kra_pin }}</div>@endif
                <div class="muted">{{ $from->format('d M Y') }} to {{ $to->format('d M Y') }}</div>
            </div>
        </header>

        <section class="summary">
            <div><span class="muted">Opening balance</span><strong>KES {{ $money($statement['opening']) }}</strong></div>
            <div><span class="muted">Invoiced</span><strong>KES {{ $money($statement['rows']->reduce(fn ($sum, $row) => bcadd($sum, $row['debit'], 2), '0')) }}</strong></div>
            <div><span class="muted">Paid</span><strong>KES {{ $money($statement['rows']->reduce(fn ($sum, $row) => bcadd($sum, $row['credit'], 2), '0')) }}</strong></div>
            <div><span class="muted">{{ bccomp($statement['closing'], '0', 2) < 0 ? 'Credit balance' : 'Balance due' }}</span><strong>KES {{ $money(ltrim($statement['closing'], '-')) }}</strong></div>
        </section>

        <table>
            <thead>
                <tr><th>Date</th><th>Document</th><th>Details</th><th class="num">Charges</th><th class="num">Payments &amp; credits</th><th class="num">Balance</th></tr>
            </thead>
            <tbody>
                <tr><td>{{ $from->format('d M Y') }}</td><td></td><td>Opening balance</td><td></td><td></td><td class="num">{{ $money($statement['opening']) }}</td></tr>
                @forelse ($statement['rows'] as $row)
                    <tr>
                        <td>{{ $row['date']->format('d M Y') }}</td>
                        <td>{{ $row['document'] }}</td>
                        <td>{{ $row['description'] }}</td>
                        <td class="num">{{ bccomp($row['debit'], '0', 2) ? $money($row['debit']) : '' }}</td>
                        <td class="num">{{ bccomp($row['credit'], '0', 2) ? $money($row['credit']) : '' }}</td>
                        <td class="num">{{ $money($row['balance']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="muted">No transactions in this period.</td></tr>
                @endforelse
            </tbody>
            <tfoot>
                <tr><td colspan="5">Closing balance (KES)</td><td class="num">{{ $money($statement['closing']) }}</td></tr>
            </tfoot>
        </table>

        <footer>
            Pay via M-Pesa Paybill{{ config('services.mpesa.shortcode') ? ' '.config('services.mpesa.shortcode') : '' }}, account number <strong>{{ $tenant->account_number }}</strong>.
            Generated {{ now()->format('d M Y H:i') }}.
        </footer>
    </div>
</body>
</html>
