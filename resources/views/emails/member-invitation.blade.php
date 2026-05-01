<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Workspace Invitation</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f7fb; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f4f7fb; padding: 40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 14px; overflow: hidden; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.08);">

                    {{-- Header --}}
                    <tr>
                        <td style="background: linear-gradient(135deg, #0f766e, #14b8a6); padding: 32px 40px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 26px; font-weight: 700;">
                                Workspace Invitation
                            </h1>
                            <p style="margin: 10px 0 0; color: #d1fae5; font-size: 15px;">
                                You have been invited to collaborate
                            </p>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td style="padding: 40px;">
                            <p style="margin: 0 0 18px; font-size: 16px; line-height: 1.6;">
                                Hello,
                            </p>

                            <p style="margin: 0 0 18px; font-size: 16px; line-height: 1.6;">
                                <strong>{{ $invitedByName }}</strong> has invited you to join the
                                <strong>{{ $workspaceName }}</strong> workspace on
                                <strong>{{ config('app.name') }}</strong>.
                            </p>

                            <p style="margin: 0 0 24px; font-size: 16px; line-height: 1.6;">
                                You are invited as a
                                <strong style="text-transform: capitalize;">{{ $role }}</strong>.
                                Please click the button below to accept your invitation and join the workspace.
                            </p>

                            {{-- Button --}}
                            <table cellpadding="0" cellspacing="0" width="100%" style="margin: 30px 0;">
                                <tr>
                                    <td align="center">
                                        <a href="{{ $invitationUrl }}"
                                           style="display: inline-block; background-color: #0f766e; color: #ffffff; text-decoration: none; padding: 14px 28px; border-radius: 10px; font-size: 16px; font-weight: 600;">
                                            Accept Invitation
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            @if(!empty($expiresAt))
                                <p style="margin: 0 0 20px; font-size: 14px; line-height: 1.6; color: #6b7280;">
                                    This invitation will expire on
                                    <strong>{{ $expiresAt }}</strong>.
                                </p>
                            @endif

                            <p style="margin: 0 0 18px; font-size: 14px; line-height: 1.6; color: #6b7280;">
                                If the button does not work, copy and paste this link into your browser:
                            </p>

                            <p style="margin: 0 0 28px; font-size: 14px; line-height: 1.6; word-break: break-all;">
                                <a href="{{ $invitationUrl }}" style="color: #0f766e; text-decoration: underline;">
                                    {{ $invitationUrl }}
                                </a>
                            </p>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #6b7280;">
                                If you were not expecting this invitation, you can safely ignore this email.
                            </p>
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td style="background-color: #f9fafb; padding: 24px 40px; text-align: center; border-top: 1px solid #e5e7eb;">
                            <p style="margin: 0 0 8px; font-size: 13px; color: #6b7280;">
                                Sent by {{ config('app.name') }}
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #9ca3af;">
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
