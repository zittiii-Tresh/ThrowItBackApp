<?php

namespace App\Enums;

/**
 * Asset categories captured during a crawl. Matches the 4 rows in the
 * "Asset types captured" table in the proposal PDF (Screen 4 — Assets).
 */
enum AssetType: string
{
    case Image      = 'image';
    case Stylesheet = 'stylesheet';
    case Javascript = 'javascript';
    case Font       = 'font';
    case Other      = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Image      => 'Image',
            self::Stylesheet => 'CSS',
            self::Javascript => 'JS',
            self::Font       => 'Font',
            self::Other      => 'Other',
        };
    }

    /**
     * Classify a mime type into one of our asset buckets. The crawler
     * infers this from Content-Type headers (or file extension as fallback).
     */
    public static function fromMimeType(?string $mime): self
    {
        if (! $mime) {
            return self::Other;
        }

        $mime = strtolower(strtok($mime, ';')); // strip charset suffix

        return match (true) {
            str_starts_with($mime, 'image/')                                 => self::Image,
            $mime === 'text/css'                                             => self::Stylesheet,
            in_array($mime, ['application/javascript', 'text/javascript',
                             'application/x-javascript'], true)              => self::Javascript,
            str_starts_with($mime, 'font/') ||
            in_array($mime, ['application/font-woff', 'application/font-woff2',
                             'application/vnd.ms-fontobject',
                             'application/x-font-ttf'], true)                => self::Font,
            default                                                          => self::Other,
        };
    }
}
