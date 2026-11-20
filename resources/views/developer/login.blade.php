@extends('base')
@section('title', 'Developer Login')
@section('guest_content')
<div class="lp page-enter">
    <section class="lp-brand">
        <div class="lp-brand-inner">
            <div class="lp-top">
                <span class="lp-logo"><img src="{{ $logo_url($logo_path) }}" alt="{{ $school_name }} logo"></span>
                <span class="lp-school">{{ $school_name }}</span>
            </div>
            <div class="lp-hero">
                <h1 class="lp-system">Developer Control Center</h1>
            </div>
            <div class="lp-cards">
                <div class="lp-glass lp-glass-help">
                    <h2 class="lp-glass-title">Restricted area</h2>
                    <div class="lp-row">
                        <span class="lp-num">01</span>
                        <div><strong>Developer account only</strong><span class="lp-desc">Headmaster, Administrator and Class Teacher accounts sign in on the normal <a class="lp-link" href="{{ route('login') }}">school login page</a>.</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-num">02</span>
                        <div><strong>Every action is recorded</strong><span class="lp-desc">Sign-ins, backups and maintenance actions are written to the audit log.</span></div>
                    </div>
                </div>
            </div>
            <div class="lp-footer">&copy; {{ $academic_year }} {{ $school_name }}. All rights reserved.</div>
        </div>
    </section>

    <section class="lp-formside">
        @php $hasError = session()->has('danger'); @endphp
        <div class="lp-card{{ $hasError ? ' login-shake' : '' }}">
            <div class="lp-card-head">
                <div><h2 class="lp-card-title">Developer sign in</h2></div>
                <span class="lp-pill"><i class="bi bi-shield-lock"></i> Developer</span>
            </div>

            @foreach (['danger', 'success', 'warning', 'info'] as $msgType)
                @if(session()->has($msgType))
                <div class="alert alert-{{ $msgType }} alert-dismissible fade show" role="alert">
                    {{ session($msgType) }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                @endif
            @endforeach

            <form method="POST" action="{{ route('developer.login') }}">
                @csrf
                <div class="lp-field">
                    <label class="visually-hidden" for="devUsername">Username</label>
                    <input type="text" class="lp-input" id="devUsername" name="username" value="{{ old('username') }}" required autofocus placeholder="Developer username" autocomplete="username">
                </div>
                <div class="lp-field lp-pass">
                    <label class="visually-hidden" for="devPassword">Password</label>
                    <input type="password" class="lp-input" name="password" id="devPassword" required placeholder="Password" autocomplete="current-password">
                    <button type="button" class="password-toggle-btn" data-target="devPassword" aria-label="Show password" tabindex="-1"><i class="bi bi-eye"></i></button>
                </div>
                <button type="submit" class="lp-submit" data-loading-text="Signing in&hellip;">
                    <span class="lp-submit-ico"><i class="bi bi-box-arrow-in-right"></i></span>
                    <span class="lp-submit-txt">Sign In</span>
                </button>
            </form>
        </div>
    </section>
</div>
@endsection
