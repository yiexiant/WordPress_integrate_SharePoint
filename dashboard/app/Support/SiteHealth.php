<?php

namespace App\Support;

/**
 * One plain-language answer to "is this website OK?", shown everywhere a site appears.
 *
 * Levels, worst first: critical (broken, act now) > warning (needs a look) > unknown > ok.
 */
final class SiteHealth
{
    public const CRITICAL = 'critical';

    public const WARNING = 'warning';

    public const UNKNOWN = 'unknown';

    public const OK = 'ok';

    public function __construct(
        public readonly string $level,
        public readonly string $label,
        public readonly string $reason,
    ) {}

    public function needsAttention(): bool
    {
        return in_array($this->level, [self::CRITICAL, self::WARNING], true);
    }

    /** Sort key: most urgent first. */
    public function rank(): int
    {
        return match ($this->level) {
            self::CRITICAL => 0,
            self::WARNING => 1,
            self::UNKNOWN => 2,
            default => 3,
        };
    }
}
