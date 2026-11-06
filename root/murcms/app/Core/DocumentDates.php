<?php
declare(strict_types=1);

namespace Mur\Core;

if (!defined('MUR_CMS_BOOTSTRAPPED')) {
    http_response_code(403);
    exit('No direct script access allowed.');
}

final class DocumentDates
{
    public static function parse(string $value, string $timezone): ?\DateTimeImmutable
    {
        try {
            $zone = new \DateTimeZone($timezone);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $zone);
                // PHP normalizes invalid dates and DST gaps; neither is a valid edit.
                return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date : null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value)) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $zone);
                return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
                $date = new \DateTimeImmutable($value);
                $errors = \DateTimeImmutable::getLastErrors();
                return $errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0) ? $date : null;
            }
        } catch (\Throwable) {
        }
        return null;
    }

    public static function fromFields(array $fields, string $timezone): string
    {
        $parts = [];
        foreach (['year', 'month', 'day', 'hour', 'minute', 'second'] as $key) {
            $value = $fields[$key] ?? null;
            if (!is_scalar($value) || !preg_match('/^\d{1,4}$/D', (string) $value)) {
                throw new \InvalidArgumentException('Заполните все шесть полей даты изменения.');
            }
            $parts[] = (int) $value;
        }
        [$year, $month, $day, $hour, $minute, $second] = $parts;
        $value = sprintf('%04d-%02d-%02d %02d:%02d:%02d', ...$parts);
        if ($year < 1 || $year > 9999 || $hour > 23 || $minute > 59 || $second > 59
            || !checkdate($month, $day, $year) || self::parse($value, $timezone) === null) {
            throw new \InvalidArgumentException('Некорректная дата или несуществующее местное время в часовом поясе сайта.');
        }
        return $value;
    }
}
