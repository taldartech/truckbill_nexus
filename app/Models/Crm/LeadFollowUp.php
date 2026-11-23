<?php

namespace App\Models\Crm;

use App\Models\User;
use Database\Factories\Crm\LeadFollowUpFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $lead_id
 * @property int $assigned_to_user_id
 * @property int $created_by_user_id
 * @property Carbon $due_at
 * @property string|null $notes
 * @property Carbon|null $completed_at
 * @property string|null $completion_notes
 */
class LeadFollowUp extends Model
{
    /** @use HasFactory<LeadFollowUpFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'crm_lead_follow_ups';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'lead_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'due_at',
        'notes',
        'completed_at',
        'completion_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): LeadFollowUpFactory
    {
        return LeadFollowUpFactory::new();
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
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }
}
