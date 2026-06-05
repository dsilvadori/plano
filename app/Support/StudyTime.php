<?php

namespace App\Support;

class StudyTime
{
    public const MAX_DAILY_MINUTES = 480;

    public static function parseToMinutes(int|string|null $value): int
    {
        if (is_int($value)) {
            return min(self::MAX_DAILY_MINUTES, max(0, $value));
        }

        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^\d{1,2}$/', $value) === 1) {
            return min(self::MAX_DAILY_MINUTES, ((int) $value) * 60);
        }

        if (! preg_match('/^(?<hours>\d{1,2}):(?<minutes>\d{2})$/', $value, $matches)) {
            return 0;
        }

        $hours = (int) $matches['hours'];
        $minutes = (int) $matches['minutes'];

        if ($minutes >= 60) {
            return 0;
        }

        return min(self::MAX_DAILY_MINUTES, ($hours * 60) + $minutes);
    }

    public static function formatMinutes(int $minutes): string
    {
        $minutes = max(0, $minutes);
        $hours = intdiv($minutes, 60);
        $remainingMinutes = $minutes % 60;

        return sprintf('%d:%02d', $hours, $remainingMinutes);
    }

    public static function normalizeForInput(int|string|null $value): string
    {
        return self::formatMinutes(self::parseToMinutes($value));
    }
}
