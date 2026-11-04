<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Provides an interface for the Product entity.
 */
interface ProductInterface extends ContentEntityInterface, EntityChangedInterface, EntityPublishedInterface, EntityOwnerInterface {

  /**
   * Gets the unit price, in minor currency units (e.g. cents).
   */
  public function getPrice(): int;

  /**
   * Gets the number of units currently held in inventory.
   */
  public function getStock(): int;

  /**
   * Whether the product can currently be sold: it is enabled and has stock.
   *
   * Mirrors \Drupal\product_kit\Entity\KitInterface::canBeSold(); a product
   * does not need to belong to a kit to be sellable in its own right.
   */
  public function canBeSold(): bool;

  /**
   * Loads the kit_product lines that place this product in kits.
   *
   * @return \Drupal\product_kit\Entity\KitProductInterface[]
   *   The lines, keyed by their entity ID.
   */
  public function getLines(): array;

}
