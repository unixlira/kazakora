<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Checkout\Models\Address;
use App\Modules\Checkout\Models\Order;
use App\Support\Rbac\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;

#[Fillable(['name', 'email', 'password', 'role', 'phone', 'cpf', 'birth_date', 'avatar_path'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, SoftDeletes;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_SUBSCRIBER = 'subscriber';

    public const ROLE_CUSTOMER = 'customer';

    /** Roles that can sign in to the admin panel (with varying permissions). */
    public const STAFF_ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SUBSCRIBER];

    /** Roles an admin is allowed to assign to a user. */
    public const ASSIGNABLE_ROLES = [self::ROLE_ADMIN, self::ROLE_MANAGER, self::ROLE_SUBSCRIBER, self::ROLE_CUSTOMER];

    /** Attributes never written to the audit trail in cleartext. */
    public static array $auditExcept = ['password', 'remember_token'];

    protected $appends = ['avatar_url', 'initials'];

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
            'birth_date' => 'date',
            'deleted_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isStaff(): bool
    {
        return in_array($this->role, self::STAFF_ROLES, true);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class)->orderByDesc('is_default')->latest();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim($this->name ?? ''));
        $letters = array_map(fn (string $word) => mb_strtoupper(mb_substr($word, 0, 1)), array_filter($words));

        return implode('', array_slice($letters, 0, 2)) ?: '?';
    }

    /**
     * Substitui a notificação padrão do Laravel (e-mail cru, sem marca) por
     * um Mailable com o template responsivo da loja. O link continua vindo
     * da mesma rota nomeada que Password::sendResetLink()/reset() já usam.
     */
    public function sendPasswordResetNotification($token): void
    {
        $resetUrl = route('senha.redefinir', ['token' => $token, 'email' => $this->email]);

        Mail::to($this->email)->send(new PasswordResetMail($this, $resetUrl));
    }
}
