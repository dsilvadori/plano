<?php

namespace App\Services;

use App\Models\Course;
use App\Models\StudentCourse;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class TutoryWebhookProcessor
{
    public function process(array $payload): WebhookEvent
    {
        return DB::transaction(function () use ($payload) {
            $eventId = $payload['event_id'] ?? $payload['id'] ?? null;
            $eventType = $payload['event_type'] ?? $payload['type'] ?? ($payload['purchase']['status'] ?? null);

            $existing = $eventId
                ? WebhookEvent::where('provider', 'tutory')->where('event_id', $eventId)->first()
                : null;

            if ($existing && in_array($existing->status, ['processed', 'ignored'], true)) {
                return $existing;
            }

            $event = $existing ?: WebhookEvent::create([
                'provider' => 'tutory',
                'event_id' => $eventId,
                'event_type' => (string) $eventType,
                'status' => 'received',
                'payload' => $payload,
            ]);

            if (! $this->isApproved($payload)) {
                $event->update([
                    'status' => 'ignored',
                    'processed_at' => now(),
                ]);

                return $event;
            }

            $studentData = $payload['purchase']['student'] ?? $payload['customer'] ?? [];
            $purchaseData = $payload['purchase'] ?? [];
            $productId = $purchaseData['product_id'] ?? data_get($payload, 'product.id');
            $purchaseId = $purchaseData['id'] ?? null;

            $user = User::firstOrCreate(
                ['email' => strtolower((string) ($studentData['email'] ?? ''))],
                [
                    'name' => $studentData['name'] ?? 'Aluno Vencendo Concursos',
                    'password' => Str::password(24),
                    'phone' => $studentData['phone'] ?? null,
                    'tutory_customer_id' => $payload['customer_id'] ?? null,
                    'role' => 'student',
                ],
            );

            $user->forceFill([
                'name' => $studentData['name'] ?? $user->name,
                'phone' => $studentData['phone'] ?? $user->phone,
                'role' => 'student',
            ])->save();

            $course = $productId ? Course::where('tutory_product_id', $productId)->first() : null;

            if ($course) {
                StudentCourse::firstOrCreate(
                    ['user_id' => $user->id, 'course_id' => $course->id],
                    ['source' => 'tutory', 'external_purchase_id' => $purchaseId],
                );
            }

            $token = Password::broker()->createToken($user);
            $user->sendSetPasswordNotification($token);

            $event->update([
                'event_type' => (string) $eventType,
                'status' => 'processed',
                'processed_at' => now(),
                'payload' => $payload,
            ]);

            return $event;
        });
    }

    public function isApproved(array $payload): bool
    {
        $eventType = $payload['event_type'] ?? $payload['type'] ?? null;
        $purchaseStatus = strtolower((string) data_get($payload, 'purchase.status', $payload['status'] ?? ''));

        return in_array($eventType, ['purchase.approved', 'purchase_approved'], true)
            || $purchaseStatus === 'approved';
    }
}
