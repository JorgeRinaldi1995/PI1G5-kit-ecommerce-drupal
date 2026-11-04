<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for kits.
 */
class KitListBuilder extends EntityListBuilder {

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
      'name' => $this->t('Name'),
      'products' => $this->t('Products'),
      'price' => $this->t('Price (calculated)'),
      'stock' => $this->t('Stock (calculated)'),
      'status' => $this->t('Enabled'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\product_kit\Entity\KitInterface $entity */
    $row['name']['data'] = [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => $entity->toUrl(),
    ];
    $row['products'] = count($entity->getLines());
    $row['price'] = $this->priceFormatter->format($entity->getPrice());
    $row['stock'] = $entity->getStock();
    $row['status'] = $entity->isPublished() ? $this->t('Yes') : $this->t('No');

    return $row + parent::buildRow($entity);
  }

}
