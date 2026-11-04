<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\product_kit\Entity\KitInterface;

/**
 * Derives a kit's price and stock from its products.
 *
 * Neither value is ever stored: a kit is a projection of the products it is
 * made of, so there is nothing that can drift out of sync.
 *
 *  - price = sum over every line of (product price * line quantity);
 *  - stock = min over every line of floor(product stock / line quantity), i.e.
 *    how many complete kits can be assembled from the products in inventory.
 *
 * Both are also available for a plain list of "lines" (product ID + quantity),
 * so a form can preview the totals of an unsaved kit.
 *
 * A "line" here is an array with the keys "product" (product ID) and
 * "quantity" (int).
 */
class KitCalculator {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Gets the price of a saved kit, in minor currency units.
   */
  public function getPrice(KitInterface $kit): int {
    return $this->priceForLines($this->linesOf($kit));
  }

  /**
   * Gets how many complete units of a saved kit can be assembled right now.
   */
  public function getStock(KitInterface $kit): int {
    return $this->stockForLines($this->linesOf($kit));
  }

  /**
   * Calculates the price of a kit made of the given lines.
   *
   * @param array<int, array{product: int|string|null, quantity: int|string}> $lines
   *   The lines. Lines without a known product or with a quantity below 1 are
   *   ignored.
   */
  public function priceForLines(array $lines): int {
    $products = $this->loadProducts($lines);

    $total = 0;
    foreach ($lines as $line) {
      $quantity = (int) $line['quantity'];
      $product = $products[$line['product']] ?? NULL;
      if ($product && $quantity > 0) {
        $total += $quantity * $product->getPrice();
      }
    }
    return $total;
  }

  /**
   * Calculates how many units of a kit made of the given lines can be built.
   *
   * @param array<int, array{product: int|string|null, quantity: int|string}> $lines
   *   The lines. Lines with a quantity below 1 are ignored; a line whose
   *   product no longer exists can supply nothing, so it makes the kit
   *   unavailable rather than silently being skipped.
   */
  public function stockForLines(array $lines): int {
    $products = $this->loadProducts($lines);

    $stock = NULL;
    foreach ($lines as $line) {
      $quantity = (int) $line['quantity'];
      if ($quantity < 1 || empty($line['product'])) {
        continue;
      }
      $product = $products[$line['product']] ?? NULL;
      $possible = $product ? intdiv($product->getStock(), $quantity) : 0;
      $stock = $stock === NULL ? $possible : min($stock, $possible);
    }
    return $stock ?? 0;
  }

  /**
   * Turns a saved kit's lines into plain product/quantity pairs.
   *
   * @return array<int, array{product: int, quantity: int}>
   */
  protected function linesOf(KitInterface $kit): array {
    $lines = [];
    foreach ($kit->getLines() as $line) {
      $lines[] = [
        'product' => (int) $line->get('product')->target_id,
        'quantity' => $line->getQuantity(),
      ];
    }
    return $lines;
  }

  /**
   * Loads the products referenced by the given lines.
   *
   * Products are loaded from storage rather than through a line's ->entity
   * reference: the reference caches the entity it resolved, so a stock or
   * price changed earlier in the same request would not be seen through it.
   *
   * @return \Drupal\product_kit\Entity\ProductInterface[]
   *   Keyed by product ID.
   */
  protected function loadProducts(array $lines): array {
    $ids = [];
    foreach ($lines as $line) {
      if (!empty($line['product'])) {
        $ids[$line['product']] = $line['product'];
      }
    }
    if (!$ids) {
      return [];
    }

    /** @var \Drupal\product_kit\Entity\ProductInterface[] $products */
    $products = $this->entityTypeManager->getStorage('product')->loadMultiple($ids);
    return $products;
  }

}
