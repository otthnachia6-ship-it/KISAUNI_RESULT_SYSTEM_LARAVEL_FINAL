@extends('base')
@section('page_title', $title)
@section('content')
<x-page_header icon="bi-activity" :title="$title" subtitle="Live values read from this server. Anything the host does not report is shown as Unavailable." />

<div class="d-flex align-items-center gap-2 mb-3">
    <span class="text-muted">Overall:</span> @include('developer.partials.status', ['status' => $overall])
    <a href="{{ route('developer.monitoring', $section) }}" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</a>
</div>

@include('developer.partials.checks', ['checks' => $checks])

@if($section === 'database' && $tables)
<div class="card mb-3">
    <div class="card-header fw-semibold">Rows per table</div>
    <div class="table-responsive">
    <table class="table table-sm mb-0">
        <thead><tr><th>Table</th><th>Contents</th><th class="text-end">Rows</th></tr></thead>
        <tbody>
        @foreach($tables as $t)
        <tr><td><code>{{ $t['table'] }}</code></td><td>{{ $t['label'] }}</td><td class="text-end">{{ $t['rows'] === null ? '-' : number_format($t['rows']) }}</td></tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endif

@if($section === 'errors')
<div class="card">
    <div class="card-header fw-semibold">Latest log entries <small class="text-muted fw-normal">(newest first, last part of the log file, one line each, secrets masked)</small></div>
    <div class="table-responsive">
    <table class="table table-sm mb-0 dev-log">
        <thead><tr><th>Time</th><th>Level</th><th>Message</th></tr></thead>
        <tbody>
        @forelse($log_entries as $e)
        <tr>
            <td class="text-muted dev-time" data-label="Time">{{ $e['time'] }}</td>
            <td data-label="Level"><span class="badge {{ in_array($e['level'], ['ERROR','CRITICAL','ALERT','EMERGENCY']) ? 'bg-danger' : ($e['level'] === 'WARNING' ? 'bg-warning text-dark' : 'bg-secondary') }}">{{ $e['level'] }}</span></td>
            <td class="small dev-msg" data-label="Message">{{ $e['message'] }}</td>
        </tr>
        @empty
        <tr><td colspan="3" class="text-center text-muted dev-empty">No log entries.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endif
@endsection
