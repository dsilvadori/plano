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
            $eventId = $payload['event_id'] ?? $payload['id'] ?? $payload['sessao'] ?? null;
            $eventType = $payload['event_type'] ?? $payload['type'] ?? $payload['evento'] ?? ($payload['purchase']['status'] ?? null);

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

            $studentData = $this->customerData($payload);
            $purchaseData = $payload['purchase'] ?? [];
            $productId = $purchaseData['product_id'] ?? data_get($payload, 'product.id') ?? data_get($payload, 'produto.id');
            $purchaseId = $purchaseData['id'] ?? $payload['id'] ?? null;

            $user = $this->upsertUser(
                studentData: $studentData,
                role: $this->isSubscriptionPayload($payload) ? 'subscriber' : 'student',
                tutoryCustomerId: $this->customerId($payload),
            );

            if ($user->isStudent()) {
                $course = $this->courseForPurchase($payload, $productId);

                if ($course) {
                    StudentCourse::firstOrCreate(
                        ['user_id' => $user->id, 'course_id' => $course->id],
                        ['source' => 'tutory', 'external_purchase_id' => $purchaseId],
                    );
                }
            }

            if ($user->isStudent() || $user->isSubscriber()) {
                $token = Password::broker()->createToken($user);
                $user->sendSetPasswordNotification($token);
            }

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
        $eventName = $payload['evento'] ?? null;
        $purchaseStatus = strtolower((string) data_get($payload, 'purchase.status', $payload['status'] ?? ''));

        return in_array($eventType, ['purchase.approved', 'purchase_approved'], true)
            || $eventName === 'pagamento_aprovado'
            || in_array($purchaseStatus, ['approved', 'paid'], true);
    }

    protected function customerData(array $payload): array
    {
        return $payload['purchase']['student']
            ?? $payload['customer']
            ?? [
                'name' => $payload['nome'] ?? null,
                'email' => $payload['email'] ?? null,
                'phone' => $payload['telefone'] ?? null,
            ];
    }

    protected function customerId(array $payload): ?string
    {
        return $payload['customer_id']
            ?? data_get($payload, 'metadados.assinatura_id')
            ?? $payload['sessao']
            ?? null;
    }

    protected function isSubscriptionPayload(array $payload): bool
    {
        $productName = Str::lower(implode(' ', array_filter([
            data_get($payload, 'produto.nome'),
            data_get($payload, 'product.name'),
            data_get($payload, 'purchase.product_name'),
        ])));

        return Str::contains($productName, 'assinatura');
    }

    protected function courseForPurchase(array $payload, ?string $productId): ?Course
    {
        if (! $productId) {
            return null;
        }

        $course = Course::where('tutory_product_id', $productId)->first();

        if ($course) {
            return $course;
        }

        $productName = data_get($payload, 'purchase.product_name')
            ?? data_get($payload, 'product.name')
            ?? data_get($payload, 'produto.nome')
            ?? 'Curso aguardando importação';

        return Course::create([
            'name' => $productName,
            'slug' => $this->uniqueCourseSlug($productName),
            'description' => 'Curso criado automaticamente pelo webhook. Importe a planilha e ative o curso para liberar o acesso ao aluno.',
            'tutory_product_id' => $productId,
            'is_active' => false,
        ]);
    }

    protected function uniqueCourseSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'curso';
        $slug = $baseSlug;
        $suffix = 2;

        while (Course::where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }

    protected function upsertUser(array $studentData, string $role, ?string $tutoryCustomerId): User
    {
        $email = strtolower((string) ($studentData['email'] ?? ''));
        $user = User::firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->password = Str::password(24);
            $user->role = $role;
        } elseif (! $user->isAdmin()) {
            $user->role = $role === 'subscriber' ? 'subscriber' : $user->role;
        }

        $user->forceFill([
            'name' => $studentData['name'] ?? $user->name ?? 'Aluno Vencendo Concursos',
            'phone' => $studentData['phone'] ?? $user->phone,
            'tutory_customer_id' => $tutoryCustomerId ?? $user->tutory_customer_id,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        return $user;
    }
}
