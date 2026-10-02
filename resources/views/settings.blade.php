@extends('base')
@section('page_title', 'Settings')
@section('content')
<x-page_header icon="bi-gear" title="School Settings" subtitle="School name, logo and academic year" />
<div class="card p-4" style="max-width:550px;">
    <form method="POST" action="{{ route('settings_page') }}" enctype="multipart/form-data">
        @csrf
        <div class="mb-3">
            <label class="form-label">School Name</label>
            <input type="text" class="form-control" name="school_name" value="{{ $current['school_name'] ?? $school_name }}" required>
        </div>
        <div class="mb-3">
            <label class="form-label">Academic Year</label>
            <input type="text" class="form-control" name="academic_year" value="{{ $current['academic_year'] ?? $academic_year }}" required>
            <div class="form-text">
                Changing this to a new year is also what unlocks Class Teachers to promote their
                students to the next class (they cannot promote while it stays the same as before).
            </div>
        </div>
        <div class="mb-3">
            <label class="form-label">School Logo</label><br>
            <img src="{{ $logo_url($current['logo_path'] ?? $logo_path) }}" style="height:60px;" class="mb-2"><br>
            <input type="file" class="form-control" name="logo" accept="image/*">
        </div>
        <div class="mb-3">
            <label class="form-label">Grading System (fixed)</label>
            <table class="table table-sm">
                <tr><td><span class="grade-badge grade-A">A</span></td><td>81 - 100</td></tr>
                <tr><td><span class="grade-badge grade-B">B</span></td><td>61 - 80</td></tr>
                <tr><td><span class="grade-badge grade-C">C</span></td><td>41 - 60</td></tr>
                <tr><td><span class="grade-badge grade-D">D</span></td><td>21 - 40</td></tr>
                <tr><td><span class="grade-badge grade-E">E</span></td><td>0 - 20.9</td></tr>
            </table>
            <small class="text-muted">To change the grading bands, contact your system administrator (config/school.php).</small>
        </div>
        <button class="btn btn-success">Save Settings</button>
    </form>
</div>
@endsection
