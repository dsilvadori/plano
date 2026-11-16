<?php

namespace App\Filament\Resources\Lessons;

use App\Models\Lesson;
use App\Services\LessonDirectVideoUploader;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Arr;

/**
 * Campos do envio direto de vídeo ao Panda, compartilhados entre a edição
 * de uma aula e a criação de uma aula nova a partir de um vídeo.
 */
class PandaVideoUploadForm
{
    public const ACCEPTED_VIDEO_TYPES = [
        'video/mp4',
        'video/quicktime',
        'video/x-matroska',
        'video/webm',
        'video/x-msvideo',
        'video/mpeg',
        'video/x-m4v',
    ];

    public static function fields(?Lesson $lesson = null, bool $folderRequired = true): array
    {
        $maxMb = (int) (LessonDirectVideoUploader::maxSizeKilobytes() / 1024);

        return [
            FileUpload::make('video_file')
                ->label('Arquivo de vídeo')
                ->disk(LessonDirectVideoUploader::disk())
                ->directory(LessonDirectVideoUploader::directory())
                ->visibility('private')
                ->acceptedFileTypes(self::ACCEPTED_VIDEO_TYPES)
                ->maxSize(LessonDirectVideoUploader::maxSizeKilobytes())
                ->storeFileNamesIn('video_file_name')
                ->helperText("MP4, MOV, MKV, WEBM, AVI ou MPEG, até {$maxMb} MB. Aguarde a barra de envio terminar antes de confirmar.")
                ->required(),
            TextInput::make('panda_title')
                ->label('Título do vídeo no Panda')
                ->default($lesson?->title)
                ->placeholder('Usa o título da aula quando vazio')
                ->maxLength(255),
            TextInput::make('panda_folder_reference')
                ->label('Pasta no Panda')
                ->default(fn (LessonDirectVideoUploader $uploader): ?string => $uploader->defaultFolderReferenceFor($lesson))
                ->helperText($folderRequired
                    ? 'Aceita URL completa, ID ou nome da pasta. O envio para a raiz do Panda é bloqueado.'
                    : 'Aceita URL completa, ID ou nome da pasta. Se ficar vazio, usa a pasta Panda da subpasta ou da pasta escolhida. O envio para a raiz do Panda é bloqueado.')
                ->required($folderRequired)
                ->maxLength(2048),
            Toggle::make('create_panda_folder')
                ->label('Criar a pasta no Panda se ela não existir')
                ->helperText('Só vale quando a pasta for informada pelo nome.')
                ->default(false),
        ];
    }

    public static function storedPath(array $data): ?string
    {
        $value = $data['video_file'] ?? null;

        if (is_array($value)) {
            $value = Arr::first($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function originalName(array $data): ?string
    {
        $value = $data['video_file_name'] ?? null;

        if (is_array($value)) {
            $value = Arr::first($value);
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function statusDescription(?Lesson $lesson): ?string
    {
        $upload = data_get($lesson?->metadata, 'panda_direct_upload');

        if (! is_array($upload)) {
            return null;
        }

        $name = $upload['original_name'] ?? 'vídeo';

        return match ($upload['status'] ?? null) {
            'queued' => "Envio anterior na fila: {$name}.".(filled($upload['error'] ?? null) ? ' Último erro: '.$upload['error'] : ''),
            'uploading' => "Envio em andamento: {$name}.",
            'failed' => "Último envio falhou ({$name}): ".($upload['error'] ?? 'erro desconhecido'),
            'uploaded' => "Último envio concluído: {$name}.",
            default => null,
        };
    }
}
