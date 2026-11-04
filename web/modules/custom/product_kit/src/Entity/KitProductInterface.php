<?php

declare(strict_types=1);

namespace Drupal\product_kit\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;

/**
 * Provides an interface for the Kit line (kit_product) entity.
 */
interface KitProductInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Gets the parent kit, or NULL if it is missing.
   */
  public function getKit(): ?KitInterface;

  /**
   * Gets the referenced product, or NULL if it is missing.
   */
  public function getProduct(): ?ProductInterface;

  /**
   * Gets the number of units of the product bundled in the kit.
   */
  public function getQuantity(): int;

  /**
   * Gets this line's contribution to the kit price (quantity * unit price).
   */
  public function getLineTotal(): int;

}
