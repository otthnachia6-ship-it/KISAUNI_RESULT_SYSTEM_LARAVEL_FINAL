@extends('base')
@section('page_title', 'Marks Entry')
@section('content')
<h5 class="mb-3">Select Examination and Class for Marks Entry</h5>
<p class="text-muted small">
    Only examinations for Academic Year <strong>{{ $current_academic_year }}</strong> (set in
    Settings) are shown here.
</p>

@if(empty($exams))
<div class="alert alert-warning" style="max-width:550px;">
    <i class="bi bi-exclamation-triangle me-1"></i>
    No examinations have been added yet for Academic Year <strong>{{ $current_academic_year }}</strong>.
    @if($session_user->role === $ROLE_HEADMASTER)
    Go to <a href="{{ route('examinations') }}">Examinations</a> to add one.
    @else
    Please ask the Headmaster to add one.
    @endif
</div>
@elseif($session_user->role === $ROLE_CLASS_TEACHER && count($classes) === 0)
<div class="alert alert-warning" style="max-width:550px;">
    You are not currently assigned to a class. Contact the Headmaster.
</div>
@else
<div class="card p-4" style="max-width:550px;">
    <form method="GET" action=""
          onsubmit="event.preventDefault(); window.location = '/marks/' + document.getElementById('exam_id').value + '/' + {{ $session_user->role === $ROLE_CLASS_TEACHER ? $classes[0]->id : "document.getElementById('class_id').value" }};">
        <div class="mb-3">
            <label class="form-label">Examination</label>
            <select id="exam_id" class="form-select" required>
                <option value="">-- Select Examination --</option>
                @foreach($exams as $e)
                <option value="{{ $e->id }}">{{ $e->exam_type }} - {{ $e->academic_year }}</option>
                @endforeach
            </select>
        </div>
        <div class="mb-3">
            <label class="form-label">Class</label>
            @if($session_user->role === $ROLE_CLASS_TEACHER)
                <input type="text" class="form-control" value="{{ $classes[0]->name }}" disabled>
            @else
                <select id="class_id" class="form-select" required>
                    <option value="">-- Select Class --</option>
                    @foreach($classes as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <button class="btn btn-success"><i class="bi bi-arrow-right-circle me-1"></i>Continue</button>
    </form>
</div>
@endif
@endsection
