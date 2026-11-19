<?php

declare(strict_types=1);

namespace Drupal\kit_assistant;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;

/**
 * Per-visitor chat history, kept in PrivateTempStore (session-scoped).
 *
 * Deliberately not a content entity or a custom table: this is transient
 * conversational state, not store data worth persisting past the session
 * (same reasoning `kit_catalog` gives for using plain tables instead of
 * entities for cart rows, one step further -- here there isn't even a
 * reporting/admin need to keep it around). PrivateTempStore already keys
 * data per-session for anonymous users and per-account for authenticated
 * ones, which is exactly the isolation a chat history needs.
 *
 * Only plain user/assistant text turns are kept here -- not the raw
 * tool-call/tool-result messages a turn may have produced along the way.
 * Once a turn's final assistant reply exists, the tool exchange that
 * produced it has done its job; replaying it on every subsequent request
 * would only grow the token count for no benefit, since the reply already
 * reflects whatever the tools returned.
 */
class ConversationStore {

  protected const COLLECTION = 'kit_assistant_conversation';
  protected const KEY = 'history';

  public function __construct(
    protected readonly PrivateTempStoreFactory $tempStoreFactory,
    protected readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Gets the current visitor's stored history.
   *
   * @return array<int, array{role: string, content: string}>
   *   Plain user/assistant turns, oldest first.
   */
  public function getHistory(): array {
    $store = $this->tempStoreFactory->get(self::COLLECTION);
    $history = $store->get(self::KEY);
    return is_array($history) ? $history : [];
  }

  /**
   * Appends one user/assistant exchange, trimming to the configured cap.
   */
  public function appendTurn(string $userMessage, string $assistantReply): void {
    $history = $this->getHistory();
    $history[] = ['role' => 'user', 'content' => $userMessage];
    $history[] = ['role' => 'assistant', 'content' => $assistantReply];

    // Keep the most recent N exchanges (2 messages each) only -- bounds
    // both the token cost of every future request and the session storage
    // row's size.
    $turns = max(1, (int) ($this->configFactory->get('kit_assistant.settings')->get('history_turns') ?? 8));
    $maxMessages = $turns * 2;
    if (count($history) > $maxMessages) {
      $history = array_slice($history, -$maxMessages);
    }

    $this->tempStoreFactory->get(self::COLLECTION)->set(self::KEY, $history);
  }

  /**
   * Clears the current visitor's stored history.
   */
  public function reset(): void {
    $this->tempStoreFactory->get(self::COLLECTION)->delete(self::KEY);
  }

}
