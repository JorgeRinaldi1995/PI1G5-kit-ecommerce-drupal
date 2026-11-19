<?php

declare(strict_types=1);

namespace Drupal\kit_assistant\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The floating "Cleiton" chat bubble + panel, shown site-wide.
 *
 * Deliberately does NOT mint the CSRF token here, even though it would be
 * simpler to hand one to the client straight in drupalSettings. This block
 * is per-user (see getCacheMaxAge()), which makes Drupal render it as a
 * BigPipe placeholder on any page whose shell is otherwise cacheable --
 * and placeholder content is streamed to the client *after* the response's
 * headers are already sent. A session (and its Set-Cookie) started while
 * generating a token inside that placeholder can never reach the client on
 * that response; the next POST would carry a token whose seed the server
 * never persisted, and reject it. js/chat.js instead fetches a token from
 * `kit_assistant.token` (ChatController::token()) as its own top-level
 * request, where starting a session behaves normally. See that route and
 * this module's README for the full explanation.
 */
#[Block(
  id: 'kit_assistant_chat_widget',
  admin_label: new TranslatableMarkup('Cleiton (chat assistant)'),
  category: new TranslatableMarkup('Product Kit'),
)]
class ChatWidgetBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly AccountProxyInterface $account,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->configFactory->get('kit_assistant.settings')->get('enabled')) {
      return [];
    }

    return [
      '#theme' => 'kit_assistant_widget',
      '#attached' => [
        'library' => ['kit_assistant/chat'],
        'drupalSettings' => [
          'kitAssistant' => [
            'tokenUrl' => Url::fromRoute('kit_assistant.token')->toString(),
            'messageUrl' => Url::fromRoute('kit_assistant.message')->toString(),
            'resetUrl' => Url::fromRoute('kit_assistant.reset')->toString(),
            'loggedIn' => $this->account->isAuthenticated(),
            'loginUrl' => Url::fromRoute('user.login')->toString(),
          ],
        ],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Reflects login state (used for the "faça login" hint) -- same
   * reasoning as CartLinkBlock. The token itself is fetched separately
   * (see this class's docblock), so this max-age:0 is only about the
   * loggedIn flag staying correct per visitor, not about token freshness.
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
