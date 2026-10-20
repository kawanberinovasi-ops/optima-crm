<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Department;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * Define the model's default state. Login lewat SSO, jadi tidak ada password.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'department_id' => fn () => Department::query()->value('id') ?? Department::create(['name' => 'Commercial'])->id,
            'role' => Role::Staf,
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function role(Role $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    public function inDepartment(string $name): static
    {
        return $this->state(fn () => ['department_id' => Department::firstOrCreate(['name' => $name])->id]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false, 'deactivated_at' => now()]);
    }
}
