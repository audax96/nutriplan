<?php
declare(strict_types=1);

namespace NutriPlan;

/** Small helpers for reading untrusted JSON values without warnings or type errors. */
final class Input
{
    /** A string from JSON: strings and numbers pass, anything else (arrays, objects, null, bools) becomes ''. */
    public static function str(mixed $v): string
    {
        if (is_string($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return (string)$v;
        }
        return '';
    }

    /** A short, single-line display text: trimmed, with control characters removed. */
    public static function text(mixed $v): string
    {
        return trim(preg_replace('/[\p{Cc}\x{2028}\x{2029}]+/u', ' ', self::str($v)) ?? '');
    }

    /**
     * Allergies and dislikes as sent by the app: [{name, kind}, ...]. A plain string is read as
     * an allergy, so a malformed request can never silently drop one.
     *
     * @return array{0: array[], 1: ?string} the cleaned list and an error message (or null)
     */
    public static function exclusions(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [[], null];
        }
        if (!is_array($raw)) {
            return [[], 'Allergies and dislikes must be a list.'];
        }
        $out = [];
        foreach ($raw as $e) {
            if (is_string($e)) {
                $e = ['name' => $e, 'kind' => 'allergy'];
            }
            if (!is_array($e)) {
                return [[], 'Allergies and dislikes must be a list of names.'];
            }
            if (isset($e['name']) && !is_string($e['name'])) {
                return [[], 'Allergy and dislike names must be text.'];
            }
            $name = self::text($e['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if (mb_strlen($name) > 80) {
                return [[], 'Allergy and dislike names must be 80 characters or fewer.'];
            }
            $out[] = ['name' => $name, 'kind' => ($e['kind'] ?? 'allergy') === 'dislike' ? 'dislike' : 'allergy'];
        }
        if (count($out) > 30) {
            return [[], 'Please list at most 30 allergies and dislikes.'];
        }
        return [$out, null];
    }
}
