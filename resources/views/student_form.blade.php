@extends('base')
@section('page_title', $mode == 'add' ? 'Register Student' : 'Edit Student')
@section('content')
<div class="card p-4" style="max-width:600px;">
    <h5 class="mb-3">{{ $mode == 'add' ? 'Register New Student' : 'Edit Student Details' }}</h5>
    <form method="POST" action="{{ $mode == 'add' ? route('add_student') : route('edit_student', ['student_id' => $student->id]) }}">
        @csrf
        <div class="mb-3">
            <label class="form-label">Reg No (Admission Number)</label>
            <input type="text" class="form-control" name="reg_no" required
                   value="{{ $student ? ($student['reg_no'] ?? $student->reg_no) : old('reg_no') }}">
        </div>
        <div class="mb-3">
            <label class="form-label">Student's Full Name</label>
            <input type="text" id="full_name" class="form-control" name="full_name" required autocomplete="off"
                   value="{{ $student ? ($student['full_name'] ?? $student->full_name) : old('full_name') }}">
        </div>
        <div class="row">
            <div class="col-7 mb-3">
                <label class="form-label">Gender</label>
                <select id="gender" class="form-select" name="gender">
                    <option value="">-- Select --</option>
                    @php $currentGender = $student ? ($student['gender'] ?? $student->gender) : old('gender'); @endphp
                    <option value="Male" @if($currentGender == 'Male') selected @endif>Male</option>
                    <option value="Female" @if($currentGender == 'Female') selected @endif>Female</option>
                </select>
                <div id="gender-hint" class="form-text"></div>
            </div>
            <div class="col-5 mb-3 d-flex align-items-center">
                <div class="form-check mt-4">
                    @php $isConfirmed = $student ? ($student['gender_confirmed'] ?? $student->gender_confirmed) : false; @endphp
                    <input class="form-check-input" type="checkbox" id="gender_confirmed" name="gender_confirmed"
                           @if($isConfirmed) checked @endif>
                    <label class="form-check-label" for="gender_confirmed">Confirm Gender</label>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">Class</label>
            @if($session_user->role === $ROLE_CLASS_TEACHER)
                <input type="text" class="form-control" value="{{ count($classes) > 0 ? $classes[0]->name : 'No class assigned' }}" disabled>
                <input type="hidden" name="class_id" value="{{ count($classes) > 0 ? $classes[0]->id : '' }}">
                <div class="form-text">You can only register/edit students in your own assigned class.</div>
            @else
                <select class="form-select" name="class_id" required>
                    <option value="">-- Select Class --</option>
                    @php $currentClassId = $student ? ($student['class_id'] ?? $student->class_id) : old('class_id'); @endphp
                    @foreach($classes as $c)
                    <option value="{{ $c->id }}" @if(strval($currentClassId) === strval($c->id)) selected @endif>{{ $c->name }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <button class="btn btn-success"><i class="bi bi-check2 me-1"></i>{{ $mode == 'add' ? 'Register Student' : 'Save Changes' }}</button>
        <a href="{{ route('students') }}" class="btn btn-outline-secondary">Cancel</a>
    </form>
</div>
@endsection
