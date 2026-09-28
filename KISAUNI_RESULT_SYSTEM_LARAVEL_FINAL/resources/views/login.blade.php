@extends('base')
@section('title', 'Login')
@section('guest_content')
<div class="login-split page-enter">

    <div class="login-split-brand">
        <div class="login-split-brand-inner">
            <div class="login-split-mark">
                <img src="{{ $logo_url($logo_path) }}" alt="{{ $school_name }} logo">
                <div>
                    <div class="login-split-mark-name">{{ $school_name }}</div>
                    <div class="login-split-mark-tag">Result Management System</div>
                </div>
            </div>

            <h1 class="login-split-headline"><span class="accent-amber">Manage Results.</span> <span class="accent-teal">Measure Progress.</span> Drive Success.</h1>
            <p class="login-split-sub">
                From marks and performance to approved results and professional reports — all in one place.
            </p>

            <div class="login-split-features">
                <div class="login-split-feature">
                    <span class="login-split-feature-icon"><i class="bi bi-people"></i></span>
                    <div>
                        <div class="login-split-feature-title">Student Records</div>
                        <div class="login-split-feature-desc">Full profiles for every student, organised by class</div>
                    </div>
                </div>
                <div class="login-split-feature">
                    <span class="login-split-feature-icon"><i class="bi bi-clipboard-check"></i></span>
                    <div>
                        <div class="login-split-feature-title">Marks &amp; Approval</div>
                        <div class="login-split-feature-desc">Class Teachers enter marks, the Headmaster reviews and approves</div>
                    </div>
                </div>
                <div class="login-split-feature">
                    <span class="login-split-feature-icon"><i class="bi bi-file-earmark-pdf"></i></span>
                    <div>
                        <div class="login-split-feature-title">PDF Reports</div>
                        <div class="login-split-feature-desc">Student report cards and class result sheets, ready to print</div>
                    </div>
                </div>
                <div class="login-split-feature">
                    <span class="login-split-feature-icon"><i class="bi bi-graph-up-arrow"></i></span>
                    <div>
                        <div class="login-split-feature-title">Performance Analytics</div>
                        <div class="login-split-feature-desc">Class and subject comparisons for every examination</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="login-split-footer">&copy; {{ $academic_year }} {{ $school_name }}. All rights reserved.</div>
    </div>

    <div class="login-split-form-side">
        @php $hasError = session()->has('danger'); @endphp
        <div class="login-split-card{{ $hasError ? ' login-shake' : '' }}">
            <h4 class="login-split-card-title">Sign in to the System</h4>
            <p class="login-split-card-sub">Use your Class Teacher or Headmaster account to continue.</p>

            @foreach (['danger', 'success', 'warning', 'info'] as $msgType)
                @if(session()->has($msgType))
                <div class="alert alert-{{ $msgType }} alert-dismissible fade show" role="alert">
                    {!! session($msgType) !!}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                @endif
            @endforeach

            <form method="POST" action="{{ route('login') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label">Username</label>
                    <div class="login-input-icon">
                        <i class="bi bi-person login-input-icon-glyph"></i>
                        <input type="text" class="form-control" name="username" value="{{ old('username') }}" required autofocus placeholder="Enter your username">
                    </div>
                </div>
                <div class="mb-2">
                    <label class="form-label">Password</label>
                    <div class="password-input-group login-input-icon">
                        <i class="bi bi-lock login-input-icon-glyph"></i>
                        <input type="password" class="form-control" name="password" id="loginPassword" required placeholder="Enter your password">
                        <button type="button" class="password-toggle-btn" data-target="loginPassword" aria-label="Show password" tabindex="-1">
                            <i class="bi bi-eye"></i>
                        </button>
                    </div>
                </div>
                <div class="text-end mb-3">
                    <a href="{{ route('forgot_password') }}" style="font-size:.85rem;">Forgot password?</a>
                </div>
                <button type="submit" class="btn btn-success w-100 py-2 fw-semibold" data-loading-text="Signing in&hellip;">
                    <i class="bi bi-box-arrow-in-right me-1"></i>Sign In
                </button>
            </form>
        </div>
    </div>
</div>
@endsection
