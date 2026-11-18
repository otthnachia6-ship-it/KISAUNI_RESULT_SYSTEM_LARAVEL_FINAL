@extends('base')
@section('title', 'Login')
@section('guest_content')
<div class="lp page-enter">

    <section class="lp-brand">
        <div class="lp-brand-inner">
            <div class="lp-top">
                <span class="lp-logo"><img src="{{ $logo_url($logo_path) }}" alt="{{ $school_name }} logo"></span>
                <span class="lp-school">{{ $school_name }}</span>
            </div>

            <div class="lp-hero">
                <h1 class="lp-system">Result Management System</h1>
            </div>

            <div class="lp-cards">
                <div class="lp-glass lp-glass-features">
                    <h2 class="lp-glass-title">What you can do</h2>
                    <div class="lp-row">
                        <span class="lp-dot"></span>
                        <div><strong>Student Records</strong><span class="lp-desc">Full profiles for every student, organised by class</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-dot"></span>
                        <div><strong>Marks &amp; Approval</strong><span class="lp-desc">Class Teachers enter marks, the Headmaster reviews and approves</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-dot"></span>
                        <div><strong>PDF Reports</strong><span class="lp-desc">Student report cards and class result sheets, ready to print</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-dot"></span>
                        <div><strong>Performance Analytics</strong><span class="lp-desc">Class and subject comparisons for every examination</span></div>
                    </div>
                </div>

                <div class="lp-glass lp-glass-help">
                    <h2 class="lp-glass-title">Need quick help?</h2>
                    <div class="lp-row">
                        <span class="lp-num">01</span>
                        <div><strong>Class Teacher?</strong><span class="lp-desc">Sign in with the username and password the Headmaster gave you.</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-num">02</span>
                        <div><strong>Forgot your password?</strong><span class="lp-desc"><a class="lp-link" href="{{ route('forgot_password') }}">Notify the Headmaster</a> to get a new temporary password.</span></div>
                    </div>
                    <div class="lp-row">
                        <span class="lp-num">03</span>
                        <div><strong>Headmaster?</strong><span class="lp-desc">Sign in to review, approve and print results.</span></div>
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
                <div>
                    <h2 class="lp-card-title">Sign in to the System</h2>
                </div>
                <span class="lp-pill">Sign In</span>
            </div>

            <div class="lp-tabs">
                <span class="lp-tab is-active">Login</span>
                <a class="lp-tab" href="{{ route('forgot_password') }}">Forgot Password</a>
            </div>

            @foreach (['danger', 'success', 'warning', 'info'] as $msgType)
                @if(session()->has($msgType))
                <div class="alert alert-{{ $msgType }} alert-dismissible fade show" role="alert">
                    {{ session($msgType) }}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                @endif
            @endforeach

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="lp-field">
                    <label class="visually-hidden" for="loginUsername">Username</label>
                    <input type="text" class="lp-input" id="loginUsername" name="username" value="{{ old('username') }}" required autofocus placeholder="Username" autocomplete="username">
                </div>
                <div class="lp-field lp-pass">
                    <label class="visually-hidden" for="loginPassword">Password</label>
                    <input type="password" class="lp-input" name="password" id="loginPassword" required placeholder="Password" autocomplete="current-password">
                    <button type="button" class="password-toggle-btn" data-target="loginPassword" aria-label="Show password" tabindex="-1">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="lp-forgot">
                    <a href="{{ route('forgot_password') }}" class="touch-link">Forgot Password?</a>
                </div>
                <button type="submit" class="lp-submit" data-loading-text="Signing in&hellip;">
                    <span class="lp-submit-ico"><i class="bi bi-box-arrow-in-right"></i></span>
                    <span class="lp-submit-txt">Sign In</span>
                </button>
                <div class="lp-divider"><span>Use your personal portal credentials</span></div>
            </form>
        </div>
    </section>

    <div class="lp-credit"><a href="https://ostexs.com" target="_blank" rel="noopener noreferrer">Developed by Ostex Global Technologies</a></div>
</div>
@endsection
