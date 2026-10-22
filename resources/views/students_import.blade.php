@extends('base')
@section('page_title', 'Import Students from ZEMS')
@section('content')
<x-page_header title="Import Students from ZEMS" subtitle="Register many pupils at once from a ZEMS Excel file">
    <x-slot:actions>
        <a href="{{ route('students') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Students</a>
    </x-slot:actions>
</x-page_header>

<div class="card p-4 mb-3" style="max-width:640px;">
    <form method="POST" action="{{ route('students_import_upload') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label" for="class_id">Class</label>
            @if($session_user->role === $ROLE_CLASS_TEACHER)
            <input type="text" class="form-control" value="{{ $classes->first()->name ?? '' }}" disabled>
            @else
            <select id="class_id" name="class_id" class="form-select" required>
                <option value="">-- Select Class --</option>
                @foreach($classes as $c)
                <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            @endif
        </div>
        <div class="mb-3">
            <label class="form-label" for="file">ZEMS Excel file (.xls)</label>
            <input type="file" id="file" name="file" class="form-control" accept=".xls,.csv,application/vnd.ms-excel,text/csv" required>
            <div class="form-text">
                Use any subject file exported from ZEMS. It lists every pupil of the standard.
                On the next page you tick the pupils who belong to this class. Nothing is saved before you confirm.
            </div>
        </div>
        <button class="btn btn-success"><i class="bi bi-arrow-right-circle me-1"></i>Continue</button>
    </form>
</div>
@endsection
