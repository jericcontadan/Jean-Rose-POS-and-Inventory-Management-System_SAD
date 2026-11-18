{{--
    Reset password email (PasswordResetMail). Same pink style as daily-stock-alert.blade.php.
    Inline styles on purpose: email apps (Gmail) ignore our CSS file.
    $url: the one-time reset link.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Jean Rose POS: reset your password</title>
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
                <div style="font-size:18px; font-weight:700; margin-bottom:6px;">Reset your password</div>
                <p style="margin:0 0 20px; font-size:15px; color:#5C1B43;">
                    We got a request to reset the password of your Jean Rose POS account. Tap the button to choose a new password.
                </p>

                <a href="{{ $url }}" style="display:inline-block; background:#D1177E; color:#FFFFFF; text-decoration:none; font-family:Poppins, Arial, sans-serif; font-weight:700; font-size:16px; padding:14px 28px; border-radius:14px;">
                    Reset password
                </a>

                <p style="margin:20px 0 0; font-size:14px; color:#7A3F63;">
                    This link works once and expires in 60 minutes. If you did not ask for this, ignore this email; your password stays the same.
                </p>
                <p style="margin:12px 0 0; font-size:12px; color:#7A3F63; word-break:break-all;">
                    Button not working? Copy this link: {{ $url }}
                </p>
            </td>
        </tr>
    </table>
</body>
</html>
