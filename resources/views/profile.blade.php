@extends('base')
@section('page_title', 'Profile')
@section('content')
<div class="card p-4" style="max-width:500px;">
    <div class="text-center mb-3">
        @php $photo = $user_photo_url($user->photo_path); @endphp
        @if($photo)
        <img src="{{ $photo }}" alt="Profile photo" class="profile-photo-lg">
        @else
        <i class="bi bi-person-circle" style="font-size:4rem; color:#2563eb;"></i>
        @endif
        <h5 class="mt-2 mb-0">{{ $user->full_name }}</h5>
        <span class="badge bg-secondary">{{ $user->role === 'headmaster' ? ($user->title ?: 'Headmaster') : 'Class Teacher' }}</span>
    </div>

    <form method="POST" action="{{ route('profile') }}" enctype="multipart/form-data" class="mb-3">
        @csrf
        <input type="hidden" name="action" value="upload_photo">
        <label class="form-label small">{{ $photo ? 'Change' : 'Upload' }} Profile Photo (PNG/JPG, up to 2MB)</label>
        <div class="d-flex gap-2">
            <input type="file" name="photo" accept=".png,.jpg,.jpeg" class="form-control form-control-sm" required>
            <button class="btn btn-sm btn-success text-nowrap">Upload</button>
        </div>
    </form>
    @if($photo)
    <form method="POST" action="{{ route('profile') }}" class="mb-3">
        @csrf
        <input type="hidden" name="action" value="remove_photo">
        <button class="btn btn-sm btn-outline-danger" onclick="return appConfirm(this.form, 'Remove your profile photo?');">
            <i class="bi bi-trash me-1"></i>Remove Photo
        </button>
    </form>
    @endif

    <table class="table table-sm">
        <tr><th>Username</th><td>{{ $user->username }}</td></tr>
        @if($cls)<tr><th>Assigned Class</th><td>{{ $cls->name }}</td></tr>@endif
        <tr><th>Status</th><td>{{ $user->active ? 'Active' : 'Deactivated' }}</td></tr>
        <tr><th>Joined</th><td>{{ $user->created_at }}</td></tr>
    </table>
    <a href="{{ route('change_password') }}" class="btn btn-outline-success btn-sm">Change Password</a>
</div>
@endsection
