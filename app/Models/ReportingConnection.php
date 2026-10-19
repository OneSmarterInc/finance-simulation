<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** U3 (RP3): one admin's sign-in to the reporting connector. Tokens are kept only as hashes. */
class ReportingConnection extends Model
{
    use HasUuids;

    protected $fillable = ['client_id', 'user_id', 'scope', 'access_hash', 'access_expires_at', 'refresh_hash', 'refresh_expires_at', 'last_used_at', 'revoked_at', 'revoked_by_user_id'];

    protected $hidden = ['access_hash', 'refresh_hash'];

    protected function casts(): array
    {
        return [
            'access_expires_at' => 'datetime',
            'refresh_expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(ReportingClient::class, 'client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_user_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(ReportingCall::class, 'connection_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null && $this->refresh_expires_at->isFuture();
    }
}
