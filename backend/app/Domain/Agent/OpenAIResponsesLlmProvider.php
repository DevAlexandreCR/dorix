<?php

namespace App\Domain\Agent;

use App\Domain\Agent\Contracts\LlmProviderInterface;
use App\Domain\Agent\DTO\AgentContext;
use App\Domain\Agent\DTO\AgentDecision;
use App\Domain\Agent\Exceptions\LlmProviderException;
use Illuminate\Http\Client\Factory as HttpFactory;

class OpenAIResponsesLlmProvider implements LlmProviderInterface
{
    public function __construct(
        protected HttpFactory $http,
        protected PromptBuilder $promptBuilder,
    ) {
    }

    public function generateDecision(AgentContext $context, array $prompt): AgentDecision
    {
        $apiKey = (string) config('services.openai.api_key');

        if ($apiKey === '') {
            throw new LlmProviderException(__('api.agent.openai_key_missing'));
        }

        $response = $this->http
            ->baseUrl(rtrim((string) config('services.openai.base_url', 'https://api.openai.com'), '/'))
            ->withToken($apiKey)
            ->acceptJson()
            ->timeout((int) config('services.openai.timeout', 30))
            ->post('/v1/responses', [
                'model' => $context->agentConfig->model ?: (string) config('services.openai.default_model', 'gpt-5.1'),
                'input' => $prompt,
                'reasoning' => [
                    'effort' => (string) config('services.openai.reasoning_effort', 'low'),
                ],
                'text' => [
                    'format' => $this->promptBuilder->decisionFormat(),
                ],
            ]);

        $responseData = $response->json();

        if (! $response->successful()) {
            throw new LlmProviderException(__('api.agent.openai_request_failed', [
                'status' => $response->status(),
            ]));
        }

        if (! is_array($responseData)) {
            throw new LlmProviderException(__('api.agent.openai_invalid_object'));
        }

        $outputText = $this->extractOutputText($responseData);

        if ($outputText === '') {
            throw new LlmProviderException(__('api.agent.openai_missing_output_text'));
        }

        $decisionPayload = json_decode($outputText, true);

        if (! is_array($decisionPayload)) {
            throw new LlmProviderException(__('api.agent.openai_invalid_decision'));
        }

        return AgentDecision::fromArray($decisionPayload);
    }

    /**
     * @param  array<string, mixed>  $responseData
     */
    protected function extractOutputText(array $responseData): string
    {
        $outputText = $responseData['output_text'] ?? null;

        if (is_string($outputText) && trim($outputText) !== '') {
            return trim($outputText);
        }

        $output = $responseData['output'] ?? null;

        if (! is_array($output)) {
            return '';
        }

        $segments = [];

        foreach ($output as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }

            $content = $item['content'] ?? null;

            if (! is_array($content)) {
                continue;
            }

            foreach ($content as $part) {
                if (! is_array($part)) {
                    continue;
                }

                if (($part['type'] ?? null) !== 'output_text') {
                    continue;
                }

                $text = $part['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $segments[] = trim($text);
                }
            }
        }

        return trim(implode("\n", $segments));
    }
}
