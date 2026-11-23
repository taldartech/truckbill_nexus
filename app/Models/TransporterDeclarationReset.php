<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $financial_year
 * @property CarbonInterface $reset_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class TransporterDeclarationReset extends Model
{
    protected $fillable = [
        'financial_year',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'financial_year' => 'integer',
            'reset_at' => 'datetime',
        ];
    }
}
