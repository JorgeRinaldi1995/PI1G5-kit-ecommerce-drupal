<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A kit line cannot ask for more units of a product than are in stock.
 */
#[Constraint(
  id: 'KitProductQuantityInStock',
  label: new TranslatableMarkup('Kit line quantity within product stock', [], ['context' => 'Validation']),
  type: ['entity:kit_product'],
)]
class KitProductQuantityInStock extends SymfonyConstraint {

  /**
   * The violation message.
   *
   * @var string
   */
  public string $message = '@product has only @stock unit(s) in stock; a kit cannot ask for @quantity.';

}
