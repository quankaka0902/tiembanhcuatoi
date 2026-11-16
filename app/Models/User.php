<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_last_step'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = [
        'role' => 'customer',
        'active' => true,
        'can_reconcile' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'can_reconcile' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_last_step' => 'integer',
        ];
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'staff'], true);
    }

    public function staffProof(): ?string
    {
        $encryptedSecret = $this->getRawOriginal('two_factor_secret');

        return $this->active && $this->isStaff() && $encryptedSecret
            ? hash('sha256', implode('|', [$this->id, $this->role, $this->email, $this->getAuthPassword(), $encryptedSecret]))
            : null;
    }
}
