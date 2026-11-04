<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for kit lines (kit_product).
 */
class KitProductListBuilder extends EntityListBuilder {

  protected PriceFormatter $priceFormatter;

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static {
    $instance = parent::createInstance($container, $entity_type);
    $instance->priceFormatter = $container->get('product_kit.price_formatter');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    return [
      'kit' => $this->t('Kit'),
      'product' => $this->t('Product'),
      'quantity' => $this->t('Quantity'),
      'line_total' => $this->t('Line total'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\product_kit\Entity\KitProductInterface $entity */
    $kit = $entity->getKit();
    $product = $entity->getProduct();

    $row['kit'] = $kit ? $kit->toLink()->toString() : $this->t('- missing -');
    $row['product'] = $product ? $product->toLink()->toString() : $this->t('- missing -');
    $row['quantity'] = $entity->getQuantity();
    $row['line_total'] = $this->priceFormatter->format($entity->getLineTotal());

    return $row + parent::buildRow($entity);
  }

}
