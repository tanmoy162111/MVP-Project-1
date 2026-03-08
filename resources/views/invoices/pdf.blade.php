<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 12px; color: #1e293b; }
  .page { padding: 40px; }
  .header { display: flex; justify-content: space-between; border-bottom: 3px solid #1a4f8a; padding-bottom: 20px; margin-bottom: 24px; }
  .brand { font-size: 22px; font-weight: bold; color: #1a4f8a; }
  .brand-sub { font-size: 11px; color: #64748b; margin-top: 4px; }
  .invoice-meta { text-align: right; }
  .invoice-number { font-size: 20px; font-weight: bold; color: #1a4f8a; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 11px; font-weight: bold; text-transform: uppercase; }
  .badge-issued  { background: #dbeafe; color: #1d4ed8; }
  .badge-paid    { background: #dcfce7; color: #166534; }
  .badge-overdue { background: #fee2e2; color: #991b1b; }
  .badge-partial { background: #fef3c7; color: #92400e; }
  .parties { display: flex; gap: 40px; margin-bottom: 24px; }
  .party { flex: 1; }
  .party-label { font-size: 10px; font-weight: bold; color: #64748b; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
  .party-name { font-size: 14px; font-weight: bold; }
  .party-detail { font-size: 11px; color: #475569; line-height: 1.6; }
  .dates-row { display: flex; gap: 40px; margin-bottom: 28px; background: #f8fafc; padding: 12px 16px; border-radius: 6px; }
  .date-item label { font-size: 10px; text-transform: uppercase; color: #94a3b8; letter-spacing: 0.5px; }
  .date-item .val { font-size: 13px; font-weight: 600; color: #1e293b; }
  table.items { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
  table.items thead tr { background: #1a4f8a; }
  table.items thead th { padding: 10px 12px; text-align: left; font-size: 11px; color: #fff; font-weight: 600; }
  table.items tbody tr:nth-child(even) { background: #f8fafc; }
  table.items tbody td { padding: 9px 12px; font-size: 11px; border-bottom: 1px solid #e2e8f0; }
  .text-right { text-align: right; }
  .totals { float: right; width: 280px; margin-top: 10px; }
  .totals table { width: 100%; border-collapse: collapse; }
  .totals td { padding: 6px 10px; font-size: 12px; }
  .totals .total-row td { font-size: 14px; font-weight: bold; background: #1a4f8a; color: #fff; border-radius: 4px; }
  .footer { clear: both; margin-top: 40px; border-top: 1px solid #e2e8f0; padding-top: 16px; font-size: 10px; color: #94a3b8; text-align: center; }
  .paid-stamp { text-align: center; margin: 20px 0; font-size: 36px; font-weight: bold; color: #16a34a; opacity: 0.3; transform: rotate(-15deg); letter-spacing: 6px; }
</style>
</head>
<body>
<div class="page">

  <div class="header">
    <div>
      <div class="brand">Electronics Platform</div>
      <div class="brand-sub">Multi-Vendor B2B Marketplace &nbsp;·&nbsp; Dhaka, Bangladesh</div>
    </div>
    <div class="invoice-meta">
      <div class="invoice-number">INVOICE</div>
      <div style="font-size:13px; font-weight:600; margin: 4px 0;">{{ $invoice->invoice_number }}</div>
      @php
        $badgeClass = match($invoice->status) {
          'paid'    => 'badge-paid',
          'overdue' => 'badge-overdue',
          'partial' => 'badge-partial',
          default   => 'badge-issued',
        };
      @endphp
      <span class="badge {{ $badgeClass }}">{{ strtoupper($invoice->status) }}</span>
    </div>
  </div>

  <div class="parties">
    <div class="party">
      <div class="party-label">Bill To</div>
      <div class="party-name">{{ $invoice->customer->name }}</div>
      <div class="party-detail">
        {{ $invoice->customer->company_name ?? '' }}<br>
        {{ $invoice->customer->email }}<br>
        {{ $invoice->customer->phone ?? '' }}<br>
        @if($invoice->billing_address)
          {{ $invoice->billing_address['line1'] ?? '' }},
          {{ $invoice->billing_address['city'] ?? '' }}
        @endif
      </div>
    </div>
    <div class="party">
      <div class="party-label">Payment Terms</div>
      <div class="party-name" style="text-transform:uppercase;">{{ str_replace('_', ' ', $invoice->payment_terms) }}</div>
    </div>
  </div>

  <div class="dates-row">
    <div class="date-item">
      <label>Issue Date</label>
      <div class="val">{{ $invoice->issued_at->format('d M Y') }}</div>
    </div>
    <div class="date-item">
      <label>Due Date</label>
      <div class="val" style="{{ $invoice->isOverdue() ? 'color:#dc2626;' : '' }}">
        {{ $invoice->due_at ? $invoice->due_at->format('d M Y') : '—' }}
        @if($invoice->isOverdue()) <span style="font-size:10px;">(OVERDUE)</span> @endif
      </div>
    </div>
    @if($invoice->paid_at)
    <div class="date-item">
      <label>Paid Date</label>
      <div class="val" style="color:#16a34a;">{{ $invoice->paid_at->format('d M Y') }}</div>
    </div>
    @endif
    <div class="date-item">
      <label>Order Ref</label>
      <div class="val">{{ $invoice->order->order_number ?? '—' }}</div>
    </div>
  </div>

  <table class="items">
    <thead>
      <tr>
        <th>#</th>
        <th>Product</th>
        <th>SKU</th>
        <th class="text-right">Qty</th>
        <th class="text-right">Unit Price</th>
        <th class="text-right">Total</th>
      </tr>
    </thead>
    <tbody>
      @foreach($invoice->line_items as $i => $item)
      <tr>
        <td>{{ $i + 1 }}</td>
        <td>
          {{ $item['product_name'] }}
          @if(!empty($item['variant_name'])) <span style="color:#64748b;font-size:10px;">· {{ $item['variant_name'] }}</span> @endif
        </td>
        <td style="color:#64748b;">{{ $item['sku'] }}</td>
        <td class="text-right">{{ $item['quantity'] }}</td>
        <td class="text-right">৳ {{ number_format($item['unit_price'], 2) }}</td>
        <td class="text-right">৳ {{ number_format($item['total_price'], 2) }}</td>
      </tr>
      @endforeach
    </tbody>
  </table>

  <div class="totals">
    <table>
      <tr><td>Subtotal</td><td class="text-right">৳ {{ number_format($invoice->subtotal, 2) }}</td></tr>
      @if($invoice->freight_cost > 0)
      <tr><td>Freight</td><td class="text-right">৳ {{ number_format($invoice->freight_cost, 2) }}</td></tr>
      @endif
      @if($invoice->discount_amount > 0)
      <tr><td style="color:#16a34a;">Discount</td><td class="text-right" style="color:#16a34a;">− ৳ {{ number_format($invoice->discount_amount, 2) }}</td></tr>
      @endif
      <tr><td>VAT</td><td class="text-right">৳ {{ number_format($invoice->tax_amount, 2) }}</td></tr>
      <tr class="total-row">
        <td>TOTAL (BDT)</td>
        <td class="text-right">৳ {{ number_format($invoice->total_amount, 2) }}</td>
      </tr>
      @if($invoice->amount_paid > 0 && !$invoice->isPaid())
      <tr><td style="color:#1d4ed8;">Amount Paid</td><td class="text-right" style="color:#1d4ed8;">৳ {{ number_format($invoice->amount_paid, 2) }}</td></tr>
      <tr><td style="font-weight:bold;">Balance Due</td><td class="text-right" style="font-weight:bold; color:#dc2626;">৳ {{ number_format($invoice->balance_due, 2) }}</td></tr>
      @endif
    </table>
  </div>

  @if($invoice->isPaid())
  <div class="paid-stamp">PAID</div>
  @endif

  @if($invoice->notes)
  <div style="clear:both; margin-top:30px; background:#f8fafc; padding:12px; border-radius:6px;">
    <div style="font-size:10px; text-transform:uppercase; color:#94a3b8; margin-bottom:4px;">Notes</div>
    <div style="font-size:11px;">{{ $invoice->notes }}</div>
  </div>
  @endif

  <div class="footer">
    This is a computer-generated invoice. &nbsp;|&nbsp; Electronics Platform &nbsp;|&nbsp;
    Generated: {{ now()->format('d M Y H:i') }}
  </div>

</div>
</body>
</html>
