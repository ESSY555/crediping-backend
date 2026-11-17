<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crediping password change code</title>
</head>
<body style="margin:0;padding:24px;background:#f4f4f5;font-family:Arial,Helvetica,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;padding:32px;">
        <tr>
            <td>
                <p style="margin:0 0 8px;font-size:14px;color:#71717a;">Crediping</p>
                <h1 style="margin:0 0 16px;font-size:22px;">Confirm your password change</h1>
                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hello {{ $name }},</p>
                <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Use this one-time code to confirm the password change on your Crediping account:</p>
                <p style="margin:0 0 24px;font-size:32px;letter-spacing:6px;font-weight:700;text-align:center;">{{ $code }}</p>
                <p style="margin:0;font-size:13px;color:#71717a;line-height:1.5;">This code expires in {{ $minutes }} minutes. If you did not request a password change, you can ignore this email and your password will stay the same.</p>
            </td>
        </tr>
    </table>
</body>
</html>
