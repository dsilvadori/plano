<?php

$defaults = require base_path('vendor/livewire/livewire/config/livewire.php');

return array_replace_recursive($defaults, [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        // O limite precisa comportar o upload direto de vídeos ao Panda (PANDA_DIRECT_UPLOAD_MAX_MB).
        // Cada campo de upload continua validando o próprio tamanho máximo.
        'rules' => ['required', 'file', 'max:'.max(51200, (int) env('PANDA_DIRECT_UPLOAD_MAX_MB', 2048) * 1024)],
        'directory' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DIRECTORY', 'livewire-tmp'),
        'middleware' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_MIDDLEWARE', 'throttle:120,1'),
        'max_upload_time' => (int) env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_MAX_TIME', 15),
        'cleanup' => true,
    ],
]);
