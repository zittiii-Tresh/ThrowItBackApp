<?php

namespace App\Enums;

/**
 * Crawl frequency type — the 4 options shown in the "Add site" modal
 * (Admin Screen 3). Each maps to a shape of `frequency_config` JSON on
 * the Site model. See App\Support\Schedule::nextRunFor() for how each
 * resolves into a concrete next_run_at timestamp.
 */
enum FrequencyType: string
{
    case Daily         = 'daily';
    case EveryNDays    = 'every_n_days';
    case SpecificDays  = 'specific_days';

    /**
     * Human-readable label used in Filament forms + the All sites table.
     */
    public function label(): string
    {
        return match ($this) {
            self::Daily        => 'Daily',
            self::EveryNDays   => 'Every N days',
            self::SpecificDays => 'Specific days',
        };
    }

    /** @return array<string,string> keyed by value → label, for form selects. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
