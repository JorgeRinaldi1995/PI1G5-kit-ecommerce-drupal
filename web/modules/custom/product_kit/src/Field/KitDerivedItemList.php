<?php

declare(strict_types=1);

namespace Drupal\product_kit\Field;

use Drupal\Core\Field\FieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed field values derived from a kit's products: "price" and "stock".
 *
 * Nothing is stored; the value is calculated when the field is first read.
 */
class KitDerivedItemList extends FieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    /** @var \Drupal\product_kit\Entity\KitInterface $kit */
    $kit = $this->getEntity();

    $value = match ($this->getFieldDefinition()->getName()) {
      'price' => $kit->getPrice(),
      'stock' => $kit->getStock(),
    };
    $this->list[0] = $this->createItem(0, $value);
  }

}
