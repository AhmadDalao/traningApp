<?php

namespace App\Services;

/** Deliberately narrow; free-text prescriptions remain valid in the planner. */
class ProgressionPrescription
{
    public function reps(?string $text): ?array
    {
        $text = str_replace(['–', '—', '−'], '-', trim($text ?? ''));
        if (strlen($text) > 100 || ! preg_match('/\A(\d+)\s*(?:-\s*(\d+))?(?:\s*reps)?\z/iu', $text, $match)) {
            return null;
        }
        $min = (int) $match[1];
        $max = isset($match[2]) && $match[2] !== '' ? (int) $match[2] : $min;

        return $min >= 1 && $max >= $min && $max <= config('training.limits.reps') ? ['min' => $min, 'max' => $max] : null;
    }

    public function duration(?string $text): ?int
    {
        $text = trim($text ?? '');
        if (strlen($text) > 100) {
            return null;
        }
        if (preg_match('/\A(\d{1,5})\s*(sec(?:ond)?s?|min(?:ute)?s?)\z/iu', $text, $match)) {
            $seconds = (int) $match[1] * (str_starts_with(strtolower($match[2]), 'min') ? 60 : 1);
        } elseif (preg_match('/\A(\d{1,4}):([0-5]\d)\z/', $text, $match)) {
            $seconds = (int) $match[1] * 60 + (int) $match[2];
        } else {
            return null;
        }

        return $seconds > 0 && $seconds <= config('training.limits.duration_seconds') ? $seconds : null;
    }

    public function repText(array $range): string
    {
        return $range['min'] === $range['max'] ? (string) $range['min'] : $range['min'].'-'.$range['max'];
    }
}
