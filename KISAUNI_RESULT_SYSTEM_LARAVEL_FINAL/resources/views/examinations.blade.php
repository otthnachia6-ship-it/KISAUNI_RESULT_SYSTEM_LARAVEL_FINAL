@extends('base')
@section('page_title', 'Examinations')
@section('content')
<x-page_header icon="bi-calendar2-week" title="Examination Management" :subtitle="'Showing examinations for Academic Year ' . $current_academic_year . ' only'" />
<p class="text-muted small">
    Set the academic year in <a href="{{ route('settings_page') }}">Settings</a>. Examinations from previous
    years remain safely stored with all their marks and results, they are just not listed here.
</p>

@if($session_user->role === $ROLE_HEADMASTER)
<div class="card p-3 mb-3">
    <form method="POST" action="{{ route('examinations') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-4">
            <label class="form-label small">Examination Type</label>
            <select name="exam_type" class="form-select form-select-sm" required>
                @foreach($exam_types as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small">Academic Year</label>
            <input type="text" class="form-control form-control-sm" value="{{ $current_academic_year }}" disabled>
            <div class="form-text">Set from <a href="{{ route('settings_page') }}">Settings</a> - applies everywhere automatically.</div>
        </div>
        <div class="col-md-2">
            <button class="btn btn-success btn-sm w-100">Add Examination</button>
        </div>
    </form>
</div>
@endif

<div class="card">
    <table class="table align-middle mb-0">
        <thead><tr><th>#</th><th>Examination Type</th><th>Academic Year</th>@if($session_user->role === $ROLE_HEADMASTER)<th class="text-end">Action</th>@endif</tr></thead>
        <tbody>
        @forelse($exams as $e)
        <tr>
            <td>{{ $loop->iteration }}</td>
            <td class="fw-semibold">{{ $e->exam_type }}</td>
            <td>{{ $e->academic_year }}</td>
            @if($session_user->role === $ROLE_HEADMASTER)
            <td class="text-end">
                <form method="POST" action="{{ route('delete_examination', ['exam_id' => $e->id]) }}"
                      onsubmit="return appConfirm(this, 'Delete {{ addslashes($e->exam_type) }} ({{ addslashes($e->academic_year) }})? This only works if no marks/results exist for it yet.', {okText: 'Delete', icon: 'bi-trash'});">
                    @csrf
                    <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i> Remove</button>
                </form>
            </td>
            @endif
        </tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted">No examinations yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
