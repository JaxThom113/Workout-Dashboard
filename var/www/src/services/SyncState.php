<?php

class SyncState
{
    private string $stateFile;

    public function __construct(string $stateFile)
    {
        $this->stateFile = $stateFile;
    }

    public function load(): array
    {
        // if state file doesn't exist, return default state
        if (!file_exists($this->stateFile))
        {
            return [
                'seen_page_ids' => [],
                'last_poll_at' => null,
                'last_success_at' => null,
            ];
        }

        // load state from file, ensure it has expected structure
        $data = json_decode(file_get_contents($this->stateFile), true);
        if (!is_array($data))
            return ['seen_page_ids' => [], 'last_poll_at' => null, 'last_success_at' => null];

        return [
            'seen_page_ids' => array_values(array_unique($data['seen_page_ids'] ?? [])),
            'last_poll_at' => $data['last_poll_at'] ?? null,
            'last_success_at' => $data['last_success_at'] ?? null,
        ];
    }

    public function save(array $state): void
    {
        // save state to file, ensure directory exists
        $dir = dirname($this->stateFile);
        if (!is_dir($dir))
            mkdir($dir, 0777, true);

        file_put_contents($this->stateFile, json_encode($state));
    }

    public function touchPoll(array $state): array
    {
        // update last_poll_at timestamp to now
        $state['last_poll_at'] = gmdate('c');
        return $state;
    }

    public function touchSuccess(array $state): array
    {
        // update last_success_at timestamp to now
        $state['last_success_at'] = gmdate('c');
        return $state;
    }

    public function unseenPages(array $pages, array $state): array
    {
        // return pages that are not in seen_page_ids
        $seenSet = array_fill_keys($state['seen_page_ids'] ?? [], true);
        return array_values(array_filter($pages, fn($page) => !isset($seenSet[$page['id'] ?? ''])));
    }

    public function markSeen(array $state, array $pageIds): array
    {
        // add pageIds to seen_page_ids so they won't be processed again in future polls
        $all = array_merge($state['seen_page_ids'] ?? [], $pageIds);
        $state['seen_page_ids'] = array_values(array_unique($all));
        return $state;
    }
}

?>
