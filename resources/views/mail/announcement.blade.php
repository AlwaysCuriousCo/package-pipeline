{{--
    The registry's announcements — a release, a failed sync, an abandonment —
    as email. One template for all of them, differing only in the accent colour
    the tone picks.

    Inline styles on nested tables, no <style> block and no images, for the
    reasons resources/views/mail/welcome.blade.php sets out at length: mail
    clients are not browsers, and this is the layout all of them agree on.

    @var string      $appName
    @var string      $title
    @var string      $body
    @var string      $actionLabel
    @var string      $actionUrl
    @var string      $tone           one of success, warning, danger, info
    @var string|null $email          null when the notifiable is not a panel user
    @var string      $preferencesUrl
--}}
@php
    // Matched to the colours the same event is given in the panel's bell.
    $accent = match ($tone) {
        'success' => '#15803d',
        'warning' => '#b45309',
        'danger' => '#b91c1c',
        default => '#3f3f46',
    };
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light" />
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f4f4f5; color: #18181b; -webkit-font-smoothing: antialiased;">

{{-- The line clients show next to the subject in the inbox list; hidden in the message itself. --}}
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
    {{ $body }}
</div>

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4f5;">
    <tr>
        <td align="center" style="padding: 40px 16px;">

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 520px; margin: 0 auto;">

                <tr>
                    <td style="padding: 0 8px 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 13px; font-weight: 600; letter-spacing: 0.08em; text-transform: uppercase; color: #71717a;">
                        {{ $appName }}
                    </td>
                </tr>

                <tr>
                    <td style="background-color: #ffffff; border: 1px solid #e4e4e7; border-top: 3px solid {{ $accent }}; border-radius: 10px; padding: 36px 32px;">

                        <p style="margin: 0 0 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 20px; line-height: 1.35; font-weight: 600; color: #18181b;">
                            {{ $title }}
                        </p>

                        <p style="margin: 0 0 28px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                            {{ $body }}
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color: {{ $accent }}; border-radius: 6px;">
                                    <a href="{{ $actionUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 1; color: #ffffff; text-decoration: none;">
                                        {{ $actionLabel }}
                                    </a>
                                </td>
                            </tr>
                        </table>

                    </td>
                </tr>

                <tr>
                    <td style="padding: 20px 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #a1a1aa;">
                        Sent{{ filled($email) ? ' to '.$email : '' }} because you have a role on {{ $appName }}. The same notice is waiting in the panel, so you lose nothing by <a href="{{ $preferencesUrl }}" target="_blank" rel="noopener" style="color: #71717a; text-decoration: underline;">turning these emails off</a>.
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
