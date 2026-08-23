<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Infrastructure\Eloquent\Role;
use App\Modules\Identity\Infrastructure\Eloquent\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Named explicitly. Eloquent's factory convention resolves
     * `Database\Factories\{Model}Factory` against `App\Models`, and AP-02
     * moved this model into its module, so the guess no longer lands.
     *
     * @var class-string<User>
     */
    protected $model = User::class;

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
            'password' => static::$password ??= Hash::make('password'),
            'role_id' => self::defaultRoleId(),
            'is_active' => true,
            'is_hidden' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /** §3.12 rule 6 — the Super Admin, absent from every user list. */
    public function hidden(): static
    {
        return $this->state(fn (array $attributes): array => ['is_hidden' => true]);
    }

    /**
     * `users.role_id` is NOT NULL because §3.1 gives every user exactly one
     * role, so a user cannot be manufactured without one.
     *
     * Indoor Sales is the default because it is the plainest operational role
     * in §3.1 — `Own` scope, no supervision, nothing hidden — so a factory user
     * carries the fewest assumptions. A test that needs a different role says
     * so explicitly.
     */
    private static function defaultRoleId(): string
    {
        return Role::firstOrCreate(
            ['slug' => 'indoor_sales'],
            ['name' => 'Indoor Sales', 'is_system' => true],
        )->id;
    }
}
