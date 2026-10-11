<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            // CPF válido (o checkout exige — pedido 2026-10-11).
            'cpf' => self::cpfValido(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /** CPF com dígitos verificadores corretos, gerado na hora. */
    public static function cpfValido(): string
    {
        do {
            $base = array_map(fn () => random_int(0, 9), range(1, 9));
        } while (count(array_unique($base)) === 1);

        foreach ([10, 11] as $peso) {
            $soma = 0;
            foreach ($base as $i => $digito) {
                $soma += $digito * ($peso - $i);
            }
            $base[] = ((10 * $soma) % 11) % 10;
        }

        return implode('', $base);
    }
}
