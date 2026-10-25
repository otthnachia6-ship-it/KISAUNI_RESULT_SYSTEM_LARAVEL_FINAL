@extends('base')
@section('page_title', 'Subjects')
@section('content')
@php $isHm = $session_user->role === $ROLE_HEADMASTER; @endphp
<x-page_header icon="bi-book" title="Subject Management" subtitle="Manage subjects and the classes that teach them">
    <x-slot:actions>
        @if($isHm)
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal"><i class="bi bi-plus-lg me-1"></i>Add Subject</button>
        @endif
    </x-slot:actions>
</x-page_header>

<div class="row g-3 mb-3 subj-stats">
    <div class="col-12 col-sm-4"><div class="subj-stat"><span class="subj-stat-icon"><i class="bi bi-book"></i></span><div><div class="subj-stat-label">Total Subjects</div><div class="subj-stat-num">{{ $stats['subjects'] }}</div></div></div></div>
    <div class="col-12 col-sm-4"><div class="subj-stat"><span class="subj-stat-icon"><i class="bi bi-door-open"></i></span><div><div class="subj-stat-label">Classes</div><div class="subj-stat-num">{{ $stats['classes'] }}</div></div></div></div>
    <div class="col-12 col-sm-4"><div class="subj-stat"><span class="subj-stat-icon"><i class="bi bi-link-45deg"></i></span><div><div class="subj-stat-label">Subject Assignments</div><div class="subj-stat-num">{{ $stats['assignments'] }}</div></div></div></div>
</div>

<div class="card p-3 mb-3">
    <form method="GET" action="{{ route('subjects') }}" class="row g-2 align-items-center">
        <div class="col-12 col-md">
            <label class="visually-hidden" for="subj-q">Search subject name or code</label>
            <input type="search" id="subj-q" name="q" class="form-control" placeholder="Search subject name or code..." value="{{ $search }}">
        </div>
        <div class="col-8 col-md-auto">
            <label class="visually-hidden" for="subj-f">Filter</label>
            <select id="subj-f" name="f" class="form-select" onchange="this.form.submit()">
                <option value="">All Subjects</option>
                <option value="nocode" @selected($filter === 'nocode')>Without a code</option>
                <option value="unassigned" @selected($filter === 'unassigned')>Not assigned to any class</option>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-outline-secondary"><i class="bi bi-search me-1"></i>Search</button>
            @if($search !== '' || $filter !== '')<a href="{{ route('subjects') }}" class="btn btn-outline-secondary ms-1">Clear</a>@endif
        </div>
    </form>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table mb-0 align-middle table-stack tbl-clean">
            <thead>
            <tr><th style="width:3rem">#</th><th>Subject Name</th><th>Subject Code</th><th>Assigned Classes</th>@if($isHm)<th class="text-end">Actions</th>@endif</tr>
            </thead>
            <tbody>
            @forelse($all_subjects as $s)
            @php $ids = $assigned[$s->id] ?? []; @endphp
            <tr>
                <td data-label="#" class="cell-muted">{{ $loop->iteration }}</td>
                <td class="stack-head fw-semibold">{{ $s->name }}</td>
                <td data-label="Code">@if($s->subject_code)<code>{{ $s->subject_code }}</code>@else<span class="text-muted">&mdash;</span>@endif</td>
                <td data-label="Classes"><span class="subj-count">{{ count($ids) }} {{ count($ids) === 1 ? 'class' : 'classes' }}</span></td>
                @if($isHm)
                <td class="text-end stack-actions"><div class="acts">
                    <button type="button" class="btn btn-sm btn-manage" data-bs-toggle="modal" data-bs-target="#manageClassesModal"
                            data-subject-id="{{ $s->id }}" data-subject-name="{{ $s->name }}" data-subject-code="{{ $s->subject_code }}"
                            data-class-ids="{{ json_encode($ids) }}" data-protected-ids="{{ json_encode($protected[$s->id] ?? []) }}">
                        <i class="bi bi-people me-1"></i>Manage
                    </button>
                    <button type="button" class="ibtn" title="Edit" aria-label="Edit {{ $s->name }}" data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                            data-subject-id="{{ $s->id }}" data-subject-name="{{ $s->name }}" data-subject-code="{{ $s->subject_code }}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline" action="{{ route('delete_subject', ['subject_id' => $s->id]) }}"
                          onsubmit="return appConfirm(this, 'Delete subject \'{{ addslashes($s->name) }}\' completely from the system? This cannot be undone.', {okText: 'Delete', icon: 'bi-trash'});">
                        @csrf
                        <button class="ibtn ibtn-danger" title="Delete" aria-label="Delete {{ $s->name }}"><i class="bi bi-trash"></i></button>
                    </form>
                </div></td>
                @endif
            </tr>
            @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No subjects found{{ $search !== '' ? ' for "' . $search . '"' : '' }}.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">Showing {{ count($all_subjects) }} of {{ $stats['subjects'] }} subjects</div>
</div>
<p class="text-muted small mt-2">A subject cannot be deleted, or removed from a class, once marks have been recorded for it. Removing a subject from a class never deletes student results.</p>

@if($isHm)
{{-- Add Subject --}}
<div class="modal fade" id="addSubjectModal" tabindex="-1" aria-labelledby="addSubjectTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="{{ route('subjects') }}" class="modal-content" data-once>
            @csrf
            <input type="hidden" name="action" value="add_subject">
            <div class="modal-header"><h5 class="modal-title" id="addSubjectTitle">Add New Subject</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="add-subject-name">Subject Name <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" name="name" id="add-subject-name" maxlength="191" required placeholder="e.g. Science and Technology"></div>
                <div class="mb-3"><label class="form-label" for="add-subject-code">Subject Code</label>
                    <input type="text" class="form-control" name="subject_code" id="add-subject-code" maxlength="20" autocomplete="off" placeholder="e.g. 114">
                    <div class="form-text">Optional. Letters and numbers only.</div></div>
                <div class="mb-0"><div class="form-label">Assign to Classes</div>
                    <div class="class-picker">@include('partials.class_picker', ['groups' => $groups, 'idPrefix' => 'add-c'])</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Subject</button></div>
        </form>
    </div>
</div>

{{-- Manage Classes --}}
<div class="modal fade" id="manageClassesModal" tabindex="-1" aria-labelledby="manageClassesTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="POST" action="{{ route('subjects') }}" class="modal-content" data-once>
            @csrf
            <input type="hidden" name="action" value="set_class_assignments">
            <input type="hidden" name="subject_id" id="manage-subject-id">
            <div class="modal-header"><div><h5 class="modal-title" id="manageClassesTitle">Manage Classes</h5>
                <div class="small text-muted" id="manage-subject-sub"></div></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <label class="visually-hidden" for="manage-search">Search class</label>
                <input type="search" class="form-control mb-3" id="manage-search" placeholder="Search class..." autocomplete="off">
                <div class="class-picker" id="manage-picker">@include('partials.class_picker', ['groups' => $groups, 'idPrefix' => 'mg-c'])</div>
                <div class="form-text mt-2"><i class="bi bi-lock me-1"></i>A class with recorded marks stays assigned and is locked.</div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-save me-1"></i>Save Changes</button></div>
        </form>
    </div>
</div>

{{-- Edit Subject --}}
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-labelledby="editSubjectTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('subjects') }}" class="modal-content" data-once>
            @csrf
            <input type="hidden" name="action" value="update_subject">
            <input type="hidden" name="subject_id" id="edit-subject-id">
            <div class="modal-header"><h5 class="modal-title" id="editSubjectTitle">Edit Subject</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label" for="edit-subject-name">Subject Name</label>
                    <input type="text" class="form-control" name="name" id="edit-subject-name" required maxlength="191"></div>
                <div class="mb-0"><label class="form-label" for="edit-subject-code">Subject Code</label>
                    <input type="text" class="form-control" name="subject_code" id="edit-subject-code" maxlength="20" autocomplete="off">
                    <div class="form-text">A short code for the subject (for example 104). Leave empty if the subject has no code.</div></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary">Save Changes</button></div>
        </form>
    </div>
</div>
@endif
@endsection

@section('scripts')
@if($isHm)
<script>
(function () {
    // Edit modal
    var edit = document.getElementById('editSubjectModal');
    edit.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget; if (!b) { return; }
        document.getElementById('edit-subject-id').value = b.getAttribute('data-subject-id') || '';
        document.getElementById('edit-subject-name').value = b.getAttribute('data-subject-name') || '';
        document.getElementById('edit-subject-code').value = b.getAttribute('data-subject-code') || '';
    });

    // Manage Classes modal
    var mg = document.getElementById('manageClassesModal');
    var picker = document.getElementById('manage-picker');
    mg.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget; if (!b) { return; }
        var ids = JSON.parse(b.getAttribute('data-class-ids') || '[]').map(String);
        var locked = JSON.parse(b.getAttribute('data-protected-ids') || '[]').map(String);
        document.getElementById('manage-subject-id').value = b.getAttribute('data-subject-id');
        var code = b.getAttribute('data-subject-code');
        document.getElementById('manage-subject-sub').textContent = b.getAttribute('data-subject-name') + (code ? ' \u2014 Code ' + code : '');
        document.getElementById('manage-search').value = '';
        picker.querySelectorAll('.js-lock-keep').forEach(function (n) { n.remove(); });
        picker.querySelectorAll('.class-pick-item').forEach(function (l) { l.hidden = false; l.classList.remove('is-locked'); });
        picker.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.checked = ids.indexOf(cb.value) !== -1;
            cb.disabled = false;
            if (cb.checked && locked.indexOf(cb.value) !== -1) {
                // a disabled box is not submitted, so keep the class via a hidden field
                cb.disabled = true; cb.closest('.class-pick-item').classList.add('is-locked');
                var h = document.createElement('input'); h.type = 'hidden'; h.name = 'class_ids[]'; h.value = cb.value; h.className = 'js-lock-keep';
                cb.closest('.class-pick-item').appendChild(h);
            }
        });
    });
    document.getElementById('manage-search').addEventListener('input', function () {
        var q = this.value.trim().toLowerCase();
        picker.querySelectorAll('.class-pick-item').forEach(function (l) { l.hidden = q !== '' && l.getAttribute('data-class-name').indexOf(q) === -1; });
    });

    // "Select all" per standard
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('[data-pick-all]'); if (!btn) { return; }
        var boxes = Array.prototype.filter.call(btn.closest('[data-pick-group]').querySelectorAll('input[type=checkbox]'), function (c) { return !c.disabled && !c.closest('.class-pick-item').hidden; });
        var all = boxes.every(function (c) { return c.checked; });
        boxes.forEach(function (c) { c.checked = !all; });
    });

    // block double submission
    document.querySelectorAll('form[data-once]').forEach(function (f) {
        f.addEventListener('submit', function () {
            var s = f.querySelector('button:not([type=button]):not(.btn-close)');
            if (s) { setTimeout(function () { s.disabled = true; }, 0); }
        });
    });
})();
</script>
@endif
@endsection
