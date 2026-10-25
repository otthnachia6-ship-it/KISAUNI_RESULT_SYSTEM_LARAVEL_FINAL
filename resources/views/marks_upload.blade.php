@extends('base')
@section('page_title', 'Upload Exam Results')
@section('content')
<x-page_header title="Upload Exam Results" subtitle="Fill the marks of a subject in Excel and upload them">
    <x-slot:actions>
        @if($cls && $selected_exam)
        <a href="{{ route('marks', ['exam_id' => $selected_exam, 'class_id' => $cls->id]) }}" class="btn btn-outline-secondary"><i class="bi bi-table me-1"></i>Marks sheet</a>
        @endif
    </x-slot:actions>
</x-page_header>

@if(!$cls)
<div class="alert alert-warning" style="max-width:600px;">You are not currently assigned to a class. Contact the Headmaster.</div>
@elseif($exams->isEmpty())
<div class="alert alert-warning" style="max-width:600px;">
    <i class="bi bi-exclamation-triangle me-1"></i>
    No examinations have been added yet for Academic Year <strong>{{ $current_academic_year }}</strong>. Please ask the Headmaster to add one.
</div>
@else
<div class="card p-4 mb-3" style="max-width:760px;">
    <form method="GET" action="{{ route('marks_upload') }}" class="row g-2">
        <div class="col-12"><label class="form-label small mb-1">Class</label>
            <input type="text" class="form-control" value="{{ $cls->name }}" disabled></div>
        <div class="col-md-6">
            <label class="form-label small mb-1" for="exam">Examination</label>
            <select id="exam" name="exam" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select Examination --</option>
                @foreach($exams as $e)
                <option value="{{ $e->id }}" @selected((int) $e->id === (int) $selected_exam)>{{ $e->exam_type }} - {{ $e->academic_year }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label small mb-1" for="subject">Subject</label>
            <select id="subject" name="subject" class="form-select" onchange="this.form.submit()">
                <option value="">-- Select Subject --</option>
                @foreach($subjects as $s)
                <option value="{{ $s->id }}" @selected((int) $s->id === (int) $selected_subject)>{{ $s->name }}@if($s->subject_code) ({{ $s->subject_code }})@endif</option>
                @endforeach
            </select>
        </div>
    </form>
</div>

@if($selected_exam && $selected_subject)
    @if($locked)
    <div class="alert alert-warning" style="max-width:760px;"><i class="bi bi-lock me-1"></i>
        Marks for this class are already <strong>{{ str_replace('_', ' ', $status) }}</strong> and cannot be changed by upload.</div>
    @else
    <div class="row g-3" style="max-width:760px;">
        <div class="col-md-6">
            <div class="card p-4 h-100">
                <h6><span class="badge bg-primary me-1">1</span> Download the marks template</h6>
                <p class="text-muted small">It already lists the pupils of your class. Give it to the subject teacher, who only fills the yellow Marks column.</p>
                <a href="{{ route('marks_template', ['exam_id' => $selected_exam, 'subject_id' => $selected_subject]) }}" class="btn btn-outline-secondary"><i class="bi bi-download me-1"></i>Download template (.xlsx)</a>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-4 h-100">
                <h6><span class="badge bg-primary me-1">2</span> Upload the filled file</h6>
                <form method="POST" action="{{ route('marks_upload_post') }}" enctype="multipart/form-data" data-once>
                    @csrf
                    <input type="hidden" name="exam_id" value="{{ $selected_exam }}">
                    <input type="hidden" name="subject_id" value="{{ $selected_subject }}">
                    <label class="form-label small mb-1" for="file">Excel file (.xlsx)</label>
                    <input type="file" id="file" name="file" class="form-control mb-3" accept=".xlsx" required>
                    <button class="btn btn-primary"><i class="bi bi-upload me-1"></i>Upload and check</button>
                </form>
            </div>
        </div>
    </div>
    <p class="text-muted small mt-3" style="max-width:760px;">You will see a preview first. After saving, open the marks sheet and press Submit when every subject is complete.</p>
    @endif
@else
<p class="text-muted">Choose the examination and the subject to get the template.</p>
@endif
@endif
@endsection

@section('scripts')
<script>
document.querySelectorAll('form[data-once]').forEach(function (f) {
    f.addEventListener('submit', function () {
        var b = f.querySelector('button:not([type=button])');
        if (b) { setTimeout(function () { b.disabled = true; }, 0); }
    });
});
</script>
@endsection
