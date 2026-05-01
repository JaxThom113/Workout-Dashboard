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

    public function __construct(string $geminiApiKey, string $geminiModel) 
    {
        $this->geminiApiKey = $geminiApiKey;
        $this->geminiModel = $geminiModel;
    }

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

    private function buildPrompt(string $htmlContent): string 
    {
        // get the Gemini prompt from a text file, insert the html content and return
        $template = file_get_contents(__DIR__ . '/prompt.txt');
        $prompt = str_replace('{{htmlContent}}', $htmlContent, $template);

        return $prompt;
    }

    private function makeRequest(array $payload): ?array 
    {
        $this->lastError = null;
        $this->lastHttpCode = null;

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
            error_log("Gemini API error (HTTP $httpCode): " . ($result ?: 'no response body'));
            return null;
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
}
?>