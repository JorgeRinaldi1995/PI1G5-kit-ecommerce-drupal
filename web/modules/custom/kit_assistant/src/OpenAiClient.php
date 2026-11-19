<?php

declare(strict_types=1);

namespace Drupal\kit_assistant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Site\Settings;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Thin wrapper around OpenAI's Chat Completions and Moderation endpoints.
 *
 * The API key is never stored in config (which is exportable/committable):
 * it is read from the OPENAI_API_KEY environment variable first (set via
 * `.ddev/config.local.yaml`'s `web_environment`, which is gitignored by
 * DDEV itself), falling back to `$settings['kit_assistant_openai_api_key']`
 * in settings.local.php for non-DDEV environments. See this module's
 * README for the exact setup step.
 */
class OpenAiClient {

  protected const API_BASE = 'https://api.openai.com/v1';

  public function __construct(
    protected readonly ClientInterface $httpClient,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly LoggerInterface $logger,
  ) {}

  /**
   * Whether an API key is configured at all.
   */
  public function isConfigured(): bool {
    return $this->apiKey() !== NULL;
  }

  /**
   * Reads the API key from the environment, then from $settings.
   */
  protected function apiKey(): ?string {
    $key = getenv('OPENAI_API_KEY');
    if (is_string($key) && $key !== '') {
      return $key;
    }
    $key = Settings::get('kit_assistant_openai_api_key');
    return is_string($key) && $key !== '' ? $key : NULL;
  }

  /**
   * Sends one Chat Completions request.
   *
   * @param array $messages
   *   OpenAI chat messages (system/user/assistant/tool).
   * @param array $tools
   *   Tool definitions in OpenAI's function-calling schema, or [] for none.
   *
   * @return array
   *   The decoded 'message' element of the first choice:
   *   ['role' => 'assistant', 'content' => string|null,
   *   'tool_calls' => array|null].
   *
   * @throws \Drupal\kit_assistant\OpenAiException
   */
  public function chat(array $messages, array $tools = []): array {
    $key = $this->apiKey();
    if ($key === NULL) {
      throw new OpenAiException('OpenAI API key is not configured.');
    }

    $config = $this->configFactory->get('kit_assistant.settings');
    $payload = [
      'model' => $config->get('model') ?: 'gpt-4o-mini',
      'messages' => $messages,
      'temperature' => 0.3,
      'max_tokens' => 600,
    ];
    if ($tools) {
      $payload['tools'] = $tools;
      $payload['tool_choice'] = 'auto';
    }

    try {
      $response = $this->httpClient->request('POST', self::API_BASE . '/chat/completions', [
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Content-Type' => 'application/json',
        ],
        'json' => $payload,
        'timeout' => 30,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->error('OpenAI chat request failed: @message', ['@message' => $e->getMessage()]);
      throw new OpenAiException('OpenAI request failed.', previous: $e);
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    $message = $body['choices'][0]['message'] ?? NULL;
    if (!is_array($message)) {
      $this->logger->error('OpenAI chat response had no message: @body', ['@body' => substr((string) $response->getBody(), 0, 500)]);
      throw new OpenAiException('OpenAI response was malformed.');
    }

    if (isset($body['usage'])) {
      $this->logger->info('OpenAI chat usage: @usage', ['@usage' => json_encode($body['usage'])]);
    }

    return $message;
  }

  /**
   * Checks text against OpenAI's moderation endpoint.
   *
   * Used on both the raw user message (before it ever reaches the main
   * model) and the final assistant reply (before it reaches the visitor) --
   * see this module's README, "Guardrails", for why both ends matter.
   *
   * @return bool
   *   TRUE if the text was genuinely flagged by the moderation model.
   *
   * @throws \Drupal\kit_assistant\OpenAiException
   *   If moderation itself couldn't be reached or answered -- deliberately
   *   distinct from a TRUE return. Both end up blocking the turn (see
   *   Assistant::respond()), but as different visitor-facing messages: a
   *   flagged message gets "prefiro não responder a isso", an
   *   unreachable moderation service gets "indisponível no momento". A
   *   visitor (or an admin debugging a misconfigured API key) seeing the
   *   content-flagged message for every single turn would reasonably
   *   conclude the guardrail is broken, when the real problem is
   *   connectivity or credentials.
   */
  public function moderate(string $text): bool {
    if (trim($text) === '') {
      return FALSE;
    }
    $key = $this->apiKey();
    if ($key === NULL) {
      throw new OpenAiException('OpenAI API key is not configured.');
    }

    $config = $this->configFactory->get('kit_assistant.settings');
    try {
      $response = $this->httpClient->request('POST', self::API_BASE . '/moderations', [
        'headers' => [
          'Authorization' => 'Bearer ' . $key,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $config->get('moderation_model') ?: 'omni-moderation-latest',
          'input' => $text,
        ],
        'timeout' => 15,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('OpenAI moderation request failed: @message', ['@message' => $e->getMessage()]);
      throw new OpenAiException('OpenAI moderation request failed.', previous: $e);
    }

    $body = json_decode((string) $response->getBody(), TRUE);
    if (!isset($body['results'][0]['flagged'])) {
      $this->logger->error('OpenAI moderation response was malformed: @body', ['@body' => substr((string) $response->getBody(), 0, 500)]);
      throw new OpenAiException('OpenAI moderation response was malformed.');
    }

    return (bool) $body['results'][0]['flagged'];
  }

}
