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
    private int $geminiBatchSize;
    private float $batchDelaySeconds;

    public function __construct(NotionService $notionService, CacheService $cache, SyncState $syncState, ?GeminiService $gemini = null, ?WorkoutRepository $repository = null, int $geminiBatchSize = 5, float $batchDelaySeconds = 5.0) 
    {
        $this->notionService = $notionService;
        $this->cache = $cache;
        $this->syncState = $syncState;
        $this->gemini = $gemini;
        $this->repository = $repository;
        $this->geminiBatchSize = max(1, $geminiBatchSize);
        $this->batchDelaySeconds = max(0.0, $batchDelaySeconds);
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

            $seenIds = [];
            
            $batches = array_chunk($unseenPages, $this->geminiBatchSize, true);
            
            foreach ($batches as $batchIndex => $batch)
            {
                error_log('Polling batch ' . ($batchIndex + 1) . '/' . count($batches) . ' with ' . count($batch) . ' page(s)');

                // Prepare batch with id and content
                $batchPages = array_map(fn($page) => [
                    'id' => $page['id'] ?? '',
                    'content' => $page['content'] ?? ''
                ], $batch);
                
                $batchResults = $this->gemini->parseWorkoutLogBatch($batchPages);
                
                // Check for rate limiting
                $httpCode = $this->gemini->getLastHttpCode();
                if ($httpCode === 429)
                {
                    $retryAfter = $this->gemini->getRateLimitRetryAfter();
                    $apiError = $this->gemini->getLastError();
                    $stats['stopped_early'] = true;
                    $retryMessage = $retryAfter !== null ? " Retry in {$retryAfter}s." : '';
                    $stats['stop_reason'] = "Rate limited by Gemini API (429).{$retryMessage} " . ($apiError ? "Gemini said: {$apiError}" : 'Check AI Studio for whether RPM, TPM, or RPD was exceeded.');
                    $hasHardFailure = true;
                    break;
                }
                
                if ($httpCode === 404)
                {
                    $stats['stopped_early'] = true;
                    $stats['stop_reason'] = 'Gemini returned 404 Not Found. Check configured model/version.';
                    $hasHardFailure = true;
                    break;
                }

                if ($httpCode !== 200)
                {
                    $stats['stopped_early'] = true;
                    $stats['stop_reason'] = 'Gemini request failed with HTTP ' . ($httpCode ?? 0) . ': ' . ($this->gemini->getLastError() ?? 'Unknown error');
                    $hasHardFailure = true;
                    break;
                }
                
                // Process results from this batch
                foreach ($batchResults as $pageId => $parsed)
                {
                    if (!$parsed)
                    {
                        $stats['parsed_failures']++;
                        continue;
                    }

                    // Ensure parsed data is an array (Gemini might return strings in some cases)
                    if (!is_array($parsed))
                    {
                        $stats['parsed_failures']++;
                        error_log("Warning: Gemini returned non-array data for page $pageId: " . gettype($parsed));
                        continue;
                    }

                    if ($this->repository->saveWorkout($parsed))
                    {
                        $stats['parsed_success']++;
                        if (!empty($pageId))
                            $seenIds[] = $pageId;
                    }
                    else
                    {
                        $stats['parsed_failures']++;
                    }
                }

                if ($this->batchDelaySeconds > 0 && $batchIndex < count($batches) - 1)
                    usleep((int)($this->batchDelaySeconds * 1_000_000));
            }

            if (!empty($seenIds))
                $state = $this->syncState->markSeen($state, $seenIds);
        }
        else
        {
            // Cache-only polls should not consume pages that still need database ingestion.
        }

        if (!$hasHardFailure)
            $state = $this->syncState->touchSuccess($state);
        $this->syncState->save($state);

        $stats['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        return $stats;
    }
}

?>
