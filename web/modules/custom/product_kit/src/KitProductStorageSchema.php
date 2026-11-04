<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorageSchema;

/**
 * Adds UNIQUE(kit, product) to the kit_product table.
 *
 * The UniqueKitProduct constraint gives a readable message; this key is what
 * keeps the rule true for concurrent saves and for code that skips validation.
 */
class KitProductStorageSchema extends SqlContentEntityStorageSchema {

  /**
   * {@inheritdoc}
   */
  protected function getEntitySchema(ContentEntityTypeInterface $entity_type, $reset = FALSE): array {
    $schema = parent::getEntitySchema($entity_type, $reset);

    $schema['kit_product']['unique keys']['kit_product__kit_product'] = ['kit', 'product'];

    return $schema;
  }

}
