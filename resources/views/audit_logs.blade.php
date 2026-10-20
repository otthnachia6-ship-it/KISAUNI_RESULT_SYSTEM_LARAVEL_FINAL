@extends('base')
@section('page_title', 'Audit Log')
@section('content')
<x-page_header icon="bi-shield-check" title="Audit Log" :subtitle="'System activity - records kept for ' . $retention_days . ' days'" />
@php
$action_labels = [
    'ADD_CLASS' => 'Added a class', 'ADD_EXAM' => 'Added an examination',
    'ADD_STUDENT' => 'Added a student', 'ADD_STUDENTS_BULK' => 'Added students (bulk)',
    'ADD_SUBJECT' => 'Added a subject', 'ADD_USER' => 'Added a user account',
    'ADVANCE_ACADEMIC_YEAR' => 'Advanced the academic year', 'APPROVE_RESULTS' => 'Approved results',
    'ASSIGN_CLASS_TEACHER' => 'Assigned a class teacher', 'CHANGE_PASSWORD' => 'Changed password',
    'CREATE_BACKUP' => 'Created a backup', 'DELETE_BACKUP' => 'Deleted a backup',
    'DELETE_CLASS' => 'Removed a class', 'DELETE_EXAM' => 'Deleted an examination',
    'DELETE_STUDENT' => 'Removed a student', 'DELETE_SUBJECT' => 'Deleted a subject',
    'DELETE_USER' => 'Deleted a user account', 'DOWNLOAD_BACKUP' => 'Downloaded a backup',
    'EDIT_STUDENT' => 'Edited a student', 'EDIT_USER' => 'Edited a user account',
    'ENTER_MARKS' => 'Entered marks', 'LOGIN' => 'Logged in', 'PASSWORD_HASH_UPGRADED' => 'Password migrated from the old system', 'LOGOUT' => 'Logged out',
    'PASSWORD_RESET_REQUESTED' => 'Requested a password reset', 'PROMOTE_CLASS' => 'Promoted a class',
    'PROMOTE_GRADUATE' => 'Graduated a class', 'PROMOTE_MY_CLASS' => 'Promoted own class',
    'PROMOTE_MY_CLASS_GRADUATE' => 'Graduated own class', 'PURGE_STUDENT' => 'Permanently deleted a student',
    'RESTORE_BACKUP' => 'Restored a backup', 'RESTORE_BACKUP_UPLOAD' => 'Restored from an uploaded backup',
    'RESTORE_STUDENT' => 'Restored a student', 'RETURN_RESULTS' => 'Returned results',
    'START_REVIEW' => 'Started results review', 'SUBMIT_RESULTS' => 'Submitted results',
    'TOGGLE_USER' => 'Activated/deactivated a user', 'UPDATE_PROFILE' => 'Updated profile',
    'UPDATE_SETTINGS' => 'Updated school settings',
];
@endphp
<div class="card">
    <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Details</th></tr></thead>
        <tbody>
        @forelse($logs as $log)
        @php
            $logTime = is_array($log) ? ($log['created_at'] ?? '') : $log->created_at;
            $logUser = is_array($log) ? ($log['username'] ?? '') : $log->username;
            $logAction = is_array($log) ? ($log['action'] ?? '') : $log->action;
            $logDetails = is_array($log) ? ($log['details'] ?? '') : $log->details;
        @endphp
        <tr>
            <td class="text-muted">{{ $logTime }}</td>
            <td>{{ $logUser }}</td>
            <td><span class="badge bg-light text-dark border" title="{{ $logAction }}">{{ $action_labels[$logAction] ?? $logAction }}</span></td>
            <td class="text-muted">{{ $logDetails }}</td>
        </tr>
        @empty
        <tr><td colspan="4" class="text-center text-muted">No records yet.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
