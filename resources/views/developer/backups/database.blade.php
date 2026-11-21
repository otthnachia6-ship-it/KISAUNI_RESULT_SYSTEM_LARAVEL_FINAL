@extends('base')
@section('page_title', 'Database Overview')
@section('content')
<x-page_header icon="bi-table" title="Database Overview" subtitle="Connection, size and the number of records in each school table (read-only)">
    <x-slot:actions>
        <a href="{{ route('developer.backups.create') }}" class="btn btn-primary"><i class="bi bi-database-add me-1"></i>Create backup</a>
    </x-slot:actions>
</x-page_header>

@include('developer.partials.checks', ['checks' => $checks])

@if($pending)
<div class="card border-warning mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-exclamation-triangle text-warning me-1"></i>Pending migrations ({{ count($pending) }})</div>
    <div class="card-body">
        <p class="small text-muted mb-2">These database updates are in the code but have not been applied to this database yet:</p>
        <ul class="small mb-3">
            @foreach($pending as $m)<li><code>{{ $m }}</code></li>@endforeach
        </ul>
        <p class="small text-muted mb-3">Pressing the button first makes a verified backup of the whole database. If that backup fails, nothing is run.</p>
        <form method="POST" action="{{ route('developer.backups.migrate') }}" data-once
              onsubmit="return appConfirm(this, 'Apply {{ count($pending) }} pending database update(s) now? A verified backup is made first.', {okClass: 'btn-warning', okText: 'Backup and run', icon: 'bi-database-gear', title: 'Run pending migrations'});">
            @csrf
            <button class="btn btn-warning"><i class="bi bi-database-gear me-1"></i>Back up, then run pending migrations</button>
        </form>
    </div>
</div>
@else
<p class="small text-muted mb-3"><i class="bi bi-check-circle text-success me-1"></i>All database migrations have been applied.</p>
@endif

<div class="card">
    <div class="card-header fw-semibold">School tables</div>
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
@endsection
