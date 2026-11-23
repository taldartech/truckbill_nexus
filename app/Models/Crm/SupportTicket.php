<?php

namespace App\Models\Crm;

use App\Domains\Crm\Enums\SupportTicketPriority;
use App\Domains\Crm\Enums\SupportTicketStatus;
use App\Models\Company;
use App\Models\User;
use Database\Factories\Crm\SupportTicketFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $subject
 * @property string $description
 * @property string $category
 * @property SupportTicketPriority $priority
 * @property SupportTicketStatus $status
 * @property int|null $requester_user_id
 * @property int|null $assigned_to_user_id
 * @property int $created_by_user_id
 * @property string|null $related_company_id
 * @property Carbon|null $due_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string|null $resolution_notes
 */
class SupportTicket extends Model
{
    /** @use HasFactory<SupportTicketFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'crm_support_tickets';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'subject',
        'description',
        'category',
        'priority',
        'status',
        'requester_user_id',
        'assigned_to_user_id',
        'created_by_user_id',
        'related_company_id',
        'due_at',
        'resolved_at',
        'closed_at',
        'resolution_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => SupportTicketPriority::class,
            'status' => SupportTicketStatus::class,
            'due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): SupportTicketFactory
    {
        return SupportTicketFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function relatedCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'related_company_id');
    }

    /**
     * @return HasMany<SupportTicketActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(SupportTicketActivity::class, 'ticket_id');
    }
}
