<?php

namespace App\Models\Crm;

use App\Models\User;
use Database\Factories\Crm\SupportTicketActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $ticket_id
 * @property int|null $actor_user_id
 * @property string $activity_type
 * @property string|null $body
 * @property array<string, mixed>|null $meta
 */
class SupportTicketActivity extends Model
{
    /** @use HasFactory<SupportTicketActivityFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'crm_support_ticket_activities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ticket_id',
        'actor_user_id',
        'activity_type',
        'body',
        'meta',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SupportTicketActivityFactory
    {
        return SupportTicketActivityFactory::new();
    }

    /**
     * @return BelongsTo<SupportTicket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(SupportTicket::class, 'ticket_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
