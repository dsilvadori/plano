<?php

namespace App\Services;

use App\Models\Course;
use App\Models\StudentCourse;
use App\Models\User;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class TutoryWebhookProcessor
{
    public function __construct(
        protected CourseAccessResolver $courseAccessResolver,
    ) {}

    public function process(array $payload): WebhookEvent
    {
        $sendAccessNotification = null;

        $event = DB::transaction(function () use ($payload, &$sendAccessNotification) {
            $eventId = $payload['event_id'] ?? $payload['id'] ?? $payload['sessao'] ?? null;
            $eventType = $payload['event_type'] ?? $payload['type'] ?? $payload['evento'] ?? ($payload['purchase']['status'] ?? null);

            $existing = $eventId
                ? WebhookEvent::where('provider', 'tutory')->where('event_id', $eventId)->first()
                : null;

            $approved = $this->isApproved($payload);

            // A Tutory reutiliza o id do pedido em todos os eventos dele (ex.: pagamento pendente e,
            // depois, pagamento aprovado). Só um evento já processado é duplicado; um ignorado
            // precisa ser reprocessado quando a aprovação do mesmo pedido chegar.
            if ($existing && ($existing->status === 'processed' || ($existing->status === 'ignored' && ! $approved))) {
                return $existing;
            }

            $event = $existing ?: WebhookEvent::create([
                'provider' => 'tutory',
                'event_id' => $eventId,
                'event_type' => (string) $eventType,
                'status' => 'received',
                'payload' => $payload,
            ]);

            if (! $approved) {
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
            $previousUser = User::findByEmail($studentData['email'] ?? null);
            $existingUser = $previousUser !== null;
            $wasSubscriber = (bool) $previousUser?->isSubscriber();

            $user = $this->upsertUser(
                studentData: $studentData,
                role: $this->isSubscriptionPayload($payload) ? 'subscriber' : 'student',
                tutoryCustomerId: $this->customerId($payload),
            );

            $linkedCourses = collect();
            $warning = null;

            if ($user->isSubscriber()) {
                $user->renewSubscriberAccess($wasSubscriber);
            }

            if ($user->isStudent()) {
                $courses = $this->coursesForPurchase($payload, $productId);

                foreach ($courses as $course) {
                    $studentCourse = StudentCourse::firstOrCreate(
                        ['user_id' => $user->id, 'course_id' => $course->id],
                        ['source' => 'tutory', 'external_purchase_id' => $purchaseId],
                    );

                    if ($studentCourse->wasRecentlyCreated) {
                        $linkedCourses->push($course);

                        continue;
                    }

                    // Nova compra do mesmo curso: renova 1 ano a partir de agora.
                    // Se o acesso já tinha vencido, o aluno é avisado como num curso novo.
                    $wasExpired = $studentCourse->isExpired();
                    $studentCourse->renew();

                    if ($wasExpired) {
                        $linkedCourses->push($course);
                    }
                }

                $this->removeStaleProvisionalCourses($user, $courses, $purchaseId);
                $warning = $this->visibilityWarning($courses, $this->productName($payload), $productId);
            }

            if (($user->isStudent() || $user->isSubscriber()) && ! $existingUser) {
                $sendAccessNotification = fn () => $user->sendSetPasswordNotification(
                    Password::broker('first_access')->createToken($user),
                );
            } elseif ($user->isStudent() && $linkedCourses->isNotEmpty()) {
                $sendAccessNotification = fn () => $user->sendCourseAccessGrantedNotification($linkedCourses);
            }

            $event->update([
                'event_type' => (string) $eventType,
                'status' => 'processed',
                'processed_at' => now(),
                'payload' => $payload,
                'error_message' => $warning,
            ]);

            return $event;
        });

        if ($sendAccessNotification !== null) {
            $this->sendAccessNotification($event, $sendAccessNotification);
        }

        return $event;
    }

    /**
     * Roda fora da transação: uma falha de SMTP não pode desfazer a compra já registrada.
     */
    protected function sendAccessNotification(WebhookEvent $event, callable $send): void
    {
        try {
            $send();
        } catch (Throwable $exception) {
            report($exception);

            $event->update(['error_message' => collect([
                $event->error_message,
                'Falha ao enviar e-mail de acesso: '.Str::limit($exception->getMessage(), 200),
            ])->filter()->implode(' | ')]);
        }
    }

    /**
     * Alerta para o admin quando a compra não liberou nenhum curso que o aluno consiga ver
     * (produto sem curso ou combo correspondente, ou curso ainda inativo/não publicado).
     */
    protected function visibilityWarning($courses, string $productName, ?string $productId): ?string
    {
        $visible = $courses->contains(fn (Course $course): bool => $course->is_active && $course->status === 'published');

        if ($visible) {
            return null;
        }

        $product = trim($productName.' (id: '.($productId ?: '-').')');

        if ($courses->isEmpty()) {
            return "ATENÇÃO: nenhum curso liberado. Produto {$product} sem curso correspondente.";
        }

        return "ATENÇÃO: aluno vinculado a curso inativo ou não publicado ({$courses->pluck('name')->implode(', ')}). "
            ."Produto {$product}: publique o curso ou ajuste o ID do produto/combo e vincule o aluno manualmente.";
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

    protected function coursesForPurchase(array $payload, ?string $productId)
    {
        $courses = $this->courseAccessResolver->coursesForProduct($productId, $this->productName($payload));

        if ($courses->isNotEmpty()) {
            return $courses->map(function (Course $course) use ($productId): Course {
                $course = $this->courseAccessResolver->publishedEquivalentFor($course) ?? $course;
                $this->courseAccessResolver->rememberProductId($course, $productId);

                return $course;
            });
        }

        return collect(array_filter([$this->createPlaceholderCourseForPurchase($payload, $productId)]));
    }

    protected function createPlaceholderCourseForPurchase(array $payload, ?string $productId): ?Course
    {
        if (! $productId) {
            return null;
        }

        $productName = $this->productName($payload) ?: 'Curso aguardando importação';

        return Course::create([
            'name' => $productName,
            'slug' => $this->uniqueCourseSlug($productName),
            'description' => 'Curso criado automaticamente pelo webhook. Importe a planilha e ative o curso para liberar o acesso ao aluno.',
            'tutory_product_id' => $productId,
            'combo_name' => null,
            'is_active' => false,
        ]);
    }

    protected function removeStaleProvisionalCourses(User $user, $courses, ?string $purchaseId): void
    {
        if (blank($purchaseId)) {
            return;
        }

        $courseIds = $courses
            ->pluck('id')
            ->filter()
            ->values()
            ->all();

        $hasPublishedCourse = $courses->contains(fn (Course $course): bool => $course->is_active && $course->status === 'published');

        if (! $hasPublishedCourse) {
            return;
        }

        StudentCourse::query()
            ->where('user_id', $user->id)
            ->where('source', 'tutory')
            ->where('external_purchase_id', $purchaseId)
            ->when($courseIds !== [], fn ($query) => $query->whereNotIn('course_id', $courseIds))
            ->whereHas('course', fn ($query) => $query
                ->where('is_active', false)
                ->orWhere('status', '!=', 'published'))
            ->delete();
    }

    protected function productName(array $payload): string
    {
        return (string) (
            data_get($payload, 'purchase.product_name')
            ?? data_get($payload, 'product.name')
            ?? data_get($payload, 'produto.nome')
            ?? ''
        );
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
        $user = User::firstOrNewByEmail($studentData['email'] ?? null);

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
