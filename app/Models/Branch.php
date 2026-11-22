<?php

namespace App\Models;

use App\Domains\Company\Exceptions\CompanyDomainException;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property Carbon|null $deactivated_at
 * @property Carbon|null $deleted_at
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'deactivated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            if (! filled($branch->getAttribute('id'))) {
                $branch->setAttribute('id', (string) Str::uuid());
            }
        });
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null && ! $this->trashed();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasMany<BranchAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(BranchAssignment::class);
    }

    public function restore(): bool
    {
        throw new CompanyDomainException('Branch restoration is prohibited (DEC-003).');
    }

    public function forceDelete(): bool
    {
        throw new CompanyDomainException('Hard deletion of branches is prohibited (DEC-003).');
    }
}
