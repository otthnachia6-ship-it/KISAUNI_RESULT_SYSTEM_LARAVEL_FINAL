@php
    $statusIcons = ['healthy' => 'bi-check-circle-fill', 'warning' => 'bi-exclamation-triangle-fill', 'critical' => 'bi-x-octagon-fill', 'unavailable' => 'bi-dash-circle', 'not_configured' => 'bi-gear'];
@endphp
<span class="dev-status dev-status-{{ $status }}"><i class="bi {{ $statusIcons[$status] ?? 'bi-question-circle' }}"></i>{{ \App\Services\SystemHealthService::STATUS_LABELS[$status] ?? ucfirst($status) }}</span>
