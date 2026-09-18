<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminTokenService;
use App\Support\AdminPasswordPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class ResetAdminPassword extends Command
{
    protected $signature = 'admin:reset-password';

    protected $description = 'Reset an existing administrator password';

    public function handle(AdminTokenService $tokens): int
    {
        $email = User::normalizeEmail($this->ask('Admin email'));

        if ($email === null || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('Unable to reset the admin password.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Confirm new password');
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
            DB::transaction(function () use ($email, $password, $tokens): void {
                $user = User::query()->where('email', $email)->lockForUpdate()->first();
                $membership = $user?->adminMembership()->lockForUpdate()->first();

                if ($user === null || $membership === null || ! $user->is_active || $membership->status->value !== 'active') {
                    throw new \RuntimeException('Admin password reset is unavailable.');
                }

                $user->forceFill(['password' => Hash::make($password)])->save();
                $tokens->revokeAdminTokens($user);
            });
        } catch (Throwable $exception) {
            $this->error('Unable to reset the admin password.');

            return self::FAILURE;
        }

        $this->info('Admin password reset.');

        return self::SUCCESS;
    }
}
