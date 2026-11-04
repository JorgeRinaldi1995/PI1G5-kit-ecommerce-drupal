<?php

declare(strict_types=1);

namespace Drupal\product_kit\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * Admin landing page with shortcuts to every Product Kit admin screen.
 */
class ProductKitOverviewController extends ControllerBase {

  /**
   * Builds the overview page.
   */
  public function overview(): array {
    $groups = [
      [
        'title' => $this->t('Products'),
        'count' => $this->countEntities('product'),
        'actions' => [
          ['title' => $this->t('View all products'), 'route' => 'entity.product.collection'],
          ['title' => $this->t('Add product'), 'route' => 'entity.product.add_form', 'primary' => TRUE],
        ],
      ],
      [
        'title' => $this->t('Kits'),
        'count' => $this->countEntities('kit'),
        'actions' => [
          ['title' => $this->t('View all kits'), 'route' => 'entity.kit.collection'],
          ['title' => $this->t('Add kit'), 'route' => 'entity.kit.add_form', 'primary' => TRUE],
        ],
      ],
      [
        'title' => $this->t('Kit lines'),
        'count' => $this->countEntities('kit_product'),
        'actions' => [
          ['title' => $this->t('View all kit lines'), 'route' => 'entity.kit_product.collection'],
          ['title' => $this->t('Add kit line'), 'route' => 'entity.kit_product.add_form', 'primary' => TRUE],
        ],
      ],
      [
        'title' => $this->t('Settings'),
        'actions' => [
          ['title' => $this->t('Product settings'), 'route' => 'entity.product.settings'],
          ['title' => $this->t('Kit settings'), 'route' => 'entity.kit.settings'],
          ['title' => $this->t('Kit line settings'), 'route' => 'entity.kit_product.settings'],
        ],
      ],
    ];

    $build = [
      '#type' => 'container',
      '#attributes' => ['class' => ['product-kit-overview']],
      // The counts change whenever an entity is created or deleted.
      '#cache' => ['tags' => [
        ...$this->entityTypeManager()->getDefinition('product')->getListCacheTags(),
        ...$this->entityTypeManager()->getDefinition('kit')->getListCacheTags(),
        ...$this->entityTypeManager()->getDefinition('kit_product')->getListCacheTags(),
      ]],
    ];

    foreach ($groups as $i => $group) {
      $section = [
        '#type' => 'fieldset',
        '#title' => isset($group['count'])
          ? $this->t('@title (@count)', ['@title' => $group['title'], '@count' => $group['count']])
          : $group['title'],
      ];

      $section['links'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['product-kit-overview__actions']],
      ];
      foreach ($group['actions'] as $key => $action) {
        $classes = ['button'];
        if (!empty($action['primary'])) {
          $classes[] = 'button--primary';
        }
        $section['links'][$key] = [
          '#type' => 'link',
          '#title' => $action['title'],
          '#url' => Url::fromRoute($action['route']),
          '#attributes' => ['class' => $classes],
        ];
      }

      $build['group_' . $i] = $section;
    }

    return $build;
  }

  /**
   * Counts the entities of a type.
   */
  protected function countEntities(string $entity_type_id): int {
    return (int) $this->entityTypeManager()
      ->getStorage($entity_type_id)
      ->getQuery()
      ->accessCheck(FALSE)
      ->count()
      ->execute();
  }

}
