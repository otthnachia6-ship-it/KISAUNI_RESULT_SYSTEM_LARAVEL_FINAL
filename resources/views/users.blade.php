@extends('base')
@section('page_title', 'Users / Accounts')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <x-page_header icon="bi-person-badge" title="User Management" subtitle="Headmaster and class-teacher accounts" />
    <a href="{{ route('add_user') }}" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Add User</a>
</div>
<div class="card">
    <div class="table-responsive">
    <table class="table align-middle mb-0 table-stack">
        <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Class</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        @foreach($users as $u)
        <tr>
            <td class="fw-semibold stack-head">
                <div class="d-flex align-items-center gap-2">
                    @php
                        $photo = $user_photo_url($u->photo_path);
                        $parts = preg_split('/\s+/', trim($u->full_name));
                        $initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr($parts[1] ?? '', 0, 1) : ''));
                    @endphp
                    @if($photo)
                    <img src="{{ $photo }}" alt="{{ $u->full_name }}" class="user-avatar-img" style="width:32px;height:32px;">
                    @else
                    <div class="user-avatar" style="width:32px;height:32px;font-size:.72rem;">{{ $initials }}</div>
                    @endif
                    <span>{{ $u->full_name }}</span>
                </div>
            </td>
            <td data-label="Username">{{ $u->username }}</td>
            <td data-label="Role">
                @if($u->role === 'headmaster')
                    @if($u->title === 'Administrator')
                    <span class="badge" style="background:#6f42c1;">Administrator</span>
                    @else
                    <span class="badge bg-dark">Headmaster</span>
                    @endif
                @else
                <span class="badge bg-info text-dark">Class Teacher</span>
                @endif
            </td>
            <td data-label="Class">{{ $u->class_name ?: '-' }}</td>
            <td data-label="Status">
                @if($u->active)<span class="badge bg-success">Active</span>@else<span class="badge bg-secondary">Deactivated</span>@endif
                @if($u->must_change_password)<span class="badge bg-warning text-dark">Temp Password</span>@endif
            </td>
            <td class="text-end stack-actions">
                <a href="{{ route('edit_user', ['user_id' => $u->id]) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                @if($u->role !== 'headmaster')
                <form method="POST" action="{{ route('toggle_user', ['user_id' => $u->id]) }}" class="d-inline">
                    @csrf
                    <button class="btn btn-sm {{ $u->active ? 'btn-outline-danger' : 'btn-outline-success' }}">
                        <i class="bi {{ $u->active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>
                    </button>
                </form>
                @if($u->id != $session_user->id)
                <form method="POST" action="{{ route('delete_user', ['user_id' => $u->id]) }}" class="d-inline"
                      onsubmit="return appConfirm(this, 'Permanently delete the account \'{{ addslashes($u->username) }}\' ({{ addslashes($u->full_name) }})? This cannot be undone - if you just want to reuse this class for another teacher, Deactivate is usually safer.', {okText: 'Delete permanently', icon: 'bi-trash3-fill'});">
                    @csrf
                    <button class="btn btn-sm btn-danger" title="Permanently Delete Account">
                        <i class="bi bi-trash3-fill"></i>
                    </button>
                </form>
                @endif
                @endif
            </td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
