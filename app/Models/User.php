<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Traders are Krayin CRM users.
 *
 * This model intentionally reads the "users" table of the shared krayin_crm
 * database, which is owned by Krayin: the columns mirror the CRM schema and
 * the hash is the CRM one, so the same credentials work in both applications.
 * A row is inserted only by app/Services/ClientRegistrationService.php when a
 * guest signs up on the public registration page.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $table = 'users';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'status',
        'role_id',
        'view_permission',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
        ];
    }

    public function tradingAccount(): HasOne
    {
        return $this->hasOne(TradingAccount::class);
    }

    public function tradingPositions(): HasMany
    {
        return $this->hasMany(TradingPosition::class);
    }
}
