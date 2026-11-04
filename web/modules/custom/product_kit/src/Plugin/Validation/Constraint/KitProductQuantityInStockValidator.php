<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Validation\Constraint;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\product_kit\KitCompositionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the KitProductQuantityInStock constraint.
 *
 * Only a new line, or one whose product or quantity is being changed, is
 * judged. Stock moves on its own (sales, restocking), so an untouched line
 * whose product later ran low must not make unrelated edits fail.
 */
class KitProductQuantityInStockValidator extends ConstraintValidator implements ContainerInjectionInterface {

  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly KitCompositionManager $compositionManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('product_kit.composition_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    /** @var \Drupal\product_kit\Entity\KitProductInterface|null $value */
    if ($value === NULL) {
      return;
    }

    $product = $value->getProduct();
    $quantity = $value->getQuantity();
    if (!$product || $quantity < 1) {
      return;
    }

    if (!$value->isNew()) {
      $stored = $this->entityTypeManager->getStorage('kit_product')->loadUnchanged($value->id());
      if ($stored
        && $stored->getQuantity() === $quantity
        && (int) $stored->get('product')->target_id === (int) $product->id()) {
        return;
      }
    }

    if ($quantity > $this->compositionManager->getMaxQuantity($product)) {
      $this->context->buildViolation($constraint->message)
        ->setParameter('@product', (string) $product->label())
        ->setParameter('@stock', (string) $product->getStock())
        ->setParameter('@quantity', (string) $quantity)
        ->atPath('quantity')
        ->addViolation();
    }
  }

}
