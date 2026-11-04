<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the UniqueKitProduct constraint.
 */
class UniqueKitProductValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    /** @var \Drupal\product_kit\Entity\KitProductInterface|null $value */
    if ($value === NULL) {
      return;
    }

    $kit_id = $value->get('kit')->target_id;
    $product_id = $value->get('product')->target_id;
    if (empty($kit_id) || empty($product_id)) {
      return;
    }

    $query = $this->entityTypeManager->getStorage('kit_product')->getQuery()
      ->accessCheck(FALSE)
      ->condition('kit', $kit_id)
      ->condition('product', $product_id);
    if (!$value->isNew()) {
      $query->condition('id', $value->id(), '<>');
    }

    if ((int) $query->count()->execute() > 0) {
      $this->context->buildViolation($constraint->message)
        ->atPath('product')
        ->addViolation();
    }
  }

}
