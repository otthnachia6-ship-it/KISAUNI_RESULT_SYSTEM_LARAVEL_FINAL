@extends('base')
@section('page_title', 'Classes')
@section('content')
<x-page_header icon="bi-door-open" title="Class Management" subtitle="Streams and class-teacher assignments" />

<div class="card p-3 mb-3">
    <h6 class="mb-3"><i class="bi bi-plus-circle me-1"></i>Add a new Stream (e.g. Standard 1 C)</h6>
    <form method="POST" action="{{ route('add_class') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-sm-4 col-md-3">
            <label class="form-label small mb-1">Standard</label>
            <select name="standard" class="form-select form-select-sm" required>
                @for ($n = 1; $n <= $num_standards; $n++)
                <option value="{{ $n }}">Standard {{ $ordinal_words[$n] ?? $n }}</option>
                @endfor
            </select>
        </div>
        <div class="col-sm-4 col-md-3">
            <label class="form-label small mb-1">Stream letter/name</label>
            <input type="text" name="stream" class="form-control form-control-sm" placeholder="e.g. C" maxlength="10" required>
        </div>
        <div class="col-sm-4 col-md-3">
            <button class="btn btn-sm btn-success"><i class="bi bi-plus-lg me-1"></i>Add Class</button>
        </div>
    </form>
    <p class="text-muted small mt-2 mb-0">New classes automatically get the right subjects for their
    Standard (1-3 use the lower-class subject list, 4-7 use the upper-class list).</p>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Class</th><th>Students</th><th>Current Class Teacher</th><th style="width:280px;">Assign Class Teacher</th><th style="width:70px;"></th></tr></thead>
            <tbody>
            @foreach($classes as $c)
            <tr>
                <td class="fw-semibold">{{ $c->name }}</td>
                <td>{{ $c->student_count }}</td>
                <td>
                    @if($c->teacher_name)
                        <span class="badge bg-success">{{ $c->teacher_name }}</span>
                        <form method="POST" action="{{ route('classes') }}" class="d-inline ms-1"
                              onsubmit="return appConfirm(this, 'Remove {{ addslashes($c->teacher_name) }} from {{ addslashes($c->name) }}? You can assign a new teacher afterwards.', {okText: 'Remove', icon: 'bi-person-dash'});">
                            @csrf
                            <input type="hidden" name="class_id" value="{{ $c->id }}">
                            <input type="hidden" name="teacher_id" value="">
                            <button class="btn btn-sm btn-outline-danger py-0 px-1" title="Remove teacher"><i class="bi bi-x-lg"></i></button>
                        </form>
                    @else
                        <span class="badge bg-secondary">Not assigned</span>
                    @endif
                </td>
                <td>
                    <form method="POST" action="{{ route('classes') }}" class="d-flex gap-2">
                        @csrf
                        <input type="hidden" name="class_id" value="{{ $c->id }}">
                        <select name="teacher_id" class="form-select form-select-sm">
                            <option value="">-- None --</option>
                            @foreach($teachers as $t)
                            <option value="{{ $t->id }}" @if($c->teacher_id == $t->id) selected @endif>{{ $t->full_name }}</option>
                            @endforeach
                        </select>
                        <button class="btn btn-sm btn-outline-success">Save</button>
                    </form>
                </td>
                <td>
                    @if($c->student_count == 0)
                    <form method="POST" action="{{ route('delete_class', ['class_id' => $c->id]) }}"
                          onsubmit="return appConfirm(this, 'Remove empty class {{ addslashes($c->name) }}? This cannot be undone.', {okText: 'Remove', icon: 'bi-trash'});">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger" title="Remove this empty class"><i class="bi bi-trash"></i></button>
                    </form>
                    @endif
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
<p class="text-muted small mt-2">To create a new Class Teacher account, go to <a href="{{ route('add_user') }}">Users / Accounts</a>.
Use the <i class="bi bi-x-lg"></i> button to unassign a teacher (e.g. if they have left the school), then use the
dropdown to assign a replacement. You can also deactivate the old teacher's account from
<a href="{{ route('users') }}">Users / Accounts</a>. A class can only be removed
(<i class="bi bi-trash"></i>) once it has no students left in it — move or promote them first.
When it's time to move every class up a year, use <a href="{{ route('promote_students') }}">Promote Students</a>.</p>
@endsection
