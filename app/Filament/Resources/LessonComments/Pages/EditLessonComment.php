<?php

namespace App\Filament\Resources\LessonComments\Pages;

use App\Filament\Resources\LessonComments\LessonCommentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLessonComment extends EditRecord
{
    protected static string $resource = LessonCommentResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (filled($data['answer'] ?? null)) {
            $data['status'] = 'answered';
            $data['answered_by_user_id'] = auth()->id();
            $data['answered_at'] = now();
        } else {
            $data['status'] = 'open';
            $data['answered_by_user_id'] = null;
            $data['answered_at'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->label('Excluir'),
        ];
    }
}
