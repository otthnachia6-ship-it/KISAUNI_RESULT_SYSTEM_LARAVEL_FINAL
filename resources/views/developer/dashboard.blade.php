@extends('base')
@section('page_title', 'Developer Dashboard')
@section('content')
<x-page_header icon="bi-speedometer2" title="Developer Dashboard" subtitle="School management, system monitoring, backups and maintenance in one place" />

<div class="row g-3 mb-3">
    @foreach([
        ['Active students', $stats['students'], 'bi-people', route('students')],
        ['Classes', $stats['classes'], 'bi-door-open', route('classes')],
        ['School accounts', $stats['school_accounts'], 'bi-person-badge', route('users')],
        ['Results awaiting review', $stats['pending'], 'bi-hourglass-split', route('results')],
    ] as [$label, $value, $icon, $url])
    <div class="col-6 col-lg-3">
        <a href="{{ $url }}" class="card p-3 h-100 text-decoration-none text-reset">
            <div class="d-flex align-items-center gap-2 text-muted small"><i class="bi {{ $icon }}"></i>{{ $label }}</div>
            <div class="fs-3 fw-bold">{{ $value }}</div>
        </a>
    </div>
    @endforeach
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card p-3 p-md-4 h-100">
            <div class="panel-head"><div><div class="panel-eyebrow">System Monitoring</div><h2 class="panel-title">Health summary</h2></div></div>
            <div class="list-group list-group-flush">
                @foreach($health as $key => $h)
                <a href="{{ route('developer.monitoring', $key) }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span>{{ $h['label'] }}</span>
                    @include('developer.partials.status', ['status' => $h['status']])
                </a>
                @endforeach
                <a href="{{ route('developer.backups.status') }}" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                    <span>Database backups <small class="text-muted d-block">{{ $backup['value'] }}</small></span>
                    @include('developer.partials.status', ['status' => $backup['status']])
                </a>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card p-3 p-md-4 h-100">
            <div class="panel-head"><div><div class="panel-eyebrow">Shortcuts</div><h2 class="panel-title">Quick actions</h2></div></div>
            <div class="qa-list">
                <a class="qa-row" href="{{ route('developer.backups.create') }}"><span class="qa-ico"><i class="bi bi-database-add"></i></span>Create a database backup<i class="bi bi-chevron-right qa-go"></i></a>
                <a class="qa-row" href="{{ route('add_student') }}"><span class="qa-ico"><i class="bi bi-person-plus"></i></span>Add student<i class="bi bi-chevron-right qa-go"></i></a>
                <a class="qa-row" href="{{ route('promote_students') }}"><span class="qa-ico"><i class="bi bi-arrow-up-circle"></i></span>Promote students<i class="bi bi-chevron-right qa-go"></i></a>
                <a class="qa-row" href="{{ route('marks_upload') }}"><span class="qa-ico"><i class="bi bi-cloud-arrow-up"></i></span>Upload exam marks (Excel)<i class="bi bi-chevron-right qa-go"></i></a>
                <a class="qa-row" href="{{ route('headmaster_overview') }}"><span class="qa-ico"><i class="bi bi-bar-chart-line"></i></span>Headmaster overview<i class="bi bi-chevron-right qa-go"></i></a>
                <a class="qa-row" href="{{ route('settings_page') }}"><span class="qa-ico"><i class="bi bi-gear"></i></span>School settings<i class="bi bi-chevron-right qa-go"></i></a>
            </div>
        </div>
    </div>
</div>

<div class="card p-3 p-md-4">
    <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="panel-eyebrow">System Activities</div><h2 class="panel-title">Recent audit log</h2></div>
        <a href="{{ route('audit_logs') }}" class="btn btn-sm btn-outline-secondary">All audit logs</a>
    </div>
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($recent as $log)
            @php $log = (array) $log; @endphp
            <tr><td class="text-muted text-nowrap">{{ $log['created_at'] ?? '' }}</td><td>{{ $log['username'] ?? '' }}</td><td><span class="badge bg-light text-dark border">{{ $log['action'] ?? '' }}</span></td><td class="text-muted">{{ $log['details'] ?? '' }}</td></tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted">No activity recorded in the last days.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
