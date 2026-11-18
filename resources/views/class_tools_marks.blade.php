@extends('base')
@section('page_title', 'Correct marks')

@section('content')
<x-page_header icon="bi-eraser" title="{{ $class->name }}" subtitle="{{ $exam->exam_type }} {{ $exam->academic_year }}">
    <x-slot:actions>
        <a href="{{ route('class_tools', $class->id) }}" class="btn btn-sm btn-outline-secondary">&larr; Class tools</a>
    </x-slot:actions>
</x-page_header>

<form method="POST" action="{{ route('class_tools_marks_save', [$class->id, $exam->id]) }}" class="card p-3 p-md-4" autocomplete="off">
    @csrf
    @if(count($grid['subjects']) && count($grid['students']))
    <div class="table-responsive marks-fix">
        <table class="table table-sm align-middle mb-3">
            <thead>
                <tr>
                    <th class="marks-fix-name">Student</th>
                    @foreach($grid['subjects'] as $sub)<th class="text-center">{{ $sub->name }}</th>@endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($grid['students'] as $st)
                <tr>
                    <td class="marks-fix-name">{{ $st->full_name }}</td>
                    @foreach($grid['subjects'] as $sub)
                        @php $v = old("marks.{$st->id}.{$sub->id}", $grid['scores'][$st->id][$sub->id] ?? ''); @endphp
                        <td><input type="number" class="form-control form-control-sm marks-fix-input" min="0" max="100" step="any" inputmode="decimal"
                                   name="marks[{{ $st->id }}][{{ $sub->id }}]" value="{{ $v }}"
                                   aria-label="{{ $st->full_name }}, {{ $sub->name }}"></td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button type="submit" class="btn btn-success" data-loading-text="Saving..."><i class="bi bi-check2-circle me-1"></i>Save &amp; Approve</button>
        <a href="{{ route('class_tools', $class->id) }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
    @else
        <div class="small text-muted">No students or subjects to show.</div>
    @endif
</form>
@endsection
