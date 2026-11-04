<?php

declare(strict_types=1);

namespace Drupal\product_kit\Field;

use Drupal\Core\Field\EntityReferenceFieldItemList;
use Drupal\Core\TypedData\ComputedItemListTrait;

/**
 * Computed list of the kit_product lines that reference a kit or a product.
 */
class LineItemList extends EntityReferenceFieldItemList {

  use ComputedItemListTrait;

  /**
   * {@inheritdoc}
   */
  protected function computeValue(): void {
    $owner = $this->getEntity();

    /** @var \Drupal\product_kit\Entity\KitInterface|\Drupal\product_kit\Entity\ProductInterface $owner */
    foreach (array_keys($owner->getLines()) as $delta => $line_id) {
      $this->list[$delta] = $this->createItem($delta, ['target_id' => $line_id]);
    }
  }

}
