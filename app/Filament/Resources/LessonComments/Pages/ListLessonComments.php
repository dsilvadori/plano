<?php

namespace App\Filament\Resources\LessonComments\Pages;

use App\Filament\Resources\LessonComments\LessonCommentResource;
use Filament\Resources\Pages\ListRecords;

class ListLessonComments extends ListRecords
{
    protected static string $resource = LessonCommentResource::class;
}
