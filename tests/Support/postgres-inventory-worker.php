<?php

use App\Exceptions\OrderReturnConflictException;
use App\Exceptions\PhysicalReturnConflictException;
use App\Exceptions\ProductConversionConflictException;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReturnReceipt;
use App\Models\SellableItem;
use App\Services\AdminProductConversionService;
use App\Services\GuestOrderService;
use App\Services\PhysicalReturnService;
use App\Services\ReturnInventoryTransferService;
use App\Services\SellableItemManagementService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\DedicatedPostgresDatabase;
use Throwable;

$root = dirname(__DIR__, 2);
chdir($root);

$job = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, flags: JSON_THROW_ON_ERROR);
$application = require $root.'/bootstrap/app.php';
$application->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
DedicatedPostgresDatabase::assertConfiguredAndConnected();
DB::connection()->selectOne('select set_config(?, ?, false)', ['application_name', $job['application_name']]);

$writeJson = static function (string $path, array $payload): void {
    $temporaryPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
    file_put_contents($temporaryPath, json_encode($payload, JSON_THROW_ON_ERROR));
    if (! rename($temporaryPath, $path)) {
        @unlink($temporaryPath);
        throw new RuntimeException('Could not publish a worker signal.');
    }
};

$waitForFile = static function (string $path, int $timeoutSeconds): void {
    $deadline = microtime(true) + $timeoutSeconds;
    while (! is_file($path)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Timed out waiting for a parent worker signal.');
        }
        usleep(20_000);
    }
};

$execute = static function (array $operation): array {
    try {
        $result = match ($operation['type']) {
            'convert' => app(AdminProductConversionService::class)->convert(
                Product::query()->findOrFail($operation['product_id']),
                (int) $operation['source_id'],
                $operation['payload'],
                $operation['idempotency_key'],
            ),
            'order' => app(GuestOrderService::class)->create($operation['payload'], $operation['idempotency_key']),
            'transfer' => app(ReturnInventoryTransferService::class)->transfer(
                Order::query()->findOrFail($operation['order_id']),
                (int) $operation['receipt_id'],
                (int) $operation['receipt_item_id'],
                [
                    'target_sellable_item_id' => (int) $operation['target_id'],
                    'quantity' => (int) $operation['quantity'],
                    'reason' => $operation['reason'],
                ],
                (int) $operation['actor_id'],
                $operation['idempotency_key'],
            ),
            'correct' => app(PhysicalReturnService::class)->correct(
                ReturnReceipt::query()->findOrFail($operation['receipt_id']),
                $operation['payload'],
                (int) $operation['actor_id'],
                $operation['idempotency_key'],
            ),
            'reverse_receipt' => app(PhysicalReturnService::class)->reverse(
                ReturnReceipt::query()->findOrFail($operation['receipt_id']),
                $operation['payload'],
                (int) $operation['actor_id'],
                $operation['idempotency_key'],
            ),
            'reverse_transfer' => app(ReturnInventoryTransferService::class)->reverse(
                Order::query()->findOrFail($operation['order_id']),
                (int) $operation['receipt_id'],
                (int) $operation['receipt_item_id'],
                (int) $operation['transfer_id'],
                ['reversal_reason' => $operation['reason']],
                (int) $operation['actor_id'],
                $operation['idempotency_key'],
            ),
            'update_source' => app(SellableItemManagementService::class)->update(
                SellableItem::query()->findOrFail($operation['source_id']),
                ['stock_quantity' => (int) $operation['stock_quantity']],
            ),
            default => throw new RuntimeException('Unsupported worker operation.'),
        };

        return [
            'ok' => true,
            'replayed' => (bool) ($result['replayed'] ?? false),
            'result_id' => (int) ($result['order']->id ?? $result['transfer']->id ?? $result['receipt']->id ?? 0),
        ];
    } catch (ProductConversionConflictException|PhysicalReturnConflictException|OrderReturnConflictException $exception) {
        return ['ok' => false, 'code' => $exception->errorCode, 'message' => $exception->getMessage()];
    } catch (ValidationException $exception) {
        return ['ok' => false, 'code' => 'validation_failed', 'message' => implode(' ', $exception->validator->errors()->all())];
    } catch (QueryException $exception) {
        return ['ok' => false, 'code' => (string) ($exception->errorInfo[0] ?? $exception->getCode()), 'message' => 'Database query failed.'];
    } catch (Throwable $exception) {
        return ['ok' => false, 'code' => get_class($exception), 'message' => $exception->getMessage()];
    }
};

try {
    $writeJson($job['ready_path'], ['ready' => true]);
    $waitForFile($job['go_path'], (int) $job['timeout_seconds']);
    if (isset($job['lock_id'])) {
        DB::beginTransaction();
        $lockTable = $job['lock_table'] ?? 'products';
        if (! in_array($lockTable, ['products', 'orders', 'sellable_items'], true)) {
            throw new RuntimeException('Unsupported concurrency lock table.');
        }
        DB::table($lockTable)->where('id', $job['lock_id'])->lockForUpdate()->first();
        $writeJson($job['locked_path'], ['locked' => true]);
        $waitForFile($job['release_path'], (int) $job['timeout_seconds']);
    }

    $result = $execute($job['operation']);
    if (DB::transactionLevel() > 0) {
        DB::commit();
    }
    $writeJson($job['result_path'], $result);
    exit($result['ok'] ? 0 : 0);
} catch (Throwable $exception) {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    $writeJson($job['result_path'], [
        'ok' => false,
        'code' => get_class($exception),
        'message' => $exception->getMessage(),
    ]);
    exit(1);
}
