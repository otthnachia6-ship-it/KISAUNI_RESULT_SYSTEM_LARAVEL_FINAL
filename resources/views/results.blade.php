@extends('base')
@section('page_title', 'Review & Approval')
@section('content')
<x-page_header title="Review & Approval" :subtitle="'Submitted results for Academic Year ' . $current_academic_year" />
<div class="card">
    <div class="table-responsive">
    <table class="table align-middle mb-0 table-stack tbl-clean">
        <thead><tr><th>Class</th><th>Examination</th><th>Status</th><th>Submitted</th><th class="text-end">Action</th></tr></thead>
        <tbody>
        @forelse($rows as $r)
        <tr>
            <td class="fw-semibold stack-head">{{ $r->class_name }}</td>
            <td data-label="Examination">{{ $r->exam_type }} ({{ $r->academic_year }})</td>
            <td data-label="Status"><span class="badge status-badge-{{ $r->status }}">{{ $STATUS_LABELS[$r->status] ?? $r->status }}</span></td>
            <td class="cell-muted" data-label="Submitted">{{ $r->submitted_at ?: '-' }}</td>
            <td class="text-end stack-actions">
                <a href="{{ route('review_results', ['exam_id' => $r->exam_id, 'class_id' => $r->class_id_val]) }}" class="btn btn-sm btn-outline-success">
                    <i class="bi bi-eye me-1"></i>{{ in_array($r->status, ['submitted', 'under_review']) ? 'Review' : 'View' }}
                </a>
            </td>
        </tr>
        @empty
        <tr><td colspan="5" class="text-center text-muted">No results submitted yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
