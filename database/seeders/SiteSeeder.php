<?php

namespace Database\Seeders;

use App\Enums\FrequencyType;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * Seeds the 5 example SAS client sites from the proposal PDF so a fresh
 * dev database has realistic data to verify the admin UI against.
 *
 * Uses updateOrCreate so re-running is safe — existing rows get their
 * schedule refreshed instead of throwing unique-constraint errors.
 */
class SiteSeeder extends Seeder
{
    public function run(): void
    {
        $sites = [
            [
                'name'     => 'acme.com',
                'base_url' => 'https://acme.com',
                'freq'     => FrequencyType::EveryNDays,
                'config'   => ['days' => 2],
                'notify'   => ['email'],
            ],
            [
                'name'     => 'blog.acme.com',
                'base_url' => 'https://blog.acme.com',
                'freq'     => FrequencyType::SpecificDays,
                'config'   => ['days' => ['mon', 'wed', 'fri'], 'time' => '08:00'],
                'notify'   => ['email', 'slack'],
            ],
            [
                'name'     => 'shop.acme.com',
                'base_url' => 'https://shop.acme.com',
                'freq'     => FrequencyType::Daily,
                'config'   => null,
                'notify'   => ['email', 'slack'],
                'active'   => false, // paused — simulates the "failed, auto-paused" state from the PDF
            ],
            [
                'name'     => 'docs.acme.com',
                'base_url' => 'https://docs.acme.com',
                'freq'     => FrequencyType::SpecificDays,
                'config'   => ['days' => ['mon'], 'time' => '00:00'], // weekly on Mondays
                'notify'   => ['email'],
            ],
            [
                'name'     => 'portal.acme.com',
                'base_url' => 'https://portal.acme.com',
                'freq'     => FrequencyType::SpecificDays,
                'config'   => ['days' => ['mon', 'wed', 'fri'], 'time' => '22:00'],
                'notify'   => ['email', 'slack'],
            ],
        ];

        foreach ($sites as $s) {
            Site::updateOrCreate(
                ['base_url' => $s['base_url']],
                [
                    'name'             => $s['name'],
                    'crawl_depth'      => 2,
                    'max_pages'        => 500,
                    'frequency_type'   => $s['freq'],
                    'frequency_config' => $s['config'],
                    'notify_channels'  => $s['notify'],
                    'is_active'        => $s['active'] ?? true,
                ],
            );
        }
    }
}
