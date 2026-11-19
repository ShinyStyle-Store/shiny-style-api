<?php

namespace App\Http\Controllers\Api;

use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentProvider;
use App\Exceptions\PaymobConfigurationException;
use App\Exceptions\PaymobRequestException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AdminPaymentReviewResource;
use App\Models\PaymentAttempt;
use App\Services\PaymobTransactionInquiryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

final class AdminPaymentReviewController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $attempts = PaymentAttempt::query()
            ->with(['order:id,order_number', 'paymobTransactions' => fn ($query) => $query
                ->where(fn ($transactions) => $transactions
                    ->where('status', PaymentAttemptStatus::RequiresReview->value)
                    ->orWhereNotNull('review_code')
                    ->orWhere('is_refunded', true)
                    ->orWhere('is_voided', true))
                ->orderBy('id')])
            ->where('provider', PaymentProvider::Paymob->value)
            ->whereIn('method', [PaymentMethod::Card->value, PaymentMethod::Wallet->value])
            ->where(fn ($query) => $query
                ->where('status', PaymentAttemptStatus::RequiresReview->value)
                ->orWhereHas('paymobTransactions', fn ($transactions) => $transactions
                    ->where(fn ($transactions) => $transactions
                        ->where('status', PaymentAttemptStatus::RequiresReview->value)
                        ->orWhereNotNull('review_code')
                        ->orWhere('is_refunded', true)
                        ->orWhere('is_voided', true))))
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return AdminPaymentReviewResource::collection($attempts);
    }

    public function verify(string $public_id, PaymobTransactionInquiryService $inquiry): JsonResponse
    {
        $attempt = PaymentAttempt::query()
            ->where('public_id', $public_id)
            ->where('provider', PaymentProvider::Paymob->value)
            ->whereIn('method', [PaymentMethod::Card->value, PaymentMethod::Wallet->value])
            ->where('status', PaymentAttemptStatus::RequiresReview->value)
            ->firstOrFail();

        try {
            $result = $inquiry->verify($attempt);
        } catch (PaymobConfigurationException|PaymobRequestException) {
            return response()->json([
                'code' => 'transaction_inquiry_unavailable',
                'message' => 'The payment could not be verified at this time. It remains under review.',
            ], 503);
        }

        return response()->json(['data' => $result]);
    }
}
