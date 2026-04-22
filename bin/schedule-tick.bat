@echo off
rem -----------------------------------------------------------------------------
rem schedule-tick.bat
rem
rem Called once a minute by the "SiteArchive Scheduler" Windows Task.
rem Ticks Laravel's scheduler, which runs crawl:dispatch-due, which spawns a
rem detached crawl:run process for every overdue site. No queue:work needed —
rem see app/Console/Commands/DispatchDueCrawlsCommand.php.
rem -----------------------------------------------------------------------------
cd /d "C:\Users\sitesatscale\Documents\SAS SiteArchive\site-archive"
"C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe" artisan schedule:run >NUL 2>&1
