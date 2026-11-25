<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $purpose
 * @property string $subject_type
 * @property string $subject_id
 * @property string $email_normalized
 * @property string $otp_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $consumed_at
 */
class EmailOtp extends Model
{
    use HasUuids;

    protected $table = 'email_otps';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'purpose',
        'subject_type',
        'subject_id',
        'email_normalized',
        'otp_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'consumed_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'otp_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
