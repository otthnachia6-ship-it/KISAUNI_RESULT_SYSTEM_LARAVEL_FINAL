@extends('base')
@section('page_title', 'Classes')

@section('content')
<x-page_header icon="bi-door-open" title="Class Management" subtitle="Streams and class-teacher assignments">
    <x-slot:actions>
        <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#addClassCard" aria-expanded="false" aria-controls="addClassCard">
            <i class="bi bi-plus-lg me-1"></i>Add Class
        </button>
    </x-slot:actions>
</x-page_header>

<!-- ---------- Add a class (opens from the "Add Class" button) ---------- -->
<div class="collapse mb-3" id="addClassCard">
    <div class="card p-3 p-md-4">
        <div class="panel-head">
            <div>
                <div class="panel-eyebrow">New class</div>
                <h2 class="panel-title">Add a class or stream</h2>
            </div>
        </div>
        <form method="POST" action="{{ route('add_class') }}" class="row g-3">
            @csrf
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="newStandard">Standard</label>
                <select id="newStandard" name="standard" class="form-select" required>
                    @for($i = 1; $i <= $num_standards; $i++)
                        <option value="{{ $i }}">Standard {{ $ordinal_words[$i] }}</option>
                    @endfor
                </select>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="newStream">Stream</label>
                <input id="newStream" type="text" name="stream" class="form-control" placeholder="e.g. C" maxlength="10" required>
            </div>
            <div class="col-sm-6 col-lg-3">
                <label class="form-label small mb-1" for="newTeacher">Class Teacher <span class="text-muted">(optional)</span></label>
                <select id="newTeacher" name="teacher_id" class="form-select">
                    <option value="">Choose teacher</option>
                    @foreach($teachers as $t)
                        <option value="{{ $t->id }}">{{ $t->full_name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-sm-3 col-lg-2">
                <label class="form-label small mb-1" for="newYear">Academic Year</label>
                <input id="newYear" type="text" class="form-control" value="{{ $academic_year }}" readonly>
            </div>
            <div class="col-sm-3 col-lg-1">
                <label class="form-label small mb-1" for="newStatus">Status</label>
                <input id="newStatus" type="text" class="form-control" value="Active" readonly>
            </div>
            <div class="col-12">
                <div class="small text-muted">
                    It will be created as <strong id="newClassPreview">Standard One &hellip;</strong>.
                    The standard subjects are added automatically; the academic year comes from Settings.
                </div>
            </div>
            <div class="col-12 d-flex gap-2 flex-wrap">
                <button class="btn btn-success"><i class="bi bi-plus-lg me-1"></i>Add Class</button>
                <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#addClassCard">Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- ---------- Classes table ---------- -->
<div class="card">
    <div class="tbl-tools">
        <div class="tbl-search">
            <i class="bi bi-search"></i>
            <input type="search" class="form-control" placeholder="Search class or teacher..." aria-label="Search classes"
                   data-filter-table="#classesTable" data-noun="classes" data-count="#classCount">
        </div>
        <span class="small text-muted" id="classCount">Showing {{ count($classes) }} of {{ count($classes) }} classes</span>
    </div>
    <div class="table-responsive">
        <table id="classesTable" class="table align-middle mb-0 table-stack tbl-clean">
            <thead>
                <tr><th>Class</th><th>Class Teacher</th><th>Students</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            @foreach($classes as $c)
                <tr data-search="{{ strtolower($c->name . ' ' . ($c->teacher_name ?? '')) }}">
                    <td class="fw-semibold stack-head">{{ $c->name }}</td>
                    <td data-label="Class Teacher">
                        @if($c->teacher_name)
                            @php
                                $tp = preg_split('/\s+/', trim($c->teacher_name));
                                $tIni = mb_strtoupper(mb_substr($tp[0] ?? '', 0, 1) . (count($tp) > 1 ? mb_substr($tp[1], 0, 1) : ''));
                            @endphp
                            <div class="person"><span class="person-av" aria-hidden="true">{{ $tIni }}</span><span class="person-name">{{ $c->teacher_name }}</span></div>
                        @else
                            <span class="chip chip--off">Not assigned</span>
                        @endif
                    </td>
                    <td data-label="Students" class="cell-muted">{{ $c->student_count }}</td>
                    <td data-label="Status"><span class="chip chip--ok">Active</span></td>
                    <td class="text-end stack-actions"><div class="acts">
                        <a href="{{ route('students', ['class_id' => $c->id]) }}" class="ibtn" title="View students" aria-label="View students in {{ $c->name }}"><i class="bi bi-eye"></i></a>
                        <button type="button" class="ibtn" title="Edit class teacher" aria-label="Edit class teacher of {{ $c->name }}"
                                data-bs-toggle="modal" data-bs-target="#editClassModal"
                                data-class-id="{{ $c->id }}" data-class-name="{{ $c->name }}" data-teacher-id="{{ $c->teacher_id }}">
                            <i class="bi bi-pencil"></i>
                        </button>
                        @if($c->student_count == 0)
                        <form method="POST" action="{{ route('delete_class', ['class_id' => $c->id]) }}" class="d-inline"
                              onsubmit="return appConfirm(this, 'Remove empty class {{ addslashes($c->name) }}? This cannot be undone.', {okText: 'Remove', icon: 'bi-trash'});">
                            @csrf
                            <button class="ibtn ibtn-danger" title="Remove empty class" aria-label="Remove {{ $c->name }}"><i class="bi bi-trash"></i></button>
                        </form>
                        @else
                        <span class="ibtn is-disabled" title="A class with students cannot be removed" aria-disabled="true"><i class="bi bi-trash"></i></span>
                        @endif
                    </div></td>
                </tr>
            @endforeach
                <tr class="filter-empty d-none"><td colspan="5" class="text-center text-muted py-4">No classes match your search.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-3 mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Use the pencil to change a class teacher (or choose "None" to leave the class without one). Only a class with no students can be removed.
</p>
@endsection

@section('scripts')
<!-- Edit class teacher (this sits at the end of the page so the dialog is never clipped by the page layout) -->
<div class="modal fade" id="editClassModal" tabindex="-1" aria-labelledby="editClassTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="{{ route('classes') }}">
                @csrf
                <div class="modal-header">
                    <h5 class="modal-title" id="editClassTitle">Edit class</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="class_id" id="editClassId">
                    <p class="small text-muted mb-3">Choose who teaches <strong id="editClassName"></strong>.</p>
                    <label class="form-label small mb-1" for="editTeacher">Class Teacher</label>
                    <select name="teacher_id" id="editTeacher" class="form-select">
                        <option value="">None</option>
                        @foreach($teachers as $t)
                            <option value="{{ $t->id }}">{{ $t->full_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var modal = document.getElementById("editClassModal");
    if (modal) {
        modal.addEventListener("show.bs.modal", function (ev) {
            var b = ev.relatedTarget;
            if (!b) { return; }
            document.getElementById("editClassId").value = b.dataset.classId;
            document.getElementById("editClassName").textContent = b.dataset.className;
            document.getElementById("editTeacher").value = b.dataset.teacherId || "";
        });
    }
    var words = @json($ordinal_words);
    var std = document.getElementById("newStandard"), stream = document.getElementById("newStream"), pv = document.getElementById("newClassPreview");
    function preview() {
        var s = (stream.value || "").trim().toUpperCase();
        pv.textContent = "Standard " + (words[std.value] || std.value) + " " + (s || "\u2026");
    }
    std.addEventListener("change", preview);
    stream.addEventListener("input", preview);
    preview();
})();
</script>
@endsection
