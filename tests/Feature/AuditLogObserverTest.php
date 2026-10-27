<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('writes audit logs for model creations, updates, and soft deletes', function (): void {
    // 1. Create a user (should trigger 'created' audit log)
    $user = User::create([
        'name' => 'Audit Test User',
        'email' => 'audit@example.com',
        'password' => Hash::make('password'),
    ]);

    $createLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'created')
        ->first();

    expect($createLog)->not->toBeNull();
    expect($createLog->new_values['email'])->toBe('audit@example.com');

    // 2. Update the user (should trigger 'updated' audit log with changed fields)
    $user->update([
        'name' => 'Audit Test User Updated',
        'record_version' => 1,
    ]);

    $updateLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'updated')
        ->first();

    expect($updateLog)->not->toBeNull();
    expect($updateLog->old_values['name'])->toBe('Audit Test User');
    expect($updateLog->new_values['name'])->toBe('Audit Test User Updated');

    // 3. Delete the user (should trigger 'deleted' audit log)
    $user->delete();

    $deleteLog = AuditLog::where('table_name', 'users')
        ->where('record_id', $user->id)
        ->where('action', 'deleted')
        ->first();

    expect($deleteLog)->not->toBeNull();
    expect($deleteLog->old_values['email'])->toBe('audit@example.com');
});

it('never logs password hashes or remember tokens, but still records that they changed', function (): void {
    $user = User::create([
        'name' => 'Hidden Fields User',
        'email' => 'hidden@example.com',
        'password' => Hash::make('first-password'),
    ]);

    $created = AuditLog::where('table_name', 'users')->where('record_id', $user->id)->where('action', 'created')->firstOrFail();
    expect($created->new_values['password'])->toBe('[redacted]');

    $user->forceFill(['password' => Hash::make('second-password'), 'remember_token' => 'token-abc', 'record_version' => 1])->save();

    $updated = AuditLog::where('table_name', 'users')->where('record_id', $user->id)->where('action', 'updated')->firstOrFail();
    expect($updated->old_values['password'])->toBe('[redacted]')
        ->and($updated->new_values['password'])->toBe('[redacted]')
        ->and($updated->new_values['remember_token'])->toBe('[redacted]');

    $user->delete();

    $deleted = AuditLog::where('table_name', 'users')->where('record_id', $user->id)->where('action', 'deleted')->firstOrFail();
    expect($deleted->old_values['password'])->toBe('[redacted]');

    $everything = AuditLog::where('table_name', 'users')->where('record_id', $user->id)->get()
        ->map(fn (AuditLog $log): string => json_encode([$log->old_values, $log->new_values]))
        ->implode(' ');
    expect($everything)->not->toContain('$2y$');
});

it('scrubs password hashes from audit rows written before the fix', function (): void {
    $user = User::create(['name' => 'Old Row User', 'email' => 'oldrow@example.com', 'password' => Hash::make('secret')]);

    // A row as the old observer wrote it: the hash in clear.
    $legacy = AuditLog::create([
        'id' => (string) Str::uuid(),
        'table_name' => 'users',
        'record_id' => $user->id,
        'action' => 'updated',
        'old_values' => ['password' => '$2y$12$oldhashvalue', 'name' => 'Old Row User'],
        'new_values' => ['password' => '$2y$12$newhashvalue', 'remember_token' => 'tok'],
    ]);

    $migration = require database_path('migrations/2026_10_28_150000_redact_hidden_values_in_audit_logs.php');
    $migration->up();

    $legacy->refresh();
    expect($legacy->old_values['password'])->toBe('[redacted]')
        ->and($legacy->old_values['name'])->toBe('Old Row User')
        ->and($legacy->new_values['password'])->toBe('[redacted]')
        ->and($legacy->new_values['remember_token'])->toBe('[redacted]');
});
