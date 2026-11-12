{{--
    Daily stock and expiry email to the owner (DailyStockAlertMail).
    Mockup: right card of docs/design/08-alerts-daily-email.png.
    Inline styles on purpose: email apps (Gmail) ignore our CSS file, so this is the one view allowed to use them.
    $alerts: open alert_notification rows with batch.product and batch.inventory loaded.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jean Rose: stock and expiry alert</title>
</head>
<body style="margin:0; padding:24px; background:#FFE3F1; font-family:Nunito, Arial, sans-serif; color:#3B0A2A;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px; margin:0 auto; background:#FFFFFF; border:2px solid #F5B5D6; border-radius:24px; overflow:hidden;">
        <tr>
            <td style="background:#D1177E; color:#FFFFFF; padding:14px 24px; font-family:Poppins, Arial, sans-serif; font-weight:600; font-size:16px;">
                Jean Rose Beauty Products Trading
            </td>
        </tr>
        <tr>
            <td style="padding:24px;">
                <div style="font-size:18px; font-weight:700; margin-bottom:6px;">Stock and expiry alert</div>
                <div style="font-size:14px; color:#7A3F63; margin-bottom:16px;">
                    {{ now()->format('m/d/Y') }} · {{ $alerts->count() }} new {{ $alerts->count() === 1 ? 'alert' : 'alerts' }}
                </div>

                @foreach ($alerts as $alert)
                    <div style="background:#FFF0F7; border-radius:12px; padding:10px 14px; margin-bottom:10px; font-size:16px;">
                        <strong>{{ $alert->batch->product->product_name }}:</strong>
                        {{ $alert->alert_type }}, qty {{ $alert->batch->inventory?->quantity_on_hand ?? 0 }},
                        batch {{ $alert->batch->batch_number }}, expires {{ $alert->batch->expiry_date->format('m/d/Y') }}
                    </div>
                @endforeach

                <p style="margin:16px 0 0; font-size:15px; color:#5C1B43;">
                    Sent to the owner every morning. Each alert is emailed once. Open the Inventory page to restock or check expiry dates.
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
