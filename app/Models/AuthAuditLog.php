<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'actor_id',
    'target_user_id',
    'event',
    'login_identifier',
    'ip_address',
    'user_agent',
    'guard',
    'provider',
    'success',
    'failure_reason_code',
    'mfa_required',
    'mfa_passed',
    'session_id_hash',
    'token_id',
    'created_at',
])]
class AuthAuditLog extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'success' => 'boolean',
            'mfa_required' => 'boolean',
            'mfa_passed' => 'boolean',
            'created_at' => 'datetime',
        ];
    }
}
