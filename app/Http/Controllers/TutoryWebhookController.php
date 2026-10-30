<?php

namespace App\Http\Controllers;

use App\Services\TutoryWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutoryWebhookController extends Controller
{
    public function __invoke(Request $request, TutoryWebhookProcessor $processor, ?string $secret = null): JsonResponse
    {
        $configuredSecret = (string) config('services.tutory.webhook_secret');
        $incomingSecret = $this->incomingSecret($request, $secret);

        if ($configuredSecret !== '' && ! hash_equals($configuredSecret, $incomingSecret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = $processor->process($request->all());

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->event_id,
        ]);
    }

    protected function incomingSecret(Request $request, ?string $routeSecret): string
    {
        $authorization = (string) $request->header('Authorization');

        if (str_starts_with($authorization, 'Bearer ')) {
            return substr($authorization, 7);
        }

        return (string) (
            $request->header('X-Tutory-Secret')
            ?: $request->query('secret')
            ?: $request->query('token')
            ?: $routeSecret
            ?: $authorization
        );
    }
}
