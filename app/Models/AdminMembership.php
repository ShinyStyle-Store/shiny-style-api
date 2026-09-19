<?php

namespace App\Models;

use App\Enums\AdminMembershipStatus;
use Database\Factories\AdminMembershipFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminMembership extends Model
{
    /** @use HasFactory<AdminMembershipFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'created_by', 'activated_at', 'suspended_at',
        'revoked_at', 'last_login_at',
    ];

    protected $hidden = ['user', 'created_by', 'createdBy'];

    protected function casts(): array
    {
        return [
            'status' => AdminMembershipStatus::class,
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
