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

    public function parseWorkoutLog(string $htmlContent): ?array 
    {
        $prompt = $this->buildPrompt($htmlContent);

        $payload = [
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

    /**
     * Parse multiple workout logs in a single API request
     * @param array $pages Array of pages with keys: id, content
     * @return array Map of page_id => parsed_data (null if failed)
     */
    public function parseWorkoutLogBatch(array $pages): array
    {
        $prompt = $this->buildBatchPrompt($pages);

        $payload = [
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
        foreach ($pages as $page) {
            $workoutLogs[] = "---PAGE ID: {$page['id']}---\n{$page['content']}";
        }

        $batchContent = implode("\n\n", $workoutLogs);

        return <<<PROMPT
You will parse multiple workout logs. For EACH log, extract the data in this JSON format:
{
    "date": "YYYY-MM-DD",
    "type": "push", "pull", "legs", (or another named type)
    "exercises": [
        {
            "name": "exercise name",
            "number": 1,
            "notes": "any notes",
            "sets": [
                {
                    "number": 1,
                    "reps": 8,
                    "warmup": false,
                    "dropset": false,
                    "failure": false
                }
            ]
        }
    ]
}

Return a JSON object where keys are the page IDs and values are the parsed workout data.
If a page cannot be parsed, use null as the value.

Example response format:
{
  "page-id-1": { parsed data },
  "page-id-2": { parsed data },
  "page-id-3": null
}

WORKOUT LOGS TO PARSE:
$batchContent

Return ONLY valid JSON, no additional text.
PROMPT;
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

        $text = $response['candidates'][0]['content']['parts'][0]['text'];
        
        // clean up markdown code blocks if present
        $text = preg_replace('/```json\s*|\s*```/', '', $text);
        
        return json_decode($text, true);
    }

    private function extractBatchStructuredData(?array $response, array $pages): array
    {
        $result = [];
        
        // Initialize all page IDs to null (in case parsing fails)
        foreach ($pages as $page) {
            $result[$page['id']] = null;
        }

        if (!$response || empty($response['candidates'][0]['content']['parts'][0]['text']))
            return $result;

        $text = $response['candidates'][0]['content']['parts'][0]['text'];
        
        // clean up markdown code blocks if present
        $text = preg_replace('/```json\s*|\s*```/', '', $text);
        
        $parsed = json_decode($text, true);
        if (!is_array($parsed))
            return $result;

        // Merge the parsed results with our initialized result array
        return array_merge($result, $parsed);
    }
}
?>