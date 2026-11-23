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

@if($uploads && count($uploads['missing']) > 0)
<div class="card border-warning mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-image text-warning me-1"></i>Missing uploaded files ({{ count($uploads['missing']) }})</div>
    <div class="card-body">
        <p class="small text-muted mb-2">The school logo and staff profile photos are recorded in the database, but {{ count($uploads['missing']) }} image file(s) are not on this server yet (so they show broken). This happens after a move where the files were not copied.</p>
        @if($restore_host)
        <p class="small text-muted mb-3">The button copies the missing files from the old server (<code>{{ $restore_host }}</code>) into this server. It only adds image files; it does not touch the database.</p>
        <form method="POST" action="{{ route('developer.backups.copy_uploads') }}" data-once
              onsubmit="return appConfirm(this, 'Copy {{ count($uploads['missing']) }} missing image file(s) from the old server now?', {okClass: 'btn-warning', okText: 'Copy files', icon: 'bi-images', title: 'Restore uploaded files'});">
            @csrf
            <button class="btn btn-warning"><i class="bi bi-images me-1"></i>Copy missing files from the old server</button>
        </form>
        @else
        <p class="small text-danger mb-0">No source server is configured (UPLOADS_RESTORE_HOST).</p>
        @endif
    </div>
</div>
@elseif($uploads)
<p class="small text-muted mb-3"><i class="bi bi-check-circle text-success me-1"></i>All uploaded files (logo and photos) are present on this server.</p>
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
