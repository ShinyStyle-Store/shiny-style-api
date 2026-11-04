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
            ->with(['order:id,order_number'])
            ->where('provider', PaymentProvider::Paymob->value)
            ->where('method', PaymentMethod::Card->value)
            ->where('status', PaymentAttemptStatus::RequiresReview->value)
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
            ->where('method', PaymentMethod::Card->value)
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
