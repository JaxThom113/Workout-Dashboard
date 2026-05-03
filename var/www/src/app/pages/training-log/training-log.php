<?php

$env = parse_ini_file(__DIR__ . '/../../../../.env');
define('NOTION_TOKEN', $env['NOTION_TOKEN'] ?? '');
define('TRAINING_PAGE_ID', $env['TRAINING_PAGE_ID'] ?? '');
define('GEMINI_API_KEY', $env['GEMINI_API_KEY'] ?? '');
define('GEMINI_MODEL', $env['GEMINI_MODEL'] ?? '');
define('GEMINI_BATCH_SIZE', (int)($env['GEMINI_BATCH_SIZE'] ?? 5));
define('GEMINI_BATCH_DELAY_SECONDS', (float)($env['GEMINI_BATCH_DELAY_SECONDS'] ?? 5.0));

// Same paths as var/www/scripts/poll_training_logs.php ($rootDir/database/...)
define('CACHE_FILE', __DIR__ . '/../../../../database/cache/cache.json');
define('SYNC_STATE_FILE', __DIR__ . '/../../../../database/state/sync_state.json');

require_once __DIR__ . '/../../../services/Database.php';
require_once __DIR__ . '/../../../services/GeminiService.php';
require_once __DIR__ . '/../../../services/WorkoutRepository.php';
require_once __DIR__ . '/../../../services/CacheService.php';
require_once __DIR__ . '/../../../services/SyncState.php';
require_once __DIR__ . '/../../../services/NotionService.php';
require_once __DIR__ . '/../../../services/PollingService.php';

$cache = new CacheService(CACHE_FILE);
$syncState = new SyncState(SYNC_STATE_FILE);
$notionService = new NotionService(NOTION_TOKEN, TRAINING_PAGE_ID);

$workout_pages = $cache->load();
$from_cache = true;

if ($workout_pages === null)
{
    $from_cache = false;
    $workout_pages = $notionService->getWorkoutPages();
    $cache->save($workout_pages);
}

$db_message = null;
$db_message_type = null;

if (GEMINI_API_KEY && GEMINI_MODEL && isset($_GET['process']))
{
    try
    {
        $gemini = new GeminiService(GEMINI_API_KEY, GEMINI_MODEL);
        $repo = new WorkoutRepository(Database::getConnection());
        $pollingService = new PollingService($notionService, $cache, $syncState, $gemini, $repo, GEMINI_BATCH_SIZE, GEMINI_BATCH_DELAY_SECONDS);

        $stats = $pollingService->sync(true);
        $workout_pages = $cache->load() ?? $workout_pages;

        $db_message = "✓ Synced {$stats['new_pages']} new page(s), saved {$stats['parsed_success']} workout(s)";
        $db_message_type = $stats['parsed_failures'] > 0 ? 'warning' : 'success';

        if ($stats['parsed_failures'] > 0)
            $db_message .= " | ⚠ Failed {$stats['parsed_failures']} page(s)";

        if ($stats['stopped_early'])
        {
            $db_message .= " | ✗ " . ($stats['stop_reason'] ?? 'Stopped early');
            $db_message_type = 'error';
        }
    }
    catch (Exception $e)
    {
        $db_message = "✗ Error: " . $e->getMessage();
        $db_message_type = 'error';
        error_log("Training-log process error: " . $e->getMessage());
    }
}

$cache_age = $cache->ageMinutes();

$training_log_years = [2024, 2025, 2026];

// year => month (1–12) => list of workout pages (same shape for $workouts_other_nested)
$workouts_nested = [];
foreach ($training_log_years as $y)
    $workouts_nested[$y] = [];

$workouts_other_nested = [];
$workouts_unparsed = [];

foreach ($workout_pages as $page)
{
    $title = $page['title'] ?? '';
    $year = null;
    $month = null;

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $title, $m))
    {
        $month = (int) $m[1];
        $day = (int) $m[2];
        $year = (int) date('Y', mktime(0, 0, 0, $month, $day, (int) $m[3]));
    }

    if ($year !== null && $month !== null && isset($workouts_nested[$year]))
        $workouts_nested[$year][$month][] = $page;
    elseif ($year !== null && $month !== null)
    {
        if (!isset($workouts_other_nested[$year]))
            $workouts_other_nested[$year] = [];
        $workouts_other_nested[$year][$month][] = $page;
    }
    else
        $workouts_unparsed[] = $page;
}

require __DIR__ . '/training-log.html.php';
