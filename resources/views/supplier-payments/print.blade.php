@php
    $money = fn ($amount): string => number_format((float) $amount, 2);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payment Voucher {{ $payment->number }}</title>
    @include('partials.print-styles')
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="page">
        @if ($payment->status === \App\Enums\PaymentStatus::Reversed)
            <div class="stamp void">REVERSED</div>
        @endif

        <header>
            <div><h1>{{ config('app.name') }}</h1></div>
            <div class="title">
                <h2>PAYMENT VOUCHER</h2>
                <div><strong>{{ $payment->number }}</strong></div>
            </div>
        </header>

        <section class="parties">
            <div>
                <h3>Paid to</h3>
                <strong>{{ $payment->vendor->name }}</strong><br>
                @if ($payment->vendor->kra_pin)KRA PIN: {{ $payment->vendor->kra_pin }}<br>@endif
                @if ($payment->vendor->paymentDetails()){{ $payment->vendor->paymentDetails() }}@endif
            </div>
            <div>
                <dl>
                    <dt>Date paid</dt><dd>{{ $payment->paid_on->format('d M Y') }}</dd>
                    <dt>Method</dt><dd>{{ $payment->method->getLabel() }}</dd>
                    @if ($payment->reference)<dt>Reference</dt><dd>{{ $payment->reference }}</dd>@endif
                    <dt>Paid from</dt><dd>{{ $payment->paidFromAccount->name }}</dd>
                </dl>
            </div>
        </section>

        <table>
            <thead><tr><th>Bill</th><th>Your invoice</th><th>Description</th><th class="num">Withholding tax</th><th class="num">Paid (KES)</th></tr></thead>
            <tbody>
                @foreach ($payment->allocations as $allocation)
                    <tr>
                        <td>{{ $allocation->bill->number }}</td>
                        <td>{{ $allocation->bill->vendor_invoice_number }}</td>
                        <td>{{ $allocation->bill->description }}</td>
                        <td class="num">{{ (float) $allocation->bill->withholding_tax_amount > 0 ? $money($allocation->bill->withholding_tax_amount) : '-' }}</td>
                        <td class="num">{{ $money($allocation->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr class="grand"><td>Total paid</td><td class="num">KES {{ $money($payment->amount) }}</td></tr>
        </table>

        <section class="parties" style="margin-top: 48px">
            <div>Prepared by: {{ $payment->creator?->name ?? '________________' }}</div>
            <div>Received by: ________________ &nbsp; Date: ________</div>
        </section>
    </div>
</body>
</html>
