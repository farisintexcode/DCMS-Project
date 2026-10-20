<?php

namespace App\Http\Controllers;

use App\Actions\Communications\ReceiveSesNotification;
use App\Models\IntegrationAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Signed SNS only, deliberately outside user-session authorization and disabled by default. */
class SesNotificationController extends Controller
{
    public function __invoke(Request $request, string $account, ReceiveSesNotification $receive): JsonResponse
    {
        abort_unless(config('communications.ses_receipts_enabled'), 404);
        abort_if(app()->isProduction() && ! $request->isSecure(), 400);
        abort_if(strlen($request->getContent()) > 262144, 413);
        $provider = IntegrationAccount::query()->where('public_id', $account)->where('provider_code', 'amazon_ses')->where('channel', 'email')->whereNotNull('capability_verified_at')->firstOrFail();
        try {
            $message = json_decode($request->getContent(), true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(400);
        }
        abort_unless(is_array($message), 400);
        $receive->receive($provider, $message);

        return response()->json(['status' => 'received'], 202);
    }
}
