@php
    $money = fn ($amount): string => number_format((float) $amount, 2);
    $number = fn ($value): string => rtrim(rtrim(number_format((float) $value, 2), '0'), '.');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase Order {{ $order->number }}</title>
    @include('partials.print-styles')
</head>
<body>
    <div class="toolbar"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
    <div class="page">
        @if ($order->status === \App\Enums\PurchaseOrderStatus::Closed)
            <div class="stamp paid">CLOSED</div>
        @endif

        <header>
            <div>
                <h1>{{ config('app.name') }}</h1>
                @if ($order->property)<div class="muted">{{ $order->property->name }}</div>@endif
            </div>
            <div class="title">
                <h2>PURCHASE ORDER</h2>
                <div><strong>{{ $order->number }}</strong></div>
            </div>
        </header>

        <section class="parties">
            <div>
                <h3>Supplier</h3>
                <strong>{{ $order->vendor->name }}</strong><br>
                @if ($order->vendor->address){{ $order->vendor->address }}<br>@endif
                @if ($order->vendor->phone){{ $order->vendor->phone }}<br>@endif
                @if ($order->vendor->kra_pin)KRA PIN: {{ $order->vendor->kra_pin }}@endif
            </div>
            <div>
                <dl>
                    <dt>Order date</dt><dd>{{ $order->order_date->format('d M Y') }}</dd>
                    @if ($order->expected_date)<dt>Needed by</dt><dd><strong>{{ $order->expected_date->format('d M Y') }}</strong></dd>@endif
                    @if ($order->property)<dt>Deliver to</dt><dd>{{ $order->property->name }}{{ $order->workOrder?->unit ? ', unit '.$order->workOrder->unit->code : '' }}</dd>@endif
                    @if ($order->workOrder)<dt>Work order</dt><dd>{{ $order->workOrder->number }}</dd>@endif
                    <dt>Approved by</dt><dd>{{ $order->approver?->name ?? '-' }}{{ $order->approved_at ? ', '.$order->approved_at->format('d M Y') : '' }}</dd>
                </dl>
            </div>
        </section>

        <p><strong>{{ $order->description }}</strong></p>

        <table>
            <thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit price</th><th class="num">Amount</th><th class="num">VAT</th></tr></thead>
            <tbody>
                @foreach ($order->lines as $line)
                    <tr>
                        <td>{{ $line->description }}</td>
                        <td class="num">{{ $number($line->quantity) }}</td>
                        <td class="num">{{ $money($line->unit_price) }}</td>
                        <td class="num">{{ $money($line->amount) }}</td>
                        <td class="num">{{ (float) $line->vat_amount > 0 ? $money($line->vat_amount) : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td>Subtotal</td><td class="num">{{ $money($order->subtotal) }}</td></tr>
            <tr><td>VAT</td><td class="num">{{ $money($order->vat_amount) }}</td></tr>
            <tr class="grand"><td>Order total</td><td class="num">KES {{ $money($order->total) }}</td></tr>
        </table>

        <div class="pay">
            <strong>Invoicing</strong><br>
            Please quote <strong>{{ $order->number }}</strong> on your invoice. We can only pay invoices up to the amount approved on this order.
            @if ($order->notes)<br><br>{{ $order->notes }}@endif
        </div>
    </div>
</body>
</html>
