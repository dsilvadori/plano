<?php

$defaults = require base_path('vendor/livewire/livewire/config/livewire.php');

return array_replace_recursive($defaults, [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK', 'local'),
        'rules' => ['required', 'file', 'max:51200'],
        'directory' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_DIRECTORY', 'livewire-tmp'),
        'middleware' => env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_MIDDLEWARE', 'throttle:120,1'),
        'max_upload_time' => (int) env('LIVEWIRE_TEMPORARY_FILE_UPLOAD_MAX_TIME', 15),
        'cleanup' => true,
    ],
]);
