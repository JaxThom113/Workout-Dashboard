<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$env = parse_ini_file($rootDir . '/.env');

$notionToken = $env['NOTION_TOKEN'] ?? '';
$trainingPageId = $env['TRAINING_PAGE_ID'] ?? '';
$geminiApiKey = $env['GEMINI_API_KEY'] ?? '';
$geminiModel = $env['GEMINI_MODEL'] ?? '';

if ($notionToken === '' || $trainingPageId === '')
{
    fwrite(STDERR, "Missing NOTION_TOKEN or TRAINING_PAGE_ID in .env\n");
    exit(1);
}

require_once $rootDir . '/src/services/Database.php';
require_once $rootDir . '/src/services/GeminiService.php';
require_once $rootDir . '/src/services/WorkoutRepository.php';
require_once $rootDir . '/src/services/NotionService.php';
require_once $rootDir . '/src/services/CacheService.php';
require_once $rootDir . '/src/services/SyncState.php';
require_once $rootDir . '/src/services/PollingService.php';

$lockFile = $rootDir . '/database/state/poll_training_logs.lock';
$logFile = $rootDir . '/database/logs/training_log_poll.log';

$lockHandle = fopen($lockFile, 'c+');
if (!$lockHandle)
{
    fwrite(STDERR, "Unable to open lock file: $lockFile\n");
    exit(1);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB))
{
    fwrite(STDERR, "Poller already running; exiting.\n");
    fclose($lockHandle);
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());

function logPollLine(string $logFile, string $message): void
{
    $line = '[' . gmdate('c') . '] ' . $message . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}

try
{
    $cache = new CacheService($rootDir . '/database/cache/cache.json');
    $syncState = new SyncState($rootDir . '/database/state/sync_state.json');
    $notionService = new NotionService($notionToken, $trainingPageId);

    $gemini = null;
    $repository = null;
    $ingestToDatabase = false;

    if ($geminiApiKey !== '' && $geminiModel !== '')
    {
        if (extension_loaded('mysqli'))
        {
            $gemini = new GeminiService($geminiApiKey, $geminiModel);
            $repository = new WorkoutRepository(Database::getConnection());
            $ingestToDatabase = true;
        }
        else
        {
            logPollLine($logFile, json_encode([
                'event' => 'poll_warning',
                'message' => 'mysqli extension not loaded; running cache-only sync'
            ]));
        }
    }

    $poller = new PollingService($notionService, $cache, $syncState, $gemini, $repository);
    $stats = $poller->sync($ingestToDatabase);

    logPollLine(
        $logFile,
        json_encode([
            'event' => 'poll_complete',
            'ingest_to_database' => $ingestToDatabase,
            'scanned_pages' => $stats['scanned_pages'],
            'new_pages' => $stats['new_pages'],
            'parsed_success' => $stats['parsed_success'],
            'parsed_failures' => $stats['parsed_failures'],
            'stopped_early' => $stats['stopped_early'],
            'stop_reason' => $stats['stop_reason'],
            'duration_seconds' => $stats['duration_seconds'],
        ])
    );

    echo "Poll complete. scanned={$stats['scanned_pages']} new={$stats['new_pages']} success={$stats['parsed_success']} failures={$stats['parsed_failures']}\n";
}
catch (Throwable $e)
{
    logPollLine($logFile, json_encode(['event' => 'poll_error', 'message' => $e->getMessage()]));
    fwrite(STDERR, "Poll failed: " . $e->getMessage() . "\n");
    exit(1);
}
finally
{
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

?>
