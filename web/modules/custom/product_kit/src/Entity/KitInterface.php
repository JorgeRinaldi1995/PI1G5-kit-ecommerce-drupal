<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for the Kit entity.
 *
 * A kit's price and stock are not data of the kit: they are derived from its
 * products (see \Drupal\product_kit\KitCalculator) each time they are asked
 * for, so they can never disagree with the products.
 */
interface KitInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Gets the derived kit price, in minor currency units (e.g. cents).
   *
   * The sum over every line of (product price * line quantity). 0 for a kit
   * that has not been saved yet.
   */
  public function getPrice(): int;

  /**
   * Gets the derived kit stock: how many complete kits can be assembled.
   *
   * The minimum over every line of floor(product stock / line quantity). 0 for
   * a kit that has not been saved yet.
   */
  public function getStock(): int;

  /**
   * Whether the kit can currently be sold: it is enabled and has stock.
   */
  public function canBeSold(): bool;

  /**
   * Loads the kit_product lines that belong to this kit.
   *
   * @return \Drupal\product_kit\Entity\KitProductInterface[]
   *   The lines, keyed by their entity ID.
   */
  public function getLines(): array;

}
