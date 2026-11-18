@extends('base')
@section('page_title', 'Class tools')

@section('content')
<x-page_header icon="bi-eraser" title="Class tools" subtitle="{{ $class->name }} · {{ $data['active'] }} active, {{ $data['former'] }} former students">
    <x-slot:actions>
        <a href="{{ route('classes') }}" class="btn btn-sm btn-outline-secondary">&larr; Classes</a>
    </x-slot:actions>
</x-page_header>

<form method="POST" action="{{ route('class_tools_preview', $class->id) }}" class="card p-3 p-md-4">
    @csrf
    @php $picked = old('action'); @endphp

    <div class="tool">
        <input class="tool-radio" type="radio" name="action" id="act-edit" value="edit_marks" @checked($picked === 'edit_marks')>
        <label class="tool-head" for="act-edit">{{ $actions['edit_marks'] }}</label>
        <div class="tool-body">
            @if(count($data['exams']))
            <label class="form-label small mb-1" for="editExam">Examination</label>
            <select id="editExam" name="edit_exam" class="form-select">
                @foreach($data['exams'] as $e)
                    <option value="{{ $e['id'] }}" @selected((int) old('edit_exam') === $e['id'])>{{ $e['label'] }}</option>
                @endforeach
            </select>
            @else
                <div class="small text-muted">No results in this class.</div>
            @endif
        </div>
    </div>

    <div class="tool">
        <input class="tool-radio" type="radio" name="action" id="act-results" value="results" @checked($picked === 'results')>
        <label class="tool-head" for="act-results">{{ $actions['results'] }}</label>
        <div class="tool-body">
            @forelse($data['exams'] as $e)
                <label class="tool-check">
                    <input class="form-check-input mt-0" type="checkbox" name="exam_ids[]" value="{{ $e['id'] }}" @checked(in_array($e['id'], (array) old('exam_ids', [])))>
                    <span>{{ $e['label'] }}</span><small>{{ $e['marks'] }} results</small>
                </label>
            @empty
                <div class="small text-muted">No results in this class.</div>
            @endforelse
        </div>
    </div>

    <div class="tool">
        <input class="tool-radio" type="radio" name="action" id="act-move" value="move_exam" @checked($picked === 'move_exam')>
        <label class="tool-head" for="act-move">{{ $actions['move_exam'] }}</label>
        <div class="tool-body">
            @if(count($data['exams']))
            <label class="form-label small mb-1" for="fromExam">From</label>
            <select id="fromExam" name="from_exam" class="form-select mb-3">
                @foreach($data['exams'] as $e)
                    <option value="{{ $e['id'] }}" @selected((int) old('from_exam') === $e['id'])>{{ $e['label'] }} ({{ $e['marks'] }})</option>
                @endforeach
            </select>
            <label class="form-label small mb-1" for="toExam">To</label>
            <select id="toExam" name="to_exam" class="form-select">
                @foreach($data['all_exams'] as $e)
                    <option value="{{ $e['id'] }}" @selected((int) old('to_exam') === $e['id'])>{{ $e['label'] }}</option>
                @endforeach
            </select>
            @else
                <div class="small text-muted">No results in this class.</div>
            @endif
        </div>
    </div>

    <div class="tool tool--danger">
        <input class="tool-radio" type="radio" name="action" id="act-students" value="students" @checked($picked === 'students')>
        <label class="tool-head" for="act-students">{{ $actions['students'] }}</label>
    </div>

    <div class="tool tool--danger">
        <input class="tool-radio" type="radio" name="action" id="act-keep" value="class_keep" @checked($picked === 'class_keep')>
        <label class="tool-head" for="act-keep">{{ $actions['class_keep'] }}</label>
        <div class="tool-body">
            <label class="form-label small mb-1" for="targetClass">Correct class</label>
            <select id="targetClass" name="target_class" class="form-select">
                @foreach($data['others'] as $o)
                    <option value="{{ $o->id }}" @selected((int) old('target_class') === $o->id)>{{ $o->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="tool tool--danger">
        <input class="tool-radio" type="radio" name="action" id="act-all" value="class_all" @checked($picked === 'class_all')>
        <label class="tool-head" for="act-all">{{ $actions['class_all'] }}</label>
    </div>

    <div class="d-flex gap-2 flex-wrap mt-2">
        <button class="btn btn-primary" id="toolContinue" type="submit">Continue</button>
        <a href="{{ route('classes') }}" class="btn btn-outline-secondary">Cancel</a>
    </div>
</form>
@endsection
