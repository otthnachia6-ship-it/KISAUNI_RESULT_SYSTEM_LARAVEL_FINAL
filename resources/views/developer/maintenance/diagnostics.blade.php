@extends('base')
@section('page_title', 'System Diagnostics')
@section('content')
<x-page_header icon="bi-clipboard2-pulse" title="System Diagnostics" :subtitle="'All health checks run together at ' . $ran_at" >
    <x-slot:actions>
        <a href="{{ route('developer.maintenance.diagnostics') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-clockwise me-1"></i>Run again</a>
    </x-slot:actions>
</x-page_header>

<div class="row g-3 mb-3">
    @foreach($sections as $key => $s)
    <div class="col-md-6 col-xl-4">
        <div class="card p-3 h-100">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <a href="{{ route('developer.monitoring', $key) }}" class="fw-semibold text-decoration-none">{{ $s['label'] }}</a>
                @include('developer.partials.status', ['status' => $s['overall']])
            </div>
            @forelse($s['problems'] as $p)
                <div class="small mb-1">@include('developer.partials.status', ['status' => $p['status']]) {{ $p['label'] }}: {{ $p['value'] }}@if($p['note'])<div class="text-muted">{{ $p['note'] }}</div>@endif</div>
            @empty
                <div class="small text-muted">{{ $s['total'] }} checks, no problems found.</div>
            @endforelse
        </div>
    </div>
    @endforeach
</div>

<h6 class="text-muted">Other checks</h6>
@include('developer.partials.checks', ['checks' => $extra])
@endsection
