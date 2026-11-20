@extends('base')
@section('page_title', 'Security Overview')
@section('content')
<x-page_header icon="bi-shield-check" title="Security Overview" :subtitle="'Protection settings and sign-in events of the last ' . $retention_days . ' days (audit log retention)'" />

<div class="row g-3 mb-3">
    @foreach($counts as $label => $value)
    <div class="col-6 col-lg-3">
        <div class="card p-3 h-100"><div class="text-muted small">{{ $label }}</div><div class="fs-3 fw-bold">{{ $value }}</div></div>
    </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <h6 class="text-muted">Protection settings</h6>
        @include('developer.partials.checks', ['checks' => $checks])
    </div>
    <div class="col-lg-5">
        <h6 class="text-muted">Accounts</h6>
        <div class="card">
            <table class="table table-sm mb-0">
                <tbody>
                @foreach($accounts as $label => $value)
                <tr><td>{{ $label }}</td><td class="text-end fw-semibold">{{ $value }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <div class="d-flex gap-2 mt-3 flex-wrap">
            <a href="{{ route('developer.security.attempts') }}" class="btn btn-sm btn-outline-secondary">Login attempts</a>
            <a href="{{ route('developer.security.sessions') }}" class="btn btn-sm btn-outline-secondary">Sessions</a>
            <a href="{{ route('developer.security.login_activity') }}" class="btn btn-sm btn-outline-secondary">Login activity</a>
        </div>
    </div>
</div>
@endsection
