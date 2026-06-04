<?php

namespace App\Http\Controllers;

use App\Services\TutoryWebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TutoryWebhookController extends Controller
{
    public function __invoke(Request $request, TutoryWebhookProcessor $processor): JsonResponse
    {
        $secret = (string) config('services.tutory.webhook_secret');
        $incomingSecret = str_replace('Bearer ', '', (string) ($request->header('Authorization') ?: $request->header('X-Tutory-Secret')));

        if ($secret !== '' && ! hash_equals($secret, $incomingSecret)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $event = $processor->process($request->all());

        return response()->json([
            'status' => $event->status,
            'event_id' => $event->event_id,
        ]);
    }
}
