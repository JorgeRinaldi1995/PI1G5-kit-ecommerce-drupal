<?php

declare(strict_types=1);

namespace Drupal\product_kit;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityTypeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * List builder for products.
 */
class ProductListBuilder extends EntityListBuilder {

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
      'price' => $this->t('Unit price'),
      'stock' => $this->t('Stock'),
      'kits' => $this->t('Kits'),
      'status' => $this->t('Enabled'),
    ] + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    /** @var \Drupal\product_kit\Entity\ProductInterface $entity */
    $row['name']['data'] = [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => $entity->toUrl(),
    ];
    $row['price'] = $this->priceFormatter->format($entity->getPrice());
    $row['stock'] = $entity->getStock();

    $kits = [];
    foreach ($entity->getLines() as $line) {
      if ($kit = $line->getKit()) {
        $kits[] = $line->getQuantity() > 1 ? $kit->label() . ' (×' . $line->getQuantity() . ')' : $kit->label();
      }
    }
    $row['kits'] = implode(', ', $kits);
    $row['status'] = $entity->isPublished() ? $this->t('Yes') : $this->t('No');

    return $row + parent::buildRow($entity);
  }

}
