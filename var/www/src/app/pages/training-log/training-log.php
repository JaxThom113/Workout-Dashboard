<?php

$env = parse_ini_file(__DIR__ . '/../../../../.env');
define('NOTION_TOKEN', $env['NOTION_TOKEN'] ?? '');
define('TRAINING_PAGE_ID', $env['TRAINING_PAGE_ID'] ?? '');
define('GEMINI_API_KEY', $env['GEMINI_API_KEY'] ?? '');
define('GEMINI_MODEL', $env['GEMINI_MODEL'] ?? '');

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

    // Align with poller: avoid treating every page as "new" on next ingest run.
    $state = $syncState->load();
    $allIds = array_values(array_filter(array_map(fn($page) => $page['id'] ?? null, $workout_pages)));
    $state = $syncState->markSeen($state, $allIds);
    $state = $syncState->touchPoll($state);
    $state = $syncState->touchSuccess($state);
    $syncState->save($state);
}

$db_message = null;
$db_message_type = null;

if (GEMINI_API_KEY && GEMINI_MODEL && isset($_GET['process']))
{
    try
    {
        $gemini = new GeminiService(GEMINI_API_KEY, GEMINI_MODEL);
        $repo = new WorkoutRepository(Database::getConnection());
        $pollingService = new PollingService($notionService, $cache, $syncState, $gemini, $repo);

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

require __DIR__ . '/training-log.html.php';
