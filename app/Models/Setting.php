<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-row settings model driving Admin Screen 7 (Settings).
 * Access via Setting::current() — guarantees one row exists and returns it.
 *
 * @property string $storage_driver           "local" | "s3"
 * @property string $retention_policy         "all" | "90_days" | "30_days"
 * @property int    $storage_limit_gb
 * @property ?string $email_recipients        comma-separated emails
 * @property ?string $slack_webhook_url
 * @property bool   $notify_on_crawl_failure
 * @property bool   $notify_on_storage_warning
 * @property bool   $notify_on_crawl_success
 */
class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'storage_driver',
        'retention_policy',
        'storage_limit_gb',
        'default_retention_months',
        'cleanup_hour',
        'cleanup_last_run_at',
        'email_recipients',
        'slack_webhook_url',
        'notify_on_crawl_failure',
        'notify_on_storage_warning',
        'notify_on_crawl_success',
    ];

    /**
     * Model-level defaults so firstOrCreate() returns a fully-populated
     * instance without having to ->refresh() from DB. These mirror the
     * migration defaults 1:1.
     */
    protected $attributes = [
        'storage_driver'             => 'local',
        'retention_policy'           => 'all',
        'storage_limit_gb'           => 50,
        'default_retention_months'   => 3,
        'cleanup_hour'               => 3,
        'notify_on_crawl_failure'    => true,
        'notify_on_storage_warning'  => true,
        'notify_on_crawl_success'    => false,
    ];

    protected function casts(): array
    {
        return [
            'storage_limit_gb'          => 'integer',
            'default_retention_months'  => 'integer',
            'cleanup_hour'              => 'integer',
            'cleanup_last_run_at'       => 'datetime',
            'notify_on_crawl_failure'   => 'boolean',
            'notify_on_storage_warning' => 'boolean',
            'notify_on_crawl_success'   => 'boolean',
        ];
    }

    /** Singleton accessor — creates defaults on first call. */
    public static function current(): self
    {
        return static::firstOrCreate([]);
    }

    /** @return array<int,string>  parsed email recipients list */
    public function emailRecipientsList(): array
    {
        if (blank($this->email_recipients)) {
            return [];
        }
        return collect(preg_split('/[,\s]+/', (string) $this->email_recipients))
            ->filter()
            ->values()
            ->all();
    }
}
