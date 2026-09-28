@extends('base')
@section('page_title', $mode == 'add' ? 'Add User' : 'Edit User')
@section('content')
<div class="card p-4" style="max-width:550px;">
    <h5 class="mb-3">{{ $mode == 'add' ? 'Add New Account' : 'Edit Account' }}</h5>
    <form method="POST" action="{{ $mode == 'add' ? route('add_user') : route('edit_user', ['user_id' => $user->id]) }}">
        @csrf
        <div class="mb-3">
            <label class="form-label">Full Name</label>
            <input type="text" class="form-control" name="full_name" required
                   value="{{ $user ? ($user['full_name'] ?? $user->full_name) : old('full_name') }}">
        </div>
        @if($mode == 'add')
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" name="username" value="{{ old('username') }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Temporary Password</label>
            <input type="password" class="form-control" name="password" required>
            <div class="form-text">The user will be required to set their own password on first login.</div>
        </div>
        @else
        <div class="mb-3">
            <label class="form-label">Username</label>
            <input type="text" class="form-control" name="username" required
                   value="{{ $user['username'] ?? $user->username }}">
            <div class="form-text">Changing this will change the login username for this account.</div>
        </div>
        <div class="mb-3">
            <label class="form-label">Reset Password (leave blank to keep current password)</label>
            <input type="password" class="form-control" name="password">
            <div class="form-text">Setting a new password here will require the user to change it on next login.</div>
        </div>
        @endif
        <div class="mb-3">
            <label class="form-label">Role</label>
            <select class="form-select" name="role" id="role" onchange="toggleClass()" required>
                <option value="class_teacher" @if($role_choice === 'class_teacher') selected @endif>Class Teacher</option>
                <option value="headmaster" @if($role_choice === 'headmaster') selected @endif>Headmaster</option>
                <option value="administrator" @if($role_choice === 'administrator') selected @endif>Administrator</option>
            </select>
            <div class="form-text">Headmaster na Administrator zina ruhusa sawa kabisa - ni jina/wadhifa tu unaotofautiana.</div>
        </div>
        <div class="mb-3" id="class_field">
            <label class="form-label">Class (if Class Teacher)</label>
            <select class="form-select" name="class_id">
                <option value="">-- None --</option>
                @php $currentClassId = $user ? ($user['class_id'] ?? $user->class_id) : null; @endphp
                @foreach($classes as $c)
                <option value="{{ $c->id }}" @if(strval($currentClassId) === strval($c->id)) selected @endif>{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-success">Save</button>
        <a href="{{ route('users') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
@section('scripts')
<script>
function toggleClass() {
    const role = document.getElementById('role').value;
    document.getElementById('class_field').style.display = role === 'class_teacher' ? 'block' : 'none';
}
document.addEventListener('DOMContentLoaded', toggleClass);
</script>
@endsection
