<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title>Scheduled maintenance - KISAUNI PRIMARY SCHOOL</title>
    <style>
        :root { --navy:#0f1b3d; --blue:#2563eb; --text:#0f172a; --muted:#64748b; --border:#e6e9f2; }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            display: flex; align-items: center; justify-content: center; padding: 1.25rem;
            background: #f1f4f9; color: var(--text);
            font-family: 'Inter', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .card {
            width: 100%; max-width: 460px; background: #fff; border: 1px solid var(--border);
            border-radius: 12px; padding: 2rem 1.75rem; text-align: center;
        }
        .logo { width: 72px; height: 72px; object-fit: cover; border-radius: 50%; margin-bottom: .75rem; }
        .school { font-size: .8rem; font-weight: 700; letter-spacing: .06em; color: var(--navy); margin: 0 0 1.25rem; }
        h1 { font-size: 1.35rem; margin: 0 0 .5rem; }
        p { margin: 0 0 .75rem; line-height: 1.55; color: var(--muted); font-size: .95rem; }
        .sw { color: var(--text); }
        .note { margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--border); font-size: .8rem; color: var(--muted); }
        .card { max-width: 580px; }
        .status { display: inline-block; font-size: .72rem; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
                  padding: .3rem .75rem; border-radius: 999px; margin: 0 0 1rem; }
        .status-maint { background: #dbeafe; color: #1e40af; }
        .status-susp { background: #fef3c7; color: #92400e; }
        .lang { text-align: left; margin: 0 0 1rem; }
        .lang h2 { font-size: 1rem; margin: 0 0 .35rem; color: var(--navy); }
        .lang p { margin: 0 0 .5rem; }
        .divider { border: 0; border-top: 1px solid var(--border); margin: 1rem 0; }
        .contact { background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; padding: .65rem .9rem;
                   font-size: .88rem; color: var(--text); margin: 0 0 .5rem; }
    </style>
</head>
<body>
    <main class="card">
        <img class="logo" src="/images/logo.png" alt="" onerror="this.style.display='none'">
        <p class="school">KISAUNI PRIMARY SCHOOL &middot; RESULT SYSTEM</p>
        <span class="status status-maint">Scheduled maintenance &middot; Matengenezo yaliyopangwa</span>
        <h1>Scheduled System Maintenance</h1>

        <div class="lang">
            <p>The Kisauni Result System is currently under maintenance while scheduled system improvements are being carried out.
               During this period the system is temporarily unavailable.</p>
            <p>All student records, examination results and user accounts are secure and have not been changed.
               Service will resume shortly. Please try again in a few minutes.</p>
            <p>Thank you for your patience.</p>
        </div>

        <hr class="divider">

        <div class="lang sw">
            <h2>Matengenezo Maalum ya Mfumo</h2>
            <p>Mfumo wa Matokeo wa Kisauni uko kwenye matengenezo kwa sasa ili kufanya maboresho yaliyopangwa.
               Katika kipindi hiki mfumo haupatikani kwa muda.</p>
            <p>Kumbukumbu zote za wanafunzi, matokeo ya mitihani na akaunti za watumiaji ziko salama na hazijabadilishwa.
               Huduma itarejea hivi punde. Tafadhali jaribu tena baada ya dakika chache.</p>
            <p>Tunashukuru kwa uvumilivu wenu.</p>
        </div>

        @if(config('kisauni.support_contact'))
        <p class="contact">Contact / Mawasiliano: <strong>{{ config('kisauni.support_contact') }}</strong></p>
        @endif
        <p class="note">This page refreshes automatically every minute. &middot; Ukurasa huu unajisasisha wenyewe kila dakika.</p>
    </main>
</body>
</html>
