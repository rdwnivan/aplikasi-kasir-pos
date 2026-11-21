<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * An inclusive range of calendar days in the store's timezone (Asia/Jakarta), read from
 * ?from=YYYY-MM-DD&to=YYYY-MM-DD. Bad or missing input falls back to the last N days.
 */
final class DateRange
{
    public const TIMEZONE = 'Asia/Jakarta';

    private function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function fromRequest(Request $request, int $defaultDays = 30): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $to = self::parse($request->query('to')) ?? $today;
        $from = self::parse($request->query('from')) ?? $to->subDays($defaultDays - 1);

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        // Guard against accidental decade-long queries.
        if ($from->diffInDays($to) > 366 * 3) {
            $from = $to->subYears(3);
        }

        return new self($from, $to);
    }

    public static function of(string $from, string $to): self
    {
        return new self(
            CarbonImmutable::parse($from, self::TIMEZONE)->startOfDay(),
            CarbonImmutable::parse($to, self::TIMEZONE)->startOfDay(),
        );
    }

    /**
     * Start of the first day, expressed in the app timezone, which is how Laravel writes
     * timestamp columns. Compare with `>=`.
     */
    public function startsAt(): CarbonImmutable
    {
        return $this->from->startOfDay()->setTimezone(self::appTimezone());
    }

    /** Start of the day after the last one, in the app timezone. Compare with `<`. */
    public function endsBefore(): CarbonImmutable
    {
        return $this->to->addDay()->startOfDay()->setTimezone(self::appTimezone());
    }

    private static function appTimezone(): string
    {
        $timezone = config('app.timezone');

        return is_string($timezone) ? $timezone : 'UTC';
    }

    /** @return array{from: string, to: string} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString()];
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('Y-m-d', $value, self::TIMEZONE);

        return $date === null || $date->format('Y-m-d') !== $value ? null : $date->startOfDay();
    }
}
