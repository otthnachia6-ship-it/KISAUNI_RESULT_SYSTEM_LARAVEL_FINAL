@extends('base')
@section('page_title', 'Students')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <x-page_header icon="bi-people" :title="$show_removed ? 'Removed Students' : 'Student List'" :subtitle="$show_removed ? 'Students who have left or been withdrawn' : 'Manage students across all classes'" />
    <div>
        @if($session_user->role === $ROLE_HEADMASTER)
            @if($show_removed)
            <a href="{{ route('students') }}" class="btn btn-outline-secondary me-2"><i class="bi bi-arrow-left me-1"></i>Back to Active List</a>
            @else
            <a href="{{ route('students', ['status' => 'removed']) }}" class="btn btn-outline-secondary me-2"><i class="bi bi-archive me-1"></i>View Removed Students</a>
            @endif
        @endif
        @if(!$show_removed)
        <a href="{{ route('add_students_bulk') }}" class="btn btn-outline-success me-2"><i class="bi bi-people-fill me-1"></i>Register Multiple</a>
        <a href="{{ route('add_student') }}" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Register Student</a>
        @endif
    </div>
</div>

@if($show_removed)
<div class="alert alert-info small">
    Students here were removed (soft-deleted) - their history is hidden from the active list but still
    intact. From here you can <strong>Restore</strong> a real student who left by mistake, or
    <strong>Permanently Delete</strong> a student and every mark they have (e.g. leftover test/junk
    data) - permanent deletion cannot be undone.
</div>
@endif

<div class="card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        @if($session_user->role === $ROLE_HEADMASTER)
        <div class="col-auto">
            <label class="form-label small mb-1">Class</label>
            <select name="class_id" class="form-select form-select-sm" onchange="this.form.submit()">
                <option value="">All Classes</option>
                @foreach($classes as $c)
                <option value="{{ $c->id }}" @if(strval($class_filter) === strval($c->id)) selected @endif>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="col-auto">
            <label class="form-label small mb-1">Search</label>
            <input type="text" name="q" class="form-control form-control-sm" placeholder="Search by name or Reg No..." value="{{ $search }}">
        </div>
        <div class="col-auto">
            <button class="btn btn-sm btn-outline-secondary"><i class="bi bi-search me-1"></i>Search</button>
        </div>
    </form>
</div>

@if(empty($grouped))
<div class="alert alert-info">No students found.</div>
@endif

@foreach($grouped as $class_name => $list)
<div class="card mb-3">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-door-open me-1"></i>{{ $class_name }}</span>
        <span class="d-flex align-items-center gap-2">
            <span class="badge rounded-pill class-gender-chip class-gender-chip--boys">
                Boys {{ collect($list)->where('gender', 'Male')->count() }}
            </span>
            <span class="badge rounded-pill class-gender-chip class-gender-chip--girls">
                Girls {{ collect($list)->where('gender', 'Female')->count() }}
            </span>
            <span class="badge bg-secondary">{{ count($list) }} students</span>
        </span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm mb-0 align-middle">
            <thead><tr><th>#</th><th>Reg No</th><th>Full Name</th><th>Gender</th><th class="text-end no-print">Actions</th></tr></thead>
            <tbody>
            @foreach($list as $s)
            <tr>
                <td>{{ $loop->iteration }}</td>
                <td>{{ $s->reg_no }}</td>
                <td>{{ $s->full_name }}</td>
                <td>
                    <span class="badge badge-gender-{{ $s->gender }}">{{ $s->gender }}</span>
                    @if(!$s->gender_confirmed)<i class="bi bi-exclamation-triangle text-warning ms-1" title="Not confirmed"></i>@endif
                </td>
                <td class="text-end no-print">
                    @if($show_removed)
                        <a href="{{ route('student_history', ['student_id' => $s->id]) }}" class="btn btn-sm btn-outline-info" title="History"><i class="bi bi-clock-history"></i></a>
                        <form method="POST" action="{{ route('restore_student', ['student_id' => $s->id]) }}" class="d-inline"
                              onsubmit="return appConfirm(this, 'Restore {{ addslashes($s->full_name) }} back to the active student list?', {okClass: 'btn-primary', okText: 'Restore', icon: 'bi-arrow-counterclockwise'});">
                            @csrf
                            <button class="btn btn-sm btn-outline-success" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                        </form>
                        <form method="POST" action="{{ route('purge_student', ['student_id' => $s->id]) }}" class="d-inline"
                              onsubmit="return appConfirm(this, 'PERMANENTLY delete {{ addslashes($s->full_name) }} and every mark ever recorded for them? This CANNOT be undone.', {okText: 'Delete permanently', icon: 'bi-trash3-fill'});">
                            @csrf
                            <button class="btn btn-sm btn-danger" title="Permanently Delete"><i class="bi bi-trash3-fill"></i></button>
                        </form>
                    @else
                        <a href="{{ route('student_history', ['student_id' => $s->id]) }}" class="btn btn-sm btn-outline-info" title="History"><i class="bi bi-clock-history"></i></a>
                        <a href="{{ route('edit_student', ['student_id' => $s->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('delete_student', ['student_id' => $s->id]) }}" class="d-inline"
                              onsubmit="return appConfirm(this, 'Are you sure you want to remove {{ addslashes($s->full_name) }}?', {okText: 'Remove', icon: 'bi-trash'});">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    @endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endforeach
@endsection
