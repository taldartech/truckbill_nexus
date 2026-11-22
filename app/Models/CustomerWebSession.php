<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Exactly one active Customer web session reference per identity (ADR-006 / P2-WS-4).
 *
 * @property int $user_id
 * @property string $session_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CustomerWebSession extends Model
{
    public $incrementing = false;

    protected $primaryKey = 'user_id';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'session_id',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
