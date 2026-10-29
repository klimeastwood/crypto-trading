<?php

namespace App\Models;

use App\Support\CardCipher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card the client funds the account from.
 *
 * The row keeps the number in an encrypted column and the CVV nowhere: the last
 * four digits and the brand are enough to render the cabinet, and the operator
 * console in the CRM decrypts the full value when a deposit has to be matched
 * against a bank statement. The model hides `pan_encrypted` from serialisation,
 * so a stray `toArray()` cannot leak the ciphertext either.
 */
class DepositCard extends Model
{
    protected $table = 'deposit_cards';

    protected $fillable = [
        'user_id',
        'holder_name',
        'brand',
        'last4',
        'expiry',
        'pan_encrypted',
        'fingerprint',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'pan_encrypted',
        'fingerprint',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The number as the client may see it: only the last four digits.
     */
    public function maskedNumber(): string
    {
        return '•••• •••• •••• '.$this->last4;
    }

    /**
     * The full number, for the CRM review screen only.
     *
     * Null when the encryption key of this deployment does not match the key
     * the row was written with, so a misconfigured environment shows an error
     * instead of a broken string.
     */
    public function pan(): ?string
    {
        return CardCipher::decrypt((string) $this->pan_encrypted);
    }

    /**
     * Human readable brand for the cabinet.
     */
    public function brandLabel(): string
    {
        return match ($this->brand) {
            'visa' => 'Visa',
            'mastercard' => 'Mastercard',
            'mir' => 'МИР',
            default => 'Банковская карта',
        };
    }
}
