<?php

/*
    This service orchestrates the polling process of fetching Notion pages, 
    determining which are new, parsing them with Gemini, and saving to AWS database 
    It also tracks sync_state.json to avoid reprocessing pages
*/
class PollingService
{
    private NotionService $notionService;
    private CacheService $cache;
    private SyncState $syncState;
    private ?GeminiService $gemini;
    private ?WorkoutRepository $repository;

    public function __construct(NotionService $notionService, CacheService $cache, SyncState $syncState, ?GeminiService $gemini = null, ?WorkoutRepository $repository = null) 
    {
        $this->notionService = $notionService;
        $this->cache = $cache;
        $this->syncState = $syncState;
        $this->gemini = $gemini;
        $this->repository = $repository;
    }

    public function sync(bool $ingestToDatabase): array
    {
        $startedAt = microtime(true);
        $stats = [
            'scanned_pages' => 0,
            'new_pages' => 0,
            'parsed_success' => 0,
            'parsed_failures' => 0,
            'stopped_early' => false,
            'stop_reason' => null,
            'duration_seconds' => 0.0,
        ];

        $state = $this->syncState->load();
        $state = $this->syncState->touchPoll($state);

        $pages = $this->notionService->getWorkoutPages();
        $stats['scanned_pages'] = count($pages);
        $this->cache->save($pages);

        $unseenPages = $this->syncState->unseenPages($pages, $state);
        $stats['new_pages'] = count($unseenPages);

        $hasHardFailure = false;

        if ($ingestToDatabase && !empty($unseenPages))
        {
            if (!$this->gemini || !$this->repository)
                throw new RuntimeException('GeminiService and WorkoutRepository are required for ingestion mode.');

            if (!$this->gemini->preflightModel())
            {
                $error = $this->gemini->getLastError() ?: 'Unknown Gemini error';
                throw new RuntimeException(
                    "Gemini preflight failed for model (HTTP " . ($this->gemini->getLastHttpCode() ?? 'unknown') . "): $error"
                );
            }

            $seenIds = [];
            foreach ($unseenPages as $page)
            {
                $parsed = $this->gemini->parseWorkoutLog($page['content'] ?? '');
                if (!$parsed)
                {
                    $stats['parsed_failures']++;

                    if ($this->gemini->getLastHttpCode() === 404)
                    {
                        $stats['stopped_early'] = true;
                        $stats['stop_reason'] = 'Gemini returned 404 Not Found. Check configured model/version.';
                        $hasHardFailure = true;
                        break;
                    }

                    continue;
                }

                if ($this->repository->saveWorkout($parsed))
                {
                    $stats['parsed_success']++;
                    if (!empty($page['id']))
                        $seenIds[] = $page['id'];
                }
                else
                {
                    $stats['parsed_failures']++;
                }
            }

            if (!empty($seenIds))
                $state = $this->syncState->markSeen($state, $seenIds);
        }
        else
        {
            $allIds = array_values(array_filter(array_map(fn($page) => $page['id'] ?? null, $pages)));
            $state = $this->syncState->markSeen($state, $allIds);
        }

        if (!$hasHardFailure)
            $state = $this->syncState->touchSuccess($state);
        $this->syncState->save($state);

        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        return $stats;
    }
}

?>
