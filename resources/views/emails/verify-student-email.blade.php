<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify your Flexee Financial Accounting account</title>
</head>

<body style="margin:0; padding:0; background:#f4f4f5; font-family:Arial, Helvetica, sans-serif;">

<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5; padding:40px 20px;">
    <tr>
        <td align="center">

            <table
                width="100%"
                cellpadding="0"
                cellspacing="0"
                style="max-width:600px; background:#ffffff; border-radius:12px;"
            >

                <tr>
                    <td style="padding:32px; text-align:center;">

                        <div
                            style="
                                display:inline-block;
                                background:#18181b;
                                color:#ffffff;
                                width:48px;
                                height:48px;
                                line-height:48px;
                                border-radius:10px;
                                font-weight:bold;
                                font-size:18px;
                            "
                        >
                            F
                        </div>

                        <h1
                            style="
                                margin:24px 0 10px;
                                font-size:24px;
                                color:#18181b;
                            "
                        >
                            Verify your email address
                        </h1>

                        <p
                            style="
                                margin:0;
                                color:#52525b;
                                font-size:15px;
                                line-height:24px;
                            "
                        >
                            Hello {{ $user->name }},
                        </p>

                        <p
                            style="
                                margin:20px 0;
                                color:#52525b;
                                font-size:15px;
                                line-height:24px;
                            "
                        >
                            Thank you for registering for Flexee Financial Accounting.
                            Please verify your email address to activate your student account.
                        </p>

                        <p style="margin:30px 0;">

                            <a
                                href="{{ $verificationUrl }}"
                                style="
                                    display:inline-block;
                                    background:#18181b;
                                    color:#ffffff;
                                    text-decoration:none;
                                    padding:14px 24px;
                                    border-radius:8px;
                                    font-size:14px;
                                    font-weight:bold;
                                "
                            >
                                Verify Email Address
                            </a>

                        </p>

                        <p
                            style="
                                margin-top:30px;
                                color:#71717a;
                                font-size:13px;
                                line-height:20px;
                            "
                        >
                            If you did not create this account, you can safely ignore
                            this email.
                        </p>

                    </td>
                </tr>

                <tr>
                    <td
                        style="
                            border-top:1px solid #e4e4e7;
                            padding:20px 32px;
                            text-align:center;
                            color:#a1a1aa;
                            font-size:12px;
                        "
                    >
                        © {{ date('Y') }} Flexee Financial Accounting
                    </td>
                </tr>

            </table>

        </td>
    </tr>
</table>

</body>
</html>