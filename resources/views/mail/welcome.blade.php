{{--
    The welcome email, written as one table of inline styles rather than with
    Laravel's markdown mail components.

    Mail clients are not browsers: Outlook drops <style> blocks in some
    configurations, Gmail strips them on forwarded copies, and neither supports
    the layered CSS the published components rely on. Inline styles on nested
    tables is the only layout every client agrees on, so the markup is plainer
    than anything else in this codebase on purpose. No images either — most
    clients block them until the reader asks, and a welcome that renders as a
    grey placeholder is worse than one that renders as type.
--}}
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light" />
    <title>{{ $appName }}</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f4f4f5; color: #18181b; -webkit-font-smoothing: antialiased;">

{{-- The line clients show next to the subject in the inbox list; hidden in the message itself. --}}
<div style="display: none; max-height: 0; overflow: hidden; mso-hide: all;">
    Your account is ready — here is where to sign in.
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
                    <td style="background-color: #ffffff; border: 1px solid #e4e4e7; border-radius: 10px; padding: 36px 32px;">

                        <p style="margin: 0 0 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 22px; line-height: 1.35; font-weight: 600; color: #18181b;">
                            Welcome{{ filled($name) ? ', '.$name : '' }}.
                        </p>

                        <p style="margin: 0 0 16px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                            An account has been created for you on {{ $appName }}, the Composer registry your team publishes and installs private packages through.
                        </p>

                        <p style="margin: 0 0 28px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.6; color: #3f3f46;">
                            You sign in with <strong style="color: #18181b; font-weight: 600;">{{ $email }}</strong>.
                        </p>

                        <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="background-color: #b45309; border-radius: 6px;">
                                    <a href="{{ $signInUrl }}" target="_blank" rel="noopener" style="display: inline-block; padding: 12px 24px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 1; color: #ffffff; text-decoration: none;">
                                        Sign in
                                    </a>
                                </td>
                            </tr>
                        </table>

                        <p style="margin: 28px 0 0; padding-top: 24px; border-top: 1px solid #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 14px; line-height: 1.6; color: #71717a;">
                            Whoever set the account up will have given you a password separately. If you do not have one, or it no longer works, <a href="{{ $passwordResetUrl }}" target="_blank" rel="noopener" style="color: #b45309; text-decoration: underline;">request a reset link</a> and set your own.
                        </p>

                    </td>
                </tr>

                <tr>
                    <td style="padding: 20px 8px 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #a1a1aa;">
                        Sent to {{ $email }} because an administrator created this account. If you were not expecting it, you can ignore this message — nothing happens until someone signs in.
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>
