@php
    // Rendered once when maintenance is switched on, then served while the app is down:
    // everything (styles, logo) is inside this page; nothing is loaded from the app.
    try {
        $mSchool = strtoupper((string) \App\Models\Setting::get('school_name', \App\Services\SchoolService::DEFAULT_SCHOOL_NAME));
    } catch (\Throwable) {
        $mSchool = \App\Services\SchoolService::DEFAULT_SCHOOL_NAME;
    }
    $mLogo = \App\Support\SchoolLogo::dataUri();
    $mInitials = collect(preg_split('/\s+/', trim($mSchool)))->filter()->take(3)->map(fn ($w) => mb_substr($w, 0, 1))->implode('');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @yield('head')
    <title>@yield('title') - {{ $mSchool }}</title>
    <style>
        :root {
            --navy: #0f1b3d; --navy-2: #1e3a8a; --text: #1e293b; --muted: #475569; --line: #e2e8f0;
            --accent: @yield('accent', '#2563eb'); --accent-soft: @yield('accent_soft', '#dbeafe'); --accent-ink: @yield('accent_ink', '#1e40af');
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px 16px;
            background: radial-gradient(circle at 15% 0%, #e0e7ff 0, transparent 45%), linear-gradient(180deg, #f1f5f9, #e2e8f0);
            color: var(--text); font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; -webkit-font-smoothing: antialiased;
        }
        .sheet {
            width: 100%; max-width: 760px; background: #fff; border-radius: 18px; overflow: hidden;
            box-shadow: 0 24px 60px -20px rgba(15, 27, 61, .35), 0 2px 6px rgba(15, 27, 61, .06);
        }
        .band {
            background: linear-gradient(120deg, var(--navy), var(--navy-2)); color: #fff;
            padding: 28px 24px 24px; text-align: center; position: relative;
        }
        .band::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 4px; background: var(--accent); }
        .logo {
            width: 96px; height: 96px; border-radius: 50%; object-fit: contain; background: #fff; padding: 4px;
            box-shadow: 0 0 0 4px rgba(255,255,255,.25), 0 10px 24px rgba(0,0,0,.35); display: block; margin: 0 auto 14px;
        }
        .logo-fallback {
            width: 96px; height: 96px; border-radius: 50%; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center;
            background: #fff; color: var(--navy); font-weight: 800; font-size: 26px; letter-spacing: .04em;
            box-shadow: 0 0 0 4px rgba(255,255,255,.25), 0 10px 24px rgba(0,0,0,.35);
        }
        .school { margin: 0; font-size: 20px; font-weight: 800; letter-spacing: .04em; }
        .system { margin: 4px 0 0; font-size: 12px; font-weight: 600; letter-spacing: .22em; text-transform: uppercase; opacity: .78; }
        .body { padding: 26px 28px 8px; }
        .status {
            display: flex; align-items: center; justify-content: center; gap: 8px; width: fit-content; margin: 0 auto 18px;
            padding: 7px 16px; border-radius: 999px; background: var(--accent-soft); color: var(--accent-ink);
            font-size: 12px; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; text-align: center;
        }
        .status .dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent); flex: none; }
        .cols { display: grid; grid-template-columns: 1fr; gap: 18px; }
        @media (min-width: 640px) { .cols { grid-template-columns: 1fr 1fr; gap: 0; } .cols .lang + .lang { border-left: 1px solid var(--line); } }
        .lang { padding: 4px 6px; }
        @media (min-width: 640px) { .lang { padding: 4px 22px; } .lang:first-child { padding-left: 0; } .lang:last-child { padding-right: 0; } }
        .lang-tag { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .12em; color: var(--muted); margin-bottom: 6px; }
        h1, h2 { margin: 0 0 10px; font-size: 21px; line-height: 1.3; color: var(--navy); }
        .lang p { margin: 0 0 10px; font-size: 15px; line-height: 1.65; color: var(--text); }
        .safe {
            display: flex; gap: 12px; align-items: flex-start; margin: 14px 0 6px; padding: 14px 16px; border-radius: 12px;
            background: #f0fdf4; border: 1px solid #bbf7d0; color: #14532d; font-size: 14px; line-height: 1.55;
        }
        .safe svg { flex: none; margin-top: 1px; }
        .contact { margin: 12px 0 0; padding: 12px 16px; border-radius: 12px; background: #f8fafc; border: 1px solid var(--line); font-size: 14px; text-align: center; }
        .foot { padding: 14px 28px 22px; text-align: center; font-size: 12.5px; color: var(--muted); border-top: 1px solid var(--line); margin-top: 16px; }
    </style>
</head>
<body>
    <main class="sheet" role="main">
        <header class="band">
            @if($mLogo)
            <img class="logo" src="{{ $mLogo }}" alt="{{ $mSchool }} logo">
            @else
            <div class="logo-fallback" aria-hidden="true">{{ $mInitials }}</div>
            @endif
            <p class="school">{{ $mSchool }}</p>
            <p class="system">Result System &middot; Mfumo wa Matokeo</p>
        </header>

        <section class="body">
            <div class="status"><span class="dot"></span>@yield('status')</div>

            <div class="cols">
                <div class="lang" lang="en">
                    <span class="lang-tag">ENGLISH</span>
                    @yield('english')
                </div>
                <div class="lang sw" lang="sw">
                    <span class="lang-tag">KISWAHILI</span>
                    @yield('swahili')
                </div>
            </div>

            <div class="safe">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                <div>@yield('safe')</div>
            </div>

            @if(config('kisauni.support_contact'))
            <p class="contact">Contact / Mawasiliano: <strong>{{ config('kisauni.support_contact') }}</strong></p>
            @endif
        </section>

        <footer class="foot">@yield('footer')</footer>
    </main>
</body>
</html>
