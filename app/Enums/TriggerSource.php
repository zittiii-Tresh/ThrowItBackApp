<?php

namespace App\Enums;

/**
 * Who/what initiated a crawl run. Drives the "Triggered" column in the
 * Crawl History table (Admin Screen 4).
 */
enum TriggerSource: string
{
    case Scheduler = 'scheduler';   // Phase 4 scheduled tick
    case Manual    = 'manual';      // "Crawl now" button on a site row

    public function label(): string
    {
        return match ($this) {
            self::Scheduler => 'Scheduler',
            self::Manual    => 'Manual',
        };
    }
}
