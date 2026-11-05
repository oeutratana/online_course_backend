<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = [
        'user_id',
        'course_id',
        'amount',
        'method',
        'status',
        'transaction_id',
        'qr_url',
        'provider_payload',
        'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'provider_payload' => 'array',
        'paid_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * A failed or expired payment can never be completed: its PayWay
     * transaction_id is single-use, so the customer has to start a brand new
     * checkout instead of reusing this row.
     */
    public function needsNewCheckout(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_EXPIRED], true);
    }

    /**
     * True while a pending checkout is still within the PayWay lifetime window.
     */
    public function isCheckoutFresh(): bool
    {
        return $this->created_at !== null
            && $this->created_at->gt(now()->subMinutes($this->checkoutLifetimeMinutes()));
    }

    public function checkoutLifetimeMinutes(): int
    {
        return max(3, (int) config('services.payway.lifetime_minutes', 60));
    }
}
