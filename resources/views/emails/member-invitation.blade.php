<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workspace Invitation</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4ef; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111111;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4ef; padding: 48px 16px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">

                    {{-- Brand Mark --}}
                    <tr>
                        <td style="padding-bottom: 28px;">
                            <p style="margin: 0; font-size: 13px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: #111111;">
                                {{ config('app.name') }}
                            </p>
                        </td>
                    </tr>

                    {{-- Card with offset-shadow effect using nested tables (email-client safe) --}}
                    <tr>
                        <td>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td>
                                        {{-- Top row: card + right shadow strip --}}
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                {{-- Main Card --}}
                                                <td valign="top" style="background-color: #ffffff; border: 2px solid #111111;">
                                                    <table width="100%" cellpadding="0" cellspacing="0" border="0">

                                                        {{-- Header --}}
                                                        <tr>
                                                            <td style="padding: 44px 44px 8px 44px;">
                                                                <p style="margin: 0 0 14px; font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #6b6b6b;">
                                                                    Workspace Invitation
                                                                </p>
                                                                <h1 style="margin: 0; color: #111111; font-size: 28px; font-weight: 800; line-height: 1.25; letter-spacing: -0.5px;">
                                                                    You've been invited to collaborate.
                                                                </h1>
                                                            </td>
                                                        </tr>

                                                        {{-- Body --}}
                                                        <tr>
                                                            <td style="padding: 24px 44px 8px 44px;">
                                                                <p style="margin: 0 0 20px; font-size: 15px; line-height: 1.65; color: #1f1f1f;">
                                                                    Hello,
                                                                </p>

                                                                <p style="margin: 0 0 28px; font-size: 15px; line-height: 1.65; color: #1f1f1f;">
                                                                    <strong>{{ $invitedByName }}</strong> has invited you to join the
                                                                    <strong>{{ $workspaceName }}</strong> workspace on
                                                                    <strong>{{ config('app.name') }}</strong>.
                                                                    You've been invited as a <strong style="text-transform: capitalize;">{{ $role }}</strong>.
                                                                </p>
                                                            </td>
                                                        </tr>

                                                        {{-- CTA with table-based offset shadow --}}
                                                        <tr>
                                                            <td style="padding: 0 44px 8px 44px;">
                                                                <table cellpadding="0" cellspacing="0" border="0">
                                                                    <tr>
                                                                        {{-- Button + right shadow strip --}}
                                                                        <td valign="top">
                                                                            <table cellpadding="0" cellspacing="0" border="0">
                                                                                <tr>
                                                                                    <td style="background-color: #111111; border: 2px solid #111111;">
                                                                                        <a href="{{ $invitationUrl }}"
                                                                                           style="display: block; background-color: #111111; color: #ffffff; text-decoration: none; padding: 14px 28px; font-size: 14px; font-weight: 700; letter-spacing: 0.5px;">
                                                                                            Accept Invitation &nbsp;→
                                                                                        </a>
                                                                                    </td>
                                                                                    <td width="4" style="background-color: #d4ff4f; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                                </tr>
                                                                                <tr>
                                                                                    <td style="background-color: #d4ff4f; height: 4px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                                    <td width="4" style="background-color: #d4ff4f; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                                </tr>
                                                                            </table>
                                                                        </td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>

                                                        @if(!empty($expiresAt))
                                                        {{-- Expiry --}}
                                                        <tr>
                                                            <td style="padding: 28px 44px 0 44px;">
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #6b6b6b;">
                                                                    This invitation expires on <strong style="color: #111111;">{{ $expiresAt }}</strong>.
                                                                </p>
                                                            </td>
                                                        </tr>
                                                        @endif

                                                        {{-- Divider --}}
                                                        <tr>
                                                            <td style="padding: 32px 44px 0 44px;">
                                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                                    <tr>
                                                                        <td style="border-top: 1px solid #e5e5e0; height: 1px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>

                                                        {{-- Fallback link --}}
                                                        <tr>
                                                            <td style="padding: 24px 44px 8px 44px;">
                                                                <p style="margin: 0 0 8px; font-size: 13px; line-height: 1.6; color: #6b6b6b;">
                                                                    If the button doesn't work, copy and paste this link into your browser:
                                                                </p>
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.6; word-break: break-all;">
                                                                    <a href="{{ $invitationUrl }}" style="color: #111111; text-decoration: underline;">
                                                                        {{ $invitationUrl }}
                                                                    </a>
                                                                </p>
                                                            </td>
                                                        </tr>

                                                        {{-- Closing note --}}
                                                        <tr>
                                                            <td style="padding: 24px 44px 44px 44px;">
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #6b6b6b;">
                                                                    If you weren't expecting this invitation, you can safely ignore this email.
                                                                </p>
                                                            </td>
                                                        </tr>

                                                    </table>
                                                </td>
                                                {{-- Right side shadow strip --}}
                                                <td width="6" style="background-color: #111111; font-size: 0; line-height: 0;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                {{-- Bottom shadow strip --}}
                                                <td style="background-color: #111111; height: 6px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                <td width="6" style="background-color: #111111; font-size: 0; line-height: 0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="padding-top: 24px; text-align: center;">
                            <p style="margin: 0 0 6px; font-size: 12px; color: #6b6b6b;">
                                Sent by {{ config('app.name') }}
                            </p>
                            <p style="margin: 0; font-size: 11px; color: #9a9a9a;">
                                This is an automated email. Please do not reply directly to this message.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>
</html>
