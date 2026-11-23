<?php

namespace App\Models\Crm;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Crm\LeadActivityFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $lead_id
 * @property int|null $actor_user_id
 * @property string $activity_type
 * @property string|null $body
 * @property array<string, mixed>|null $meta
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class LeadActivity extends Model
{
    /** @use HasFactory<LeadActivityFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'crm_lead_activities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
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

    protected static function newFactory(): LeadActivityFactory
    {
        return LeadActivityFactory::new();
    }

    /**
     * @return BelongsTo<Lead, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
