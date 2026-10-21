@extends('base')
@section('page_title', 'Users / Accounts')
@section('content')
<x-page_header icon="bi-person-badge" title="User Management" subtitle="Headmaster and class-teacher accounts">
    <x-slot:actions>
        <a href="{{ route('add_user') }}" class="btn btn-success"><i class="bi bi-person-plus me-1"></i>Add User</a>
    </x-slot:actions>
</x-page_header>
<div class="card">
    <div class="table-responsive">
    <table class="table align-middle mb-0 table-stack tbl-clean">
        <thead><tr><th>Name</th><th>Role</th><th>Class</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        @foreach($users as $u)
        <tr>
            <td class="stack-head">
                @php
                    $photo = $user_photo_url($u->photo_path);
                    $parts = preg_split('/\s+/', trim($u->full_name));
                    $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (count($parts) > 1 ? mb_substr($parts[1] ?? '', 0, 1) : ''));
                @endphp
                <div class="person">
                    <span class="person-av">@if($photo)<img src="{{ $photo }}" alt="{{ $u->full_name }}">@else{{ $initials }}@endif</span>
                    <div>
                        <div class="person-name">{{ $u->full_name }}</div>
                        <div class="person-sub">{{ $u->username }}</div>
                    </div>
                </div>
            </td>
            <td data-label="Role">
                @if($u->role === 'headmaster')
                    @if($u->title === 'Administrator')
                    <span class="role-tag role-tag--admin">Administrator</span>
                    @else
                    <span class="role-tag role-tag--head">Headmaster</span>
                    @endif
                @else
                <span class="role-tag role-tag--teacher">Class Teacher</span>
                @endif
            </td>
            <td data-label="Class" class="cell-muted">{{ $u->class_name ?: '-' }}</td>
            <td data-label="Status">
                @if($u->active)<span class="chip chip--ok">Active</span>@else<span class="chip chip--off">Deactivated</span>@endif
                @if($u->must_change_password)<span class="chip chip--warn">Temp password</span>@endif
            </td>
            <td class="text-end stack-actions"><div class="acts">
                <a href="{{ route('edit_user', ['user_id' => $u->id]) }}" class="ibtn" title="Edit" aria-label="Edit"><i class="bi bi-pencil"></i></a>
                @if($u->role !== 'headmaster')
                <form method="POST" action="{{ route('toggle_user', ['user_id' => $u->id]) }}" class="d-inline">
                    @csrf
                    <button class="ibtn {{ $u->active ? 'ibtn-danger' : 'ibtn-ok' }}" title="{{ $u->active ? 'Deactivate' : 'Activate' }}" aria-label="{{ $u->active ? 'Deactivate' : 'Activate' }}">
                        <i class="bi {{ $u->active ? 'bi-slash-circle' : 'bi-check-circle' }}"></i>
                    </button>
                </form>
                @if($u->id != $session_user->id)
                <form method="POST" action="{{ route('delete_user', ['user_id' => $u->id]) }}" class="d-inline"
                      onsubmit="return appConfirm(this, 'Permanently delete the account \'{{ addslashes($u->username) }}\' ({{ addslashes($u->full_name) }})? This cannot be undone - if you just want to reuse this class for another teacher, Deactivate is usually safer.', {okText: 'Delete permanently', icon: 'bi-trash3-fill'});">
                    @csrf
                    <button class="ibtn ibtn-danger" title="Permanently delete account" aria-label="Permanently delete account">
                        <i class="bi bi-trash3-fill"></i>
                    </button>
                </form>
                @endif
                @endif
            </div></td>
        </tr>
        @endforeach
        </tbody>
    </table>
    </div>
</div>
@endsection
