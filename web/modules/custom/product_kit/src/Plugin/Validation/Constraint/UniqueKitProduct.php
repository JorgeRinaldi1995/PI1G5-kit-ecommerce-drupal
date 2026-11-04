<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A product may appear only once per kit.
 */
#[Constraint(
  id: 'UniqueKitProduct',
  label: new TranslatableMarkup('Unique product per kit', [], ['context' => 'Validation']),
  type: ['entity:kit_product'],
)]
class UniqueKitProduct extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = 'This product is already in the kit. Increase the quantity on the existing line instead of adding it again.';

}
