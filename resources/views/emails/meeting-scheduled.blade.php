<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Meeting Scheduled</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4ef; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; color: #111111;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color: #f4f4ef; padding: 48px 16px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" border="0" style="max-width: 600px; width: 100%;">

                    <tr>
                        <td style="padding-bottom: 28px;">
                            <p style="margin: 0; font-size: 13px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: #111111;">
                                {{ config('app.name') }}
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td>
                            <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                <tr>
                                    <td>
                                        <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                            <tr>
                                                <td valign="top" style="background-color: #ffffff; border: 2px solid #111111;">
                                                    <table width="100%" cellpadding="0" cellspacing="0" border="0">

                                                        <tr>
                                                            <td style="padding: 44px 44px 8px 44px;">
                                                                <p style="margin: 0 0 14px; font-size: 11px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; color: #6b6b6b;">
                                                                    New Meeting
                                                                </p>
                                                                <h1 style="margin: 0; color: #111111; font-size: 28px; font-weight: 800; line-height: 1.25; letter-spacing: -0.5px;">
                                                                    {{ $meetingTitle }}
                                                                </h1>
                                                            </td>
                                                        </tr>

                                                        <tr>
                                                            <td style="padding: 24px 44px 8px 44px;">
                                                                <p style="margin: 0 0 24px; font-size: 15px; line-height: 1.65; color: #1f1f1f;">
                                                                    <strong>{{ $scheduledByName }}</strong> scheduled a new meeting for the
                                                                    <strong>{{ $projectName }}</strong> project.
                                                                </p>
                                                            </td>
                                                        </tr>

                                                        <tr>
                                                            <td style="padding: 0 44px 8px 44px;">
                                                                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="border: 1px solid #e5e5e0;">
                                                                    <tr>
                                                                        <td style="padding: 16px 20px; border-bottom: 1px solid #e5e5e0;">
                                                                            <p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #6b6b6b;">Date &amp; time</p>
                                                                            <p style="margin: 4px 0 0; font-size: 14px; color: #111111;">{{ $scheduledAt }}</p>
                                                                        </td>
                                                                    </tr>
                                                                    <tr>
                                                                        <td style="padding: 16px 20px; {{ $agenda ? 'border-bottom: 1px solid #e5e5e0;' : '' }}">
                                                                            <p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #6b6b6b;">Duration</p>
                                                                            <p style="margin: 4px 0 0; font-size: 14px; color: #111111;">{{ $durationMinutes }} minutes</p>
                                                                        </td>
                                                                    </tr>
                                                                    @if($agenda)
                                                                    <tr>
                                                                        <td style="padding: 16px 20px;">
                                                                            <p style="margin: 0; font-size: 11px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #6b6b6b;">Agenda</p>
                                                                            <p style="margin: 4px 0 0; font-size: 14px; line-height: 1.6; color: #111111;">{{ $agenda }}</p>
                                                                        </td>
                                                                    </tr>
                                                                    @endif
                                                                </table>
                                                            </td>
                                                        </tr>

                                                        @if($joinUrl)
                                                        <tr>
                                                            <td style="padding: 24px 44px 8px 44px;">
                                                                <table cellpadding="0" cellspacing="0" border="0">
                                                                    <tr>
                                                                        <td valign="top">
                                                                            <table cellpadding="0" cellspacing="0" border="0">
                                                                                <tr>
                                                                                    <td style="background-color: #111111; border: 2px solid #111111;">
                                                                                        <a href="{{ $joinUrl }}"
                                                                                           style="display: block; background-color: #111111; color: #ffffff; text-decoration: none; padding: 14px 28px; font-size: 14px; font-weight: 700; letter-spacing: 0.5px;">
                                                                                            Join Meeting &nbsp;→
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
                                                        @endif

                                                        <tr>
                                                            <td style="padding: 32px 44px 0 44px;">
                                                                <table width="100%" cellpadding="0" cellspacing="0" border="0">
                                                                    <tr>
                                                                        <td style="border-top: 1px solid #e5e5e0; height: 1px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                                    </tr>
                                                                </table>
                                                            </td>
                                                        </tr>

                                                        <tr>
                                                            <td style="padding: 24px 44px 44px 44px;">
                                                                <p style="margin: 0; font-size: 13px; line-height: 1.6; color: #6b6b6b;">
                                                                    You're receiving this because you're a member of the {{ $projectName }} project.
                                                                </p>
                                                            </td>
                                                        </tr>

                                                    </table>
                                                </td>
                                                <td width="6" style="background-color: #111111; font-size: 0; line-height: 0;">&nbsp;</td>
                                            </tr>
                                            <tr>
                                                <td style="background-color: #111111; height: 6px; font-size: 0; line-height: 0;">&nbsp;</td>
                                                <td width="6" style="background-color: #111111; font-size: 0; line-height: 0;">&nbsp;</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

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
