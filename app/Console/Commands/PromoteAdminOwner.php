<?php

namespace App\Console\Commands;

use App\Enums\AdminMembershipStatus;
use App\Models\AdminMembership;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromoteAdminOwner extends Command
{
    protected $signature = 'admin:promote-owner {public_id : Exact public ID of the existing admin account} {--reason= : Audit reason for the promotion}';

    protected $description = 'Promote one explicitly confirmed active admin membership to owner';

    public function handle(): int
    {
        $publicId = strtoupper(trim((string) $this->argument('public_id')));
        $reason = trim((string) $this->option('reason'));

        if (! preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $publicId)) {
            $this->error('Provide the exact 26-character public ID of the intended account.');

            return self::FAILURE;
        }
        if (mb_strlen($reason) < 8 || mb_strlen($reason) > 500) {
            $this->error('Provide an audit reason between 8 and 500 characters.');

            return self::FAILURE;
        }
        if (strtoupper(trim((string) $this->ask("Type {$publicId} to confirm the exact account"))) !== $publicId) {
            $this->error('Account confirmation did not match. No changes were made.');

            return self::FAILURE;
        }

        $membership = Cache::lock('admin-owner-promotion', 30)->block(10, function () use ($publicId): ?AdminMembership {
            return DB::transaction(function () use ($publicId): ?AdminMembership {
                $user = User::query()->where('public_id', $publicId)->lockForUpdate()->first();
                if ($user === null) {
                    return null;
                }

                $membership = AdminMembership::query()->where('user_id', $user->getKey())->lockForUpdate()->first();
                if ($membership === null
                    || ! $user->is_active
                    || $user->email_verified_at === null
                    || $membership->status !== AdminMembershipStatus::Active
                    || AdminMembership::query()->where('role', 'owner')->where('id', '<>', $membership->getKey())->exists()) {
                    return null;
                }

                if ($membership->role !== 'owner') {
                    $membership->forceFill(['role' => 'owner'])->save();
                }

                return $membership->refresh();
            });
        });

        if ($membership === null) {
            $this->error('The account is missing, inactive, unverified, or conflicts with an existing owner. No changes were made.');

            return self::FAILURE;
        }

        Log::notice('Admin membership role promotion reviewed by CLI', [
            'admin_membership_id' => $membership->getKey(),
            'user_id' => $membership->user_id,
            'role' => $membership->role,
            'reason' => $reason,
        ]);

        $this->info('Owner role is set. The account must log in again to receive the maintenance.cleanup token ability.');

        return self::SUCCESS;
    }
}
