@extends('base')
@section('title', 'Forgot Password')
@section('guest_content')
<div class="login-page">
    <div class="login-card">
        <div class="text-center mb-4">
            <img src="{{ $logo_url($logo_path) }}" style="height:70px; border-radius:12px;">
            <h4 class="mt-2 mb-0 fw-bold">Forgot Password</h4>
            <small>{{ $school_name }}</small>
        </div>

        @foreach (['danger', 'success', 'warning', 'info'] as $msgType)
            @if(session()->has($msgType))
            <div class="alert alert-{{ $msgType }} alert-dismissible fade show" role="alert">
                {!! session($msgType) !!}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            @endif
        @endforeach

        <p style="font-size:.85rem; color:rgba(226,232,255,.75);">
            For your security, passwords can no longer be reset automatically from this page.
            Enter your username below to notify the Headmaster, then see them in person (or call
            the school office) so they can set a new temporary password for you from the Users
            page. You will be asked to choose your own new password right after logging in with it.
        </p>
        <form method="POST" action="{{ route('forgot_password') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required autofocus placeholder="Enter your username">
            </div>
            <button type="submit" class="btn btn-success w-100 py-2 fw-semibold">
                <i class="bi bi-arrow-repeat me-1"></i>Notify Headmaster
            </button>
        </form>
        <p class="text-center mt-3 mb-0" style="font-size:.85rem;">
            <a href="{{ route('login') }}">&larr; Back to Login</a>
        </p>
    </div>
</div>
@endsection
