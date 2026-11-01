<?php
declare(strict_types=1);

namespace NutriPlan;

/** Thin wrapper over the Anthropic Messages API. The key only ever lives on the server. */
class ClaudeClient
{
    public function __construct(
        private string $apiKey,
        private string $model,
        private string $endpoint = 'https://api.anthropic.com/v1/messages'
    ) {
    }

    public static function fromConfig(): self
    {
        $key = Config::get('ANTHROPIC_API_KEY');
        if (!$key) {
            throw new ApiException(500, 'server_misconfigured', 'The meal-planning service is not configured yet.');
        }
        // ANTHROPIC_API_URL is optional (for an API gateway, or a stand-in server in tests).
        return new self(
            $key,
            Config::get('CLAUDE_MODEL', 'claude-sonnet-5-5'),
            Config::get('ANTHROPIC_API_URL') ?: 'https://api.anthropic.com/v1/messages'
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /** Sends a Messages API payload (model is filled in) and returns the decoded response. */
    public function messages(array $payload): array
    {
        $payload['model'] = $this->model;
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        for ($try = 0; $try < 3; $try++) {
            $ch = curl_init($this->endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                // Only ever talk HTTPS (or plain HTTP to a local test stand-in), never follow redirects.
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_TIMEOUT        => 240,
                CURLOPT_HTTPHEADER     => [
                    'content-type: application/json',
                    'x-api-key: ' . $this->apiKey,
                    'anthropic-version: 2023-06-01',
                ],
            ]);
            $raw = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($raw === false) {
                if ($try < 2) {
                    sleep(2);
                    continue;
                }
                error_log('Claude API unreachable: ' . $err);
                throw new ApiException(502, 'llm_unreachable', 'Could not reach the meal-planning service. Please try again.');
            }
            if (in_array($status, [429, 500, 502, 503, 529], true) && $try < 2) {
                sleep(2 * ($try + 1));
                continue;
            }
            $data = json_decode($raw, true);
            if ($status !== 200 || !is_array($data)) {
                error_log('Claude API error ' . $status . ': ' . substr((string)$raw, 0, 500));
                $msg = 'The meal-planning service had a problem. Please try again.';
                if (Config::get('APP_DEBUG') === '1') {
                    // Development only: show what Anthropic actually said.
                    $why = is_array($data) ? ($data['error']['message'] ?? '') : '';
                    $msg .= ' [debug: HTTP ' . $status . ($why !== '' ? ': ' . $why : '') . ']';
                }
                throw new ApiException(502, 'llm_error', $msg);
            }
            return $data;
        }
        throw new ApiException(502, 'llm_error', 'The meal-planning service is busy. Please try again.');
    }
}
