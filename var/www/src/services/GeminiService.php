<?php

/*
    This service handles interactions with Gemini API, specifically for parsing 
    Notion workout log page content into structured data we can save to database
*/
class GeminiService 
{
    private string $geminiApiKey;
    private string $geminiModel;
    private ?string $lastError = null;
    private ?int $lastHttpCode = null;
    private ?int $rateLimitRetryAfter = null;

    public function __construct(string $geminiApiKey, string $geminiModel) 
    {
        $this->geminiApiKey = $geminiApiKey;
        $this->geminiModel = $geminiModel;
    }

    public function parseWorkoutLog(string $htmlContent): ?array 
    {
        $prompt = $this->buildPrompt($htmlContent);

        $payload = [
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $response = $this->makeRequest($payload);
        return $this->extractStructuredData($response);
    }

    public function parseWorkoutLogBatch(array $pages): array
    {
        $prompt = $this->buildBatchPrompt($pages);

        $payload = [
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => $prompt]
                    ]
                ]
            ]
        ];

        $response = $this->makeRequest($payload);
        return $this->extractBatchStructuredData($response, $pages);
    }

    public function preflightModel(): bool
    {
        // will call Gemini once to check if given model is valid
        $payload = [
            'generationConfig' => [
                'responseMimeType' => 'application/json',
            ],
            'contents' => [
                [
                    'parts' => [
                        ['text' => 'Reply with valid JSON: {"status":"ok"}']
                    ]
                ]
            ]
        ];

        return $this->makeRequest($payload) !== null;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getLastHttpCode(): ?int
    {
        return $this->lastHttpCode;
    }

    public function getRateLimitRetryAfter(): ?int
    {
        return $this->rateLimitRetryAfter;
    }

    private function buildPrompt(string $htmlContent): string 
    {
        // get the Gemini prompt from a text file, insert the html content and return
        $template = file_get_contents(__DIR__ . '/prompt.txt');
        $prompt = str_replace('{{htmlContent}}', $htmlContent, $template);

        return $prompt;
    }

    private function buildBatchPrompt(array $pages): string
    {
        $workoutLogs = [];
        foreach ($pages as $page) 
        {
            $workoutLogs[] = "---PAGE ID: {$page['id']}---\n{$page['content']}";
        }

        $batchContent = implode("\n\n", $workoutLogs);

        // get the batch prompt from a text file
        $template = file_get_contents(__DIR__ . '/prompt_batch.txt');
        $prompt = str_replace('{{batchContent}}', $batchContent, $template);

        return $prompt;
    }

    private function makeRequest(array $payload): ?array 
    {
        $this->lastError = null;
        $this->lastHttpCode = null;
        $this->rateLimitRetryAfter = null;

        // access Gemini API, use common cURL operations
        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . $this->geminiModel . ':generateContent?key=' . $this->geminiApiKey);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);

        $result = curl_exec($ch);
        if ($result === false)
        {
            $this->lastError = 'cURL error: ' . curl_error($ch);
            $this->lastHttpCode = 0;
            return null;
        }

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $this->lastHttpCode = $httpCode;

        if ($httpCode !== 200) 
        {
            $decoded = is_string($result) ? json_decode($result, true) : null;
            $apiError = $decoded['error']['message'] ?? null;
            $this->lastError = $apiError ?: ("Gemini API error (HTTP $httpCode)");
            
            // handle rate limit error with retry-after information
            if ($httpCode === 429) 
            {
                $retryInfo = $decoded['error']['details'] ?? [];
                foreach ($retryInfo as $detail) 
                {
                    if ($detail['@type'] === 'type.googleapis.com/google.rpc.RetryInfo') 
                    {
                        $retryDelayStr = $detail['retryDelay'] ?? null;
                        if ($retryDelayStr) 
                        {
                            // Parse duration format like "14.091489276s"
                            $this->rateLimitRetryAfter = (int) ceil(floatval($retryDelayStr));
                        }
                        break;
                    }
                }
            }
        }

        return json_decode($result, true);
    }

    private function extractStructuredData(?array $response): ?array 
    {
        if (!$response || empty($response['candidates'][0]['content']['parts'][0]['text']))
            return null;

        $text = $this->normalizeJsonText($response['candidates'][0]['content']['parts'][0]['text']);
        $parsed = json_decode($text, true);

        if (!is_array($parsed))
        {
            $this->lastError = 'Failed to decode Gemini JSON: ' . json_last_error_msg();
            return null;
        }

        return $parsed;
    }

    private function extractBatchStructuredData(?array $response, array $pages): array
    {
        $result = [];
        
        // Initialize all page IDs to null (in case parsing fails)
        foreach ($pages as $page) 
        {
            $result[$page['id']] = null;
        }

        if (!$response || empty($response['candidates'][0]['content']['parts'][0]['text']))
            return $result;

        $text = $this->normalizeJsonText($response['candidates'][0]['content']['parts'][0]['text']);
        
        $parsed = json_decode($text, true);
        if (!is_array($parsed))
        {
            $this->lastError = 'Failed to decode Gemini batch JSON: ' . json_last_error_msg();
            return $result;
        }

        foreach ($pages as $page)
        {
            $pageId = $page['id'] ?? '';
            if ($pageId !== '' && array_key_exists($pageId, $parsed))
                $result[$pageId] = $parsed[$pageId];
        }

        return $result;
    }

    private function normalizeJsonText(string $text): string
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);
        $text = trim($text);

        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace)
            return substr($text, $firstBrace, $lastBrace - $firstBrace + 1);

        return $text;
    }
}
?>
