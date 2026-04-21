<?php

namespace App\Enums;

/**
 * Notification channels for crawl failures / storage warnings — surfaced
 * in the "Add site" modal (Admin Screen 3) and global Settings (Screen 7).
 * Actual dispatch lives in App\Notifications in Phase 5.
 */
enum NotifyChannel: string
{
    case Email = 'email';
    case Slack = 'slack';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Slack => 'Slack',
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }
}
