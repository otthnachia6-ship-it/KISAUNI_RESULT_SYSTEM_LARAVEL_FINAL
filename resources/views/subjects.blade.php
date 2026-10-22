@extends('base')
@section('page_title', 'Subjects')
@section('content')
<x-page_header icon="bi-book" title="Subject Management" subtitle="Add, edit or remove subjects taught in each class" />

@if($session_user->role === $ROLE_HEADMASTER)
<div class="card p-3 mb-3">
    <form method="POST" action="{{ route('subjects') }}" class="row g-2 align-items-end">
        @csrf
        <input type="hidden" name="action" value="add_subject">
        <div class="col-md-3">
            <label class="form-label small">New Subject Name</label>
            <input type="text" name="name" class="form-control form-control-sm" required>
        </div>
        <div class="col-md-2">
            <label class="form-label small">ZEMS Code</label>
            <input type="text" name="subject_code" class="form-control form-control-sm" maxlength="20" placeholder="e.g. 104" autocomplete="off">
        </div>
        <div class="col-md-5">
            <label class="form-label small">Which Classes Teach This Subject?</label>
            <div class="d-flex flex-wrap gap-2">
                @foreach($classes as $c)
                <div class="form-check form-check-inline">
                    <input class="form-check-input" type="checkbox" name="class_ids[]" value="{{ $c->id }}" id="nc{{ $c->id }}">
                    <label class="form-check-label small" for="nc{{ $c->id }}">{{ $c->name }}</label>
                </div>
                @endforeach
            </div>
        </div>
        <div class="col-md-2">
            <button class="btn btn-success btn-sm w-100">Add Subject</button>
        </div>
    </form>
</div>
@endif

<div class="card">
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
            <tr>
                <th>Subject</th>
                <th>Code</th>
                @foreach($classes as $c)<th class="text-center">{{ str_replace('Standard ', 'Std ', $c->name) }}</th>@endforeach
                @if($session_user->role === $ROLE_HEADMASTER)<th class="text-center">Actions</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach($all_subjects as $s)
            <tr>
                <td class="fw-semibold">{{ $s->name }}</td>
                <td>@if($s->subject_code)<code>{{ $s->subject_code }}</code>@else<span class="text-muted">&mdash;</span>@endif</td>
                @foreach($classes as $c)
                @php $isMapped = isset($mapping_set[$c->id][$s->id]); @endphp
                <td class="text-center">
                    @if($session_user->role === $ROLE_HEADMASTER)
                    <form method="POST" action="{{ route('subjects') }}" class="d-inline-block">
                        @csrf
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="class_id" value="{{ $c->id }}">
                        <input type="hidden" name="subject_id" value="{{ $s->id }}">
                        <button class="subject-toggle {{ $isMapped ? 'checked' : '' }}" title="{{ $isMapped ? 'Assigned - click to remove' : 'Not assigned - click to add' }}">
                            <i class="bi bi-check-lg"></i>
                        </button>
                    </form>
                    @else
                        @if($isMapped)<i class="bi bi-check-lg text-success"></i>@endif
                    @endif
                </td>
                @endforeach
                @if($session_user->role === $ROLE_HEADMASTER)
                <td class="text-center text-nowrap">
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1" title="Edit name / code"
                            data-bs-toggle="modal" data-bs-target="#editSubjectModal"
                            data-subject-id="{{ $s->id }}" data-subject-name="{{ $s->name }}" data-subject-code="{{ $s->subject_code }}">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <form method="POST" class="d-inline-block" action="{{ route('delete_subject', ['subject_id' => $s->id]) }}"
                          onsubmit="return appConfirm(this, 'Delete subject \'{{ addslashes($s->name) }}\' completely from the system? This cannot be undone.', {okText: 'Delete', icon: 'bi-trash'});">
                        @csrf
                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                    </form>
                </td>
                @endif
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@if($session_user->role === $ROLE_HEADMASTER)
<div class="modal fade" id="editSubjectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" action="{{ route('subjects') }}" class="modal-content">
            @csrf
            <input type="hidden" name="action" value="update_subject">
            <input type="hidden" name="subject_id" id="edit-subject-id">
            <div class="modal-header">
                <h5 class="modal-title">Edit Subject</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="edit-subject-name">Subject Name</label>
                    <input type="text" class="form-control" name="name" id="edit-subject-name" required maxlength="191">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="edit-subject-code">ZEMS Code</label>
                    <input type="text" class="form-control" name="subject_code" id="edit-subject-code" maxlength="20" autocomplete="off">
                    <div class="form-text">The code used in ZEMS result files (for example 104). Leave empty if the subject has no code.</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-success">Save Changes</button>
            </div>
        </form>
    </div>
</div>
@endif
<p class="text-muted small mt-2">Bofya tick-box kuongeza au kuondoa somo kwa darasa husika. Tumia icon ya trash kufuta somo kabisa (inawezekana tu kama hakuna alama zilizowekwa bado kwa somo hilo).</p>
@endsection

@section('scripts')
@if($session_user->role === $ROLE_HEADMASTER)
<script>
(function () {
    var modal = document.getElementById('editSubjectModal');
    if (!modal) { return; }
    modal.addEventListener('show.bs.modal', function (ev) {
        var b = ev.relatedTarget;
        if (!b) { return; }
        document.getElementById('edit-subject-id').value = b.getAttribute('data-subject-id') || '';
        document.getElementById('edit-subject-name').value = b.getAttribute('data-subject-name') || '';
        document.getElementById('edit-subject-code').value = b.getAttribute('data-subject-code') || '';
    });
})();
</script>
@endif
@endsection
