@extends('base')
@section('page_title', 'Upload Students from Excel')
@section('content')
<x-page_header icon="bi-file-earmark-excel" title="Upload Students from Excel" subtitle="Register many students at once with the Excel template">
    <x-slot:actions>
        <a href="{{ route('students') }}" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back to Students</a>
    </x-slot:actions>
</x-page_header>

<div class="row g-3" style="max-width:900px;">
    <div class="col-md-6">
        <div class="card p-4 h-100">
            <h6><span class="badge bg-primary me-1">1</span> Download the template</h6>
            <p class="text-muted small">One row per pupil: Reg No, Full Name and Gender. The file explains how to fill it.</p>
            <a href="{{ route('students_template') }}" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Download template (.xlsx)</a>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card p-4 h-100">
            <h6><span class="badge bg-primary me-1">2</span> Upload the filled file</h6>
            <form method="POST" action="{{ route('students_upload_post') }}" enctype="multipart/form-data" data-once>
                @csrf
                @if($session_user->role === $ROLE_CLASS_TEACHER)
                    @if($classes->isEmpty())
                    <div class="alert alert-warning">You are not currently assigned to a class.</div>
                    @else
                    <label class="form-label small mb-1" for="cls-label">Class</label>
                    <input type="text" id="cls-label" class="form-control mb-2" value="{{ $classes[0]->name }}" disabled>
                    @endif
                @else
                <label class="form-label small mb-1" for="class_id">Class</label>
                <select id="class_id" name="class_id" class="form-select mb-2" required>
                    <option value="">-- Select Class --</option>
                    @foreach($classes as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
                @endif
                <label class="form-label small mb-1" for="file">Excel file (.xlsx)</label>
                <input type="file" id="file" name="file" class="form-control mb-3" accept=".xlsx" required>
                <button class="btn btn-primary" @if($session_user->role === $ROLE_CLASS_TEACHER && $classes->isEmpty()) disabled @endif><i class="bi bi-upload me-1"></i>Upload and check</button>
            </form>
        </div>
    </div>
</div>
<p class="text-muted small mt-3">Nothing is saved yet after uploading. You will see a preview first, and nothing is saved if any row has a problem.</p>
@endsection
