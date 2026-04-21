<?php

namespace App\Enums;

/**
 * Lifecycle of a single crawl run. Matches the status column values shown
 * in the Dashboard "Recent crawl runs" table (Screen 1) and the Crawl
 * History log (Screen 4) per the proposal PDF.
 *
 * Transitions:
 *   Queued  → Running   (when CrawlSiteJob::handle starts)
 *   Running → Complete  (all pages captured cleanly)
 *   Running → Partial   (some pages failed but crawl finished — e.g. 404s)
 *   Running → Failed    (fatal error: DNS, timeout, job exception)
 */
enum CrawlStatus: string
{
    case Queued   = 'queued';
    case Running  = 'running';
    case Complete = 'complete';
    case Partial  = 'partial';
    case Failed   = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued   => 'Queued',
            self::Running  => 'Running',
            self::Complete => 'Done',    // matches PDF wording ("Done" not "Complete")
            self::Partial  => 'Partial',
            self::Failed   => 'Failed',
        };
    }

    /** Color tag used in Filament table status columns. */
    public function color(): string
    {
        return match ($this) {
            self::Queued   => 'gray',
            self::Running  => 'info',
            self::Complete => 'success',
            self::Partial  => 'warning',
            self::Failed   => 'danger',
        };
    }
}
