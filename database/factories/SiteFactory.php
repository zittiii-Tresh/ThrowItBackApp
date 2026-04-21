<?php

namespace Database\Factories;

use App\Enums\FrequencyType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Site>
 */
class SiteFactory extends Factory
{
    /**
     * Default state — a random active daily-crawled site. Override specific
     * fields in seeders or tests when you need predictable values.
     */
    public function definition(): array
    {
        $host = $this->faker->unique()->domainName();

        return [
            'name'             => $host,
            'base_url'         => "https://{$host}",
            'crawl_depth'      => 2,
            'max_pages'        => 500,
            'frequency_type'   => FrequencyType::Daily,
            'frequency_config' => null,
            'notify_channels'  => ['email'],
            'is_active'        => true,
            'last_crawled_at'  => null,
            'next_run_at'      => null,
        ];
    }

    public function paused(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function everyNDays(int $n): self
    {
        return $this->state([
            'frequency_type'   => FrequencyType::EveryNDays,
            'frequency_config' => ['days' => $n],
        ]);
    }

    /**
     * @param  array<int,string>  $days  3-letter lowercase (mon/tue/wed/thu/fri/sat/sun)
     */
    public function specificDays(array $days, string $time = '00:00'): self
    {
        return $this->state([
            'frequency_type'   => FrequencyType::SpecificDays,
            'frequency_config' => ['days' => $days, 'time' => $time],
        ]);
    }
}
