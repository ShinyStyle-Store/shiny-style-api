<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AdminTokenService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ChangeAdminEmail extends Command
{
    protected $signature = 'admin:change-email';

    protected $description = 'Change the email address of an existing administrator';

    public function handle(AdminTokenService $tokens): int
    {
        $currentEmail = User::normalizeEmail($this->ask('Current admin email'));
        $newEmail = User::normalizeEmail($this->ask('New admin email'));
        $confirmation = User::normalizeEmail($this->ask('Confirm new admin email'));

        if (! $this->validEmail($currentEmail) || ! $this->validEmail($newEmail)) {
            $this->error('A valid current and new email are required.');

            return self::FAILURE;
        }

        if ($newEmail !== $confirmation) {
            $this->error('The new email addresses do not match.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($currentEmail, $newEmail, $tokens): void {
                $user = User::query()->where('email', $currentEmail)->lockForUpdate()->first();
                $membership = $user?->adminMembership()->lockForUpdate()->first();

                if ($user === null || $membership === null || ! $user->is_active || $membership->status->value !== 'active') {
                    throw new \RuntimeException('Admin email change is unavailable.');
                }

                if ($user->email === $newEmail) {
                    throw new \RuntimeException('The new email must differ from the current email.');
                }

                if (User::query()
                    ->where('email', $newEmail)
                    ->where($user->getKeyName(), '!=', $user->getKey())
                    ->lockForUpdate()
                    ->exists()) {
                    throw new \RuntimeException('The new email is already in use.');
                }

                $user->forceFill(['email' => $newEmail])->save();
                $tokens->revokeAdminTokens($user);
            });
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('Unable to change the admin email. No changes were saved.');

            return self::FAILURE;
        }

        $this->info('Admin email changed. All admin sessions were revoked.');

        return self::SUCCESS;
    }

    private function validEmail(?string $email): bool
    {
        return $email !== null
            && strlen($email) <= 254
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
