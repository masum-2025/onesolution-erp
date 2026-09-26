{{-- Branded notification email. Every text is escaped; the partner's wording is plain text. --}}
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $brand['name'] }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Noto Sans Bengali',Arial,sans-serif;color:#18181b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;border:1px solid #e4e4e7;">
                    <tr><td style="height:5px;background:{{ $brand['color'] }};font-size:0;line-height:0;">&nbsp;</td></tr>
                    <tr>
                        <td style="padding:24px 28px 8px;">
                            @if ($brand['logo'])
                                <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" height="32" style="display:block;height:32px;max-width:200px;border:0;">
                            @else
                                <p style="margin:0;font-size:18px;font-weight:600;color:#18181b;">{{ $brand['name'] }}</p>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 28px 8px;font-size:15px;line-height:1.6;color:#27272a;">
                            @foreach ($paragraphs as $lines)
                                <p style="margin:0 0 14px;">
                                    @foreach ($lines as $line)
                                        {{ $line }}@if (! $loop->last)<br>@endif
                                    @endforeach
                                </p>
                            @endforeach
                        </td>
                    </tr>
                    @if ($action)
                        <tr>
                            <td style="padding:4px 28px 24px;">
                                <a href="{{ $action['url'] }}" style="display:inline-block;background:{{ $brand['color'] }};color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:11px 18px;border-radius:8px;">{{ $action['label'] }}</a>
                            </td>
                        </tr>
                    @endif
                    <tr>
                        <td style="padding:16px 28px 22px;border-top:1px solid #f4f4f5;font-size:12px;line-height:1.5;color:#71717a;">
                            @if ($brand['footer'])<p style="margin:0 0 4px;">{{ $brand['footer'] }}</p>@endif
                            @if ($brand['support_email'])<p style="margin:0 0 4px;">{{ __('notifications.mail.help', ['email' => $brand['support_email']], $locale) }}</p>@endif
                            <p style="margin:0;">{{ __('notifications.mail.why', ['product' => $brand['name']], $locale) }}</p>
                            @if ($brand['powered_by'])<p style="margin:6px 0 0;color:#a1a1aa;">{{ $brand['powered_by'] }}</p>@endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
