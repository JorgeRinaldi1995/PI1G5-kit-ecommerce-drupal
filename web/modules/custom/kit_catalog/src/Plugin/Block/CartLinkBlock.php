<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Plugin\Block;

use Drupal\Core\Block\Attribute\Block;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\kit_catalog\CartManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Cart icon + item count, meant for the theme's header/navbar region.
 *
 * Anonymous visitors get a plain login link (there is nothing to count --
 * adding to cart requires authentication everywhere else in this module,
 * see AddToCartFormBase). Authenticated visitors get CartManager's combined
 * kit+product count, same number CartForm's table would total.
 */
#[Block(
  id: 'kit_catalog_cart_link',
  admin_label: new TranslatableMarkup('Cart link'),
  category: new TranslatableMarkup('Product Kit'),
)]
class CartLinkBlock extends BlockBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    protected readonly CartManager $cartManager,
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
      $container->get('kit_catalog.cart_manager'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    if (!$this->account->isAuthenticated()) {
      return [
        '#type' => 'link',
        '#title' => [
          '#type' => 'inline_template',
          '#template' => '<span class="kit-catalog-cart-link__icon" aria-hidden="true"></span><span class="kit-catalog-cart-link__sr-label">{{ label }}</span>',
          '#context' => ['label' => $this->t('Entrar para comprar')],
        ],
        '#url' => Url::fromRoute('user.login'),
        '#attributes' => ['class' => ['kit-catalog-cart-link', 'kit-catalog-cart-link--anonymous']],
      ];
    }

    $count = $this->cartManager->getItemCount((int) $this->account->id());
    return [
      '#type' => 'link',
      '#title' => [
        '#type' => 'inline_template',
        '#template' => '<span class="kit-catalog-cart-link__icon" aria-hidden="true"></span><span class="kit-catalog-cart-link__sr-label">{{ label }}</span>{% if count > 0 %}<span class="kit-catalog-cart-link__badge">{{ count }}</span>{% endif %}',
        '#context' => [
          'label' => $this->t('Carrinho'),
          'count' => $count,
        ],
      ],
      '#url' => Url::fromRoute('kit_catalog.cart_page'),
      '#attributes' => ['class' => ['kit-catalog-cart-link']],
    ];
  }

  /**
   * {@inheritdoc}
   *
   * Per-user count, never render-cached -- same reasoning as
   * KitGridBlock::getCacheMaxAge().
   */
  public function getCacheMaxAge(): int {
    return 0;
  }

}
