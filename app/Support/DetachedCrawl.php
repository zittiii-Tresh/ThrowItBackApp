<?php

namespace App\Support;

/**
 * Spawns `php artisan crawl:run {siteId} --run-id={runId}` as a fully
 * detached background process that outlives the caller.
 *
 * Used by both the scheduler tick (DispatchDueCrawlsCommand) and the
 * Filament "Crawl now" button (SiteResource) — same code path so the
 * two flows can never drift.
 *
 * Why this exists instead of `popen("start /B ...")`:
 *   popen() on Windows always wraps the command in `cmd.exe /c "..."`.
 *   When the parent has no console (php-win.exe under Task Scheduler),
 *   Windows allocates a fresh console for that cmd.exe — the visible
 *   black window flash that fired every minute. proc_open with
 *   bypass_shell => true skips the shell entirely and CreateProcesses
 *   php-win.exe directly. No console is ever attached.
 *
 * We also force php-win.exe (the windowless PHP subsystem) for the
 * child even when the parent is php.exe — e.g. `php artisan serve`
 * driving the Filament "Crawl now" path. Otherwise the child would
 * inherit php.exe and flash its own console.
 */
class DetachedCrawl
{
    public static function spawn(int $siteId, int $runId): void
    {
        $phpBin  = self::windowlessPhpBinary();
        $artisan = base_path('artisan');
        $args    = ['crawl:run', (string) $siteId, '--run-id=' . $runId];

        if (PHP_OS_FAMILY === 'Windows') {
            self::spawnWindows($phpBin, $artisan, $args);
            return;
        }

        // Linux / macOS: shell `&` detaches reliably, no console concerns.
        $cmd = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($artisan),
            implode(' ', array_map('escapeshellarg', $args))
        );
        pclose(popen($cmd, 'r'));
    }

    /**
     * Resolve php-win.exe alongside the current PHP binary on Windows.
     * Falls back to PHP_BINARY (which is fine when already php-win.exe
     * or when the *.exe rename produced a non-existent file).
     */
    protected static function windowlessPhpBinary(): string
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return PHP_BINARY;
        }

        $phpWin = preg_replace('/php(?:-cgi)?\.exe$/i', 'php-win.exe', PHP_BINARY);

        return ($phpWin && is_file($phpWin)) ? $phpWin : PHP_BINARY;
    }

    /**
     * proc_open with bypass_shell => true CreateProcesses the child
     * directly with no cmd.exe wrapper. Stdio is bound to NUL so the
     * child has no inherited console handles to fall back on.
     *
     * The handle is intentionally NOT proc_close()'d — proc_close blocks
     * waiting for the child to exit, defeating the whole point. Letting
     * the resource fall out of scope on script exit is safe; PHP closes
     * its handle, but the OS keeps the child running with its own NUL
     * file handles.
     */
    protected static function spawnWindows(string $phpBin, string $artisan, array $args): void
    {
        $cmd = array_merge([$phpBin, $artisan], $args);

        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'w'],
            2 => ['file', 'NUL', 'w'],
        ];

        $pipes = [];
        proc_open(
            $cmd,
            $descriptors,
            $pipes,
            base_path(),
            null,
            [
                'bypass_shell'         => true,
                'create_new_console'   => false,
                'create_process_group' => true,
                'suppress_errors'      => true,
            ],
        );
    }
}
