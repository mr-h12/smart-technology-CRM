<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Ramsey\Uuid\Uuid;

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
     * Written with the query builder rather than an Eloquent model on purpose:
     * `roles` has no model yet. Module 1's application layer owns that, and a
     * factory reaching for one that does not exist would be this point
     * inventing scope it was not given.
     */
    private static function defaultRoleId(): string
    {
        $existing = DB::table('roles')->where('slug', 'indoor_sales')->value('id');

        if (is_string($existing)) {
            return $existing;
        }

        $id = Uuid::uuid7()->toString();

        DB::table('roles')->insert([
            'id' => $id,
            'name' => 'Indoor Sales',
            'slug' => 'indoor_sales',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
