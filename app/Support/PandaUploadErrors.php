<?php

namespace App\Support;

use Throwable;

/**
 * Classifica erros de upload para o Panda entre temporários (vale tentar de novo)
 * e definitivos. Compartilhado pelos jobs de upload Drive -> Panda e upload direto.
 */
class PandaUploadErrors
{
    public static function isConcurrencyLimit(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'upload concurrency limit')
            || str_contains($message, 'reached the upload concurrency')
            || str_contains($message, 'errcode":10')
            || str_contains($message, 'errcode": 10');
    }

    public static function isRetryable(Throwable $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return self::isConcurrencyLimit($exception)
            || str_contains($message, 'status 408')
            || str_contains($message, 'status 409')
            || str_contains($message, 'status 425')
            || str_contains($message, 'status 429')
            || str_contains($message, 'status 500')
            || str_contains($message, 'status 502')
            || str_contains($message, 'status 503')
            || str_contains($message, 'status 504')
            || str_contains($message, 'timeout')
            || str_contains($message, 'timed out')
            || str_contains($message, 'connection')
            || str_contains($message, 'temporarily')
            || str_contains($message, 'try again')
            || str_contains($message, 'too many requests')
            || str_contains($message, 'rate limit')
            || str_contains($message, 'server error');
    }
}
