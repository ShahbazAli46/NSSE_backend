<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Secure Access Code - NSSE</title>
</head>
<body style="margin: 0; padding: 0; background-color: #F0F4F1; font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif; -webkit-font-smoothing: antialiased;">

    <table width="100%" border="0" cellspacing="0" cellpadding="0" style="background-color: #F0F4F1; padding: 30px 15px;">
        <tr>
            <td align="center">
                
                <!-- Main Container Card -->
                <table width="100%" border="0" cellspacing="0" cellpadding="0" style="max-width: 580px; background-color: #FFFFFF; border-radius: 24px; overflow: hidden; box-shadow: 0 15px 45px rgba(11, 70, 44, 0.12); border: 1px solid #E2E8E4;">
                    
                    <!-- Header Banner with Emerald & Gold Accent -->
                    <tr>
                        <td align="center" style="background: linear-gradient(135deg, #062618 0%, #0B462C 100%); padding: 35px 25px 25px 25px; border-bottom: 3px solid #C5A059;">
                            
                            <!-- Arabic Inscription (Bismillah) -->
                            <div style="color: #E8D4A2; font-size: 16px; font-weight: 600; letter-spacing: 1px; margin-bottom: 12px; font-family: 'Traditional Arabic', 'Scheherazade', 'Amiri', serif;">
                                بِسْمِ اللَّهِ الرَّحْمَٰنِ الرَّحِيمِ
                            </div>

                            <!-- Official NSSE Logo Crest -->
                            <div style="margin-bottom: 14px;">
                                <table align="center" border="0" cellspacing="0" cellpadding="0" style="margin: 0 auto;">
                                    <tr>
                                        <td align="center" style="width: 80px; height: 80px; background-color: #FFFFFF; border: 2px solid #C5A059; border-radius: 50%; padding: 3px; box-shadow: 0 4px 15px rgba(0,0,0,0.2);">
                                            <img src="https://bbtraders.store/logo.png" alt="NSSE Crest" width="74" height="74" style="display: block; border-radius: 50%; max-width: 74px; height: auto;">
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <!-- School Name -->
                            <h1 style="margin: 0; color: #FFFFFF; font-size: 20px; font-weight: 800; letter-spacing: 2px; text-transform: uppercase; line-height: 1.2;">
                                Nawaz Sharif
                            </h1>
                            <div style="color: #C5A059; font-size: 12px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; margin-top: 4px;">
                                School of Eminence
                            </div>
                            <div style="color: #A3C9B6; font-size: 10px; font-weight: 600; letter-spacing: 2px; text-transform: uppercase; margin-top: 2px;">
                                Chunian Campus — Digital Portal
                            </div>

                            <!-- Arabic Motto from Crest -->
                            <div style="color: #E8D4A2; font-size: 13px; margin-top: 8px; font-weight: 600;">
                                « رَّبِّ زِدْنِي عِلْمًا »
                            </div>
                        </td>
                    </tr>

                    <!-- Body Content -->
                    <tr>
                        <td style="padding: 35px 35px 25px 35px; text-align: center;">
                            
                            <h2 style="margin: 0 0 10px 0; color: #0B462C; font-size: 18px; font-weight: 800;">
                                One-Time Login Verification
                            </h2>
                            <p style="margin: 0 0 25px 0; color: #4B5563; font-size: 13px; line-height: 1.6;">
                                Hello, <strong style="color: #111827;">{{ $user->name ?? 'Distinguished User' }}</strong>.<br>
                                Use the official security passcode below to securely authenticate your session on the institutional management portal.
                            </p>

                            <!-- High-End Gold & Emerald OTP Box -->
                            <table align="center" border="0" cellspacing="0" cellpadding="0" style="margin: 0 auto 25px auto;">
                                <tr>
                                    <td align="center" style="background: linear-gradient(135deg, #0B462C 0%, #062618 100%); border: 2px solid #C5A059; border-radius: 16px; padding: 18px 35px; box-shadow: 0 8px 25px rgba(11, 70, 44, 0.25);">
                                        <div style="color: #C5A059; font-size: 10px; font-weight: 700; letter-spacing: 2px; text-transform: uppercase; margin-bottom: 4px;">
                                            ✦ SECURE ACCESS CODE ✦
                                        </div>
                                        <div style="color: #FFFFFF; font-size: 36px; font-weight: 900; letter-spacing: 8px; font-family: 'Courier New', Courier, monospace;">
                                            {{ $otp }}
                                        </div>
                                        <div style="color: #A3C9B6; font-size: 10px; margin-top: 4px;">
                                            Valid for single login attempt
                                        </div>
                                    </td>
                                </tr>
                            </table>

                            <!-- Expiry Countdown Badge -->
                            <div style="display: inline-block; background-color: #FEF3C7; color: #92400E; border: 1px solid #FCD34D; font-size: 11px; font-weight: 700; padding: 6px 14px; border-radius: 20px; margin-bottom: 25px;">
                                ⏱️ Passcode expires in <strong>5 minutes</strong>
                            </div>


                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="background-color: #F9FAFB; border-top: 1px solid #E5E7EB; padding: 20px; text-align: center;">
                            <p style="margin: 0; color: #9CA3AF; font-size: 10px; line-height: 1.5;">
                                <strong>Nawaz Sharif School of Eminence (Chunian Campus)</strong><br>
                                Automated System Notification — Please do not reply directly to this email.<br>
                                © {{ date('Y') }} All Rights Reserved.
                            </p>
                        </td>
                    </tr>

                </table>
                <!-- End Main Container Card -->

            </td>
        </tr>
    </table>

</body>
</html>
