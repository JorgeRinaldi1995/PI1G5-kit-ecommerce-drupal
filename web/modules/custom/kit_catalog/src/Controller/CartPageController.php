<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\kit_catalog\CartManager;
use Drupal\product_kit\Entity\KitInterface;
use Drupal\product_kit\Entity\ProductInterface;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read-only summary of the current user's cart (kit_catalog.cart_page).
 *
 * The one piece of UI this module's "Add to cart" buttons had been missing
 * since CartManager grew a second item kind in Fase 2
 * (addKitToCart()/addProductToCart(), two cart tables): somewhere a visitor
 * can actually see both kinds together, with one consistent total.
 * `CartManager::getKitItems()`/`getProductItems()`/`getItemCount()` existed
 * since then with no caller; this is that caller.
 *
 * Deliberately still not checkout: no "remove", no "edit quantity", no
 * "place order" button. A line for an item that was deleted after being
 * added is silently skipped (nothing to show, nothing to link to) --
 * harmless here since this page writes nothing, but see this module's
 * README for why that can't be the answer once an actual order exists.
 */
class CartPageController extends ControllerBase {

  public function __construct(
    protected readonly CartManager $cartManager,
    protected readonly PriceFormatter $priceFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('kit_catalog.cart_manager'),
      $container->get('product_kit.price_formatter'),
    );
  }

  /**
   * Builds the page.
   */
  public function view(): array {
    $uid = (int) $this->currentUser()->id();

    $rows = [];
    $total = 0;
    $total += $this->buildKitRows($uid, $rows);
    $total += $this->buildProductRows($uid, $rows);

    // This page's whole content is "whatever this one user's cart holds" --
    // without the 'user' cache context, Dynamic Page Cache would key its
    // cached response on the URL alone and serve one user's cart to the
    // next authenticated visitor who hits /cart; verified live in
    // development (a second test user was shown the first's cart and
    // total) before this line was added.
    if (!$rows) {
      return [
        '#markup' => '<p>' . $this->t('Your cart is empty.') . '</p>',
        '#cache' => ['contexts' => ['user']],
      ];
    }

    $build['#cache']['contexts'] = ['user'];
    $build['#attached']['library'][] = 'kit_catalog/storefront';

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Item'),
        $this->t('Quantity'),
        $this->t('Unit price'),
        $this->t('Subtotal'),
      ],
      '#rows' => $rows,
      '#attributes' => ['class' => ['kit-catalog-cart__table']],
    ];

    $build['total'] = [
      '#markup' => '<p class="kit-catalog-cart__total">' . $this->t('Total: @amount', [
        '@amount' => $this->priceFormatter->format($total),
      ]) . '</p>',
    ];

    return $build;
  }

  /**
   * Appends the user's kit lines to $rows, returns their combined total.
   */
  protected function buildKitRows(int $uid, array &$rows): int {
    $quantities = $this->cartManager->getKitItems($uid);
    if (!$quantities) {
      return 0;
    }

    /** @var \Drupal\product_kit\Entity\KitInterface[] $kits */
    $kits = $this->entityTypeManager()->getStorage('kit')->loadMultiple(array_keys($quantities));

    $total = 0;
    foreach ($quantities as $kit_id => $quantity) {
      $kit = $kits[$kit_id] ?? NULL;
      if (!$kit instanceof KitInterface) {
        continue;
      }
      $subtotal = $kit->getPrice() * $quantity;
      $total += $subtotal;
      $rows[] = $this->buildRow($kit->label(), 'kit_catalog.kit_page', ['kit' => $kit_id], $quantity, $kit->getPrice(), $subtotal);
    }
    return $total;
  }

  /**
   * Appends the user's bare product lines to $rows, returns their total.
   */
  protected function buildProductRows(int $uid, array &$rows): int {
    $quantities = $this->cartManager->getProductItems($uid);
    if (!$quantities) {
      return 0;
    }

    /** @var \Drupal\product_kit\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager()->getStorage('product')->loadMultiple(array_keys($quantities));

    $total = 0;
    foreach ($quantities as $product_id => $quantity) {
      $product = $products[$product_id] ?? NULL;
      if (!$product instanceof ProductInterface) {
        continue;
      }
      $subtotal = $product->getPrice() * $quantity;
      $total += $subtotal;
      $rows[] = $this->buildRow($product->label(), 'kit_catalog.product_page', ['product' => $product_id], $quantity, $product->getPrice(), $subtotal);
    }
    return $total;
  }

  /**
   * Builds one table row.
   */
  protected function buildRow(string $label, string $route, array $routeParameters, int $quantity, int $unitPrice, int $subtotal): array {
    return [
      // A cell that is itself an array is read as "a 'data' key holding
      // the actual content, every other key an HTML attribute" (see
      // \Drupal\Core\Render\Element\Table's own docblock example and the
      // table preprocessor). Skipping the 'data' wrapper -- an easy
      // mistake, since the link render array *is* already a perfectly
      // valid render array on its own -- means every one of its '#'
      // properties gets read as an attribute instead, and the theme layer
      // chokes on the first non-scalar one it finds (#url, a Url object).
      ['data' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute($route, $routeParameters),
      ]],
      $quantity,
      $this->priceFormatter->format($unitPrice),
      $this->priceFormatter->format($subtotal),
    ];
  }

}
