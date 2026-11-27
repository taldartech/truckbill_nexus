<?php

namespace App\Models;

use Database\Factories\PartyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Party extends Model
{
    /** @use HasFactory<PartyFactory> */
    use HasFactory, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'company_id',
        'branch_id',
        'name',
        'short_name',
        'contact_person',
        'contact_number',
        'email',
        'gstin',
        'pan',
        'is_gst_applicable',
        'state',
        'state_id',
        'city',
        'city_id',
        'address',
        'bank_name',
        'bank_account_holder_name',
        'bank_account_number',
        'bank_ifsc',
        'bank_short_name',
        'bank_beneficiary_id',
    ];

    /** @var list<string> */
    protected $hidden = ['bank_account_number'];

    protected function casts(): array
    {
        return [
            'is_gst_applicable' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            if (! filled($model->getAttribute('id'))) {
                $model->setAttribute('id', (string) Str::uuid());
            }
        });

        static::saving(function (self $model): void {
            $followsName = $model->exists
                && $model->isDirty('name')
                && ! $model->isDirty('short_name')
                && $model->getOriginal('short_name') === $model->getOriginal('name');

            if ($followsName || blank($model->getAttribute('short_name'))) {
                $model->setAttribute('short_name', $model->getAttribute('name'));
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * @return HasMany<PartyRole, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }

    /**
     * @param  Builder<Party>  $query
     * @return Builder<Party>
     */
    public function scopeForCompany(Builder $query, string $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    /**
     * Visible in a branch context: company-wide (null branch) + this branch.
     *
     * @param  Builder<Party>  $query
     * @return Builder<Party>
     */
    public function scopeVisibleInBranch(Builder $query, string $companyId, string $branchId): Builder
    {
        return $query->where('company_id', $companyId)
            ->where(function (Builder $inner) use ($branchId): void {
                $inner->whereNull('branch_id')->orWhere('branch_id', $branchId);
            });
    }
}
