@php
    $money = fn ($amount): string => number_format((float) $amount, 2);
    $isVoid = $invoice->status === \App\Enums\InvoiceStatus::Void;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->number }}</title>
    @include('partials.print-styles')
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="page">
        @if ($isVoid)
            <div class="stamp void">VOID</div>
        @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)
            <div class="stamp paid">PAID</div>
        @endif

        <header>
            <div>
                <h1>{{ config('app.name') }}</h1>
                @if ($invoice->lease)<div class="muted">{{ $invoice->lease->unit->property->name }}</div>@endif
            </div>
            <div class="title">
                <h2>{{ (float) $invoice->vat_amount > 0 ? 'TAX INVOICE' : 'INVOICE' }}</h2>
                <div><strong>{{ $invoice->number }}</strong></div>
            </div>
        </header>

        <section class="parties">
            <div>
                <h3>Bill to</h3>
                <strong>{{ $invoice->tenant->name }}</strong><br>
                @if ($invoice->tenant->postal_address){{ $invoice->tenant->postal_address }}<br>@endif
                {{ $invoice->tenant->phone }}<br>
                @if ($invoice->tenant->kra_pin)KRA PIN: {{ $invoice->tenant->kra_pin }}@endif
            </div>
            <div>
                <dl>
                    <dt>Invoice date</dt><dd>{{ $invoice->issue_date->format('d M Y') }}</dd>
                    <dt>Due date</dt><dd><strong>{{ $invoice->due_date->format('d M Y') }}</strong></dd>
                    @if ($invoice->period_start)<dt>Period</dt><dd>{{ $invoice->period_start->format('d M Y') }} - {{ $invoice->period_end->format('d M Y') }}</dd>@endif
                    @if ($invoice->lease)<dt>Unit</dt><dd>{{ $invoice->lease->unit->fullCode() }}</dd>@endif
                    <dt>Account</dt><dd>{{ $invoice->tenant->account_number }}</dd>
                </dl>
            </div>
        </section>

        <table>
            <thead><tr><th>Description</th><th class="num">Amount</th><th class="num">VAT</th><th class="num">Total (KES)</th></tr></thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="num">{{ $money($line->amount) }}</td>
                        <td class="num">{{ (float) $line->vat_amount > 0 ? $money($line->vat_amount).' ('.rtrim(rtrim($line->vat_rate, '0'), '.').'%)' : '-' }}</td>
                        <td class="num">{{ $money($line->total()) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Subtotal</td><td class="num">{{ $money($invoice->subtotal) }}</td></tr>
            <tr><td>VAT</td><td class="num">{{ $money($invoice->vat_amount) }}</td></tr>
            <tr><td>Total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
            <tr><td>Paid</td><td class="num">{{ $money($invoice->amount_paid) }}</td></tr>
            <tr class="grand"><td>Balance due</td><td class="num">KES {{ $money($invoice->balanceDue()) }}</td></tr>
        </table>

        @unless ($isVoid)
            <div class="pay">
                <strong>How to pay</strong><br>
                M-Pesa Paybill{{ config('services.mpesa.shortcode') ? ' '.config('services.mpesa.shortcode') : '' }}, account number <strong>{{ $invoice->tenant->account_number }}</strong>.
                Please pay by {{ $invoice->due_date->format('d M Y') }}.
            </div>
        @endunless
    </div>
</body>
</html>
