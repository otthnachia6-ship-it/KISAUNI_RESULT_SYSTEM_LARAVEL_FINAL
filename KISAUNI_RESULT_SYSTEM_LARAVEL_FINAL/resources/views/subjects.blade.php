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
        <div class="col-md-7">
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
                @foreach($classes as $c)<th class="text-center">{{ str_replace('Standard ', 'Std ', $c->name) }}</th>@endforeach
                @if($session_user->role === $ROLE_HEADMASTER)<th class="text-center">Delete</th>@endif
            </tr>
            </thead>
            <tbody>
            @foreach($all_subjects as $s)
            <tr>
                <td class="fw-semibold">{{ $s->name }}</td>
                @foreach($classes as $c)
                @php $isMapped = isset($mapping_set[$c->id . '-' . $s->id]); @endphp
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
                <td class="text-center">
                    <form method="POST" action="{{ route('delete_subject', ['subject_id' => $s->id]) }}"
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
<p class="text-muted small mt-2">Bofya tick-box kuongeza au kuondoa somo kwa darasa husika. Tumia icon ya trash kufuta somo kabisa (inawezekana tu kama hakuna alama zilizowekwa bado kwa somo hilo).</p>
@endsection
