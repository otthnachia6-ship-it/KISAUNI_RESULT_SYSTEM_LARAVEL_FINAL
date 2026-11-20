{{-- $checks: list of ['label','value','status','note'] or [label, value, status] --}}
<div class="card mb-3">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Check</th><th>Value</th><th>Status</th></tr></thead>
        <tbody>
        @forelse($checks as $c)
            @php
                $c = isset($c['label']) ? $c : ['label' => $c[0], 'value' => $c[1], 'status' => $c[2], 'note' => $c[3] ?? null];
            @endphp
            <tr>
                <td class="fw-semibold">{{ $c['label'] }}</td>
                <td>{{ $c['value'] }}@if(!empty($c['note']))<div class="small text-muted">{{ $c['note'] }}</div>@endif</td>
                <td>@include('developer.partials.status', ['status' => $c['status']])</td>
            </tr>
        @empty
            <tr><td colspan="3" class="text-center text-muted">No information available.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
