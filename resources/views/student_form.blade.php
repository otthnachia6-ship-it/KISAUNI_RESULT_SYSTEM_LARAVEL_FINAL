@extends('base')
@section('page_title', $mode == 'add' ? 'Register Student' : 'Edit Student')
@section('content')
<x-page_header :title="$mode == 'add' ? 'Register Student' : 'Edit Student'" :subtitle="$mode == 'add' ? 'Add one student to a class' : 'Update this student\'s details'" />
<div class="card p-4 student-form-card">
    <form method="POST" action="{{ $mode == 'add' ? route('add_student') : route('edit_student', ['student_id' => $student->id]) }}" data-once>
        @csrf
        @php
            $currentGender = $student ? ($student['gender'] ?? $student->gender) : old('gender');
            $isConfirmed = $student ? ($student['gender_confirmed'] ?? $student->gender_confirmed) : false;
            $currentClassId = $student ? ($student['class_id'] ?? $student->class_id) : old('class_id');
        @endphp
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="reg_no">Reg No <span class="text-danger">*</span></label>
                <input type="text" id="reg_no" class="form-control" name="reg_no" maxlength="50" autocomplete="off" required
                       value="{{ old('reg_no', $student ? ($student['reg_no'] ?? $student->reg_no) : '') }}">
                <div class="form-text">The school's registration number. It must be unique.</div>
            </div>
            <div class="col-md-6">
                <label class="form-label" for="class_id">Class @if($session_user->role !== $ROLE_CLASS_TEACHER)<span class="text-danger">*</span>@endif</label>
                @if($session_user->role === $ROLE_CLASS_TEACHER)
                    <input type="text" id="class_id" class="form-control" value="{{ count($classes) > 0 ? $classes[0]->name : 'No class assigned' }}" disabled>
                    <input type="hidden" name="class_id" value="{{ count($classes) > 0 ? $classes[0]->id : '' }}">
                    <div class="form-text">You can only register or edit students in your own class.</div>
                @else
                    <select id="class_id" class="form-select" name="class_id" required>
                        <option value="">-- Select Class --</option>
                        @foreach($classes as $c)
                        <option value="{{ $c->id }}" @if(strval($currentClassId) === strval($c->id)) selected @endif>{{ $c->name }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div class="col-12">
                <label class="form-label" for="full_name">Student's Full Name <span class="text-danger">*</span></label>
                <input type="text" id="full_name" class="form-control" name="full_name" required autocomplete="off"
                       value="{{ $student ? ($student['full_name'] ?? $student->full_name) : old('full_name') }}">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="gender">Gender</label>
                <select id="gender" class="form-select" name="gender">
                    <option value="">-- Select --</option>
                    <option value="Male" @if($currentGender == 'Male') selected @endif>Male</option>
                    <option value="Female" @if($currentGender == 'Female') selected @endif>Female</option>
                </select>
                <div id="gender-hint" class="form-text"></div>
            </div>
            <div class="col-md-6 d-flex align-items-start">
                <div class="form-check student-form-confirm">
                    <input class="form-check-input" type="checkbox" id="gender_confirmed" name="gender_confirmed" @if($isConfirmed) checked @endif>
                    <label class="form-check-label" for="gender_confirmed">Confirm gender</label>
                </div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mt-4 pt-3 border-top">
            <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>{{ $mode == 'add' ? 'Register Student' : 'Save Changes' }}</button>
            <a href="{{ route('students') }}" class="btn btn-outline-secondary">Cancel</a>
        </div>
    </form>
</div>
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
