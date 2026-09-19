<?php

namespace App\Console\Commands;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use App\Support\AdminPasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class CreateAdminOwner extends Command
{
    protected $signature = 'admin:create-owner';

    protected $description = 'Create the first store owner administrator';

    public function handle(): int
    {
        $name = trim((string) $this->ask('Name'));
        $email = User::normalizeEmail($this->ask('Email'));

        if ($name === '' || $email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('A valid name and email are required.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');
        $violations = AdminPasswordPolicy::violations($password);

        if ($password !== $confirmation) {
            $violations[] = 'Passwords do not match.';
        }
        if ($violations !== []) {
            foreach (array_unique($violations) as $violation) {
                $this->error($violation);
            }

            return self::FAILURE;
        }

        try {
            Cache::lock('admin-owner-bootstrap', 30)->block(10, function () use ($name, $email, $password): void {
                DB::transaction(function () use ($name, $email, $password): void {
                    if (AdminMembership::query()->lockForUpdate()->exists()) {
                        throw new \RuntimeException('An admin membership already exists.');
                    }

                    $user = User::query()->where('email', $email)->lockForUpdate()->first();
                    if ($user !== null) {
                        if (! $this->confirm('This user exists. Promote this identity to the first owner?', false)) {
                            throw new \RuntimeException('Owner promotion declined.');
                        }
                        if (! $user->is_active && ! $this->confirm('This user is inactive. Reactivate it?', false)) {
                            throw new \RuntimeException('Owner reactivation declined.');
                        }

                        $user->forceFill([
                            'name' => $name,
                            'password' => Hash::make($password),
                            'is_active' => true,
                            'email_verified_at' => now(),
                        ])->save();
                    } else {
                        $user = User::forceCreate([
                            'name' => $name,
                            'email' => $email,
                            'password' => Hash::make($password),
                            'is_active' => true,
                            'email_verified_at' => now(),
                        ]);
                    }

                    if ($user->adminMembership()->lockForUpdate()->exists()) {
                        throw new \RuntimeException('This user already has an admin membership.');
                    }

                    AdminMembership::create([
                        'user_id' => $user->getKey(),
                        'status' => AdminMembershipStatus::Active,
                        'created_by' => null,
                        'activated_at' => now(),
                    ]);
                });
            });
        } catch (Throwable $exception) {
            $this->error('Unable to create the owner. No changes were saved.');

            return self::FAILURE;
        }

        $this->info('Admin owner created.');

        return self::SUCCESS;
    }
}
