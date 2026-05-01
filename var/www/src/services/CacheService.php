<?php

/*
    This service is for caching Notion page data to limit API calls, and 
    to allow Training Log page to load quicker
*/
class CacheService
{
    private string $cacheFile;

    public function __construct(string $cacheFile)
    {
        $this->cacheFile = $cacheFile;
    }

    public function load(): ?array
    {
        if (!file_exists($this->cacheFile))
            return null;

        $data = json_decode(file_get_contents($this->cacheFile), true);
        return is_array($data) ? $data : null;
    }

    public function save(array $pages): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir))
            mkdir($dir, 0777, true);

        file_put_contents($this->cacheFile, json_encode($pages));
    }

    public function clear(): void
    {
        if (file_exists($this->cacheFile))
            unlink($this->cacheFile);
    }

    public function ageMinutes(): ?int
    {
        if (!file_exists($this->cacheFile))
            return null;

        return (int) round((time() - filemtime($this->cacheFile)) / 60);
    }
}

?>
