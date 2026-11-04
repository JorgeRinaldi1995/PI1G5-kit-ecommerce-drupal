<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\kit_catalog\Form\KitAddToCartForm;
use Drupal\product_kit\Entity\KitInterface;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public detail page for one kit (kit_catalog.kit_page).
 *
 * This is where "what's in this kit" lives -- the grid card
 * (KitGridForm::buildCard()) stays a lightweight entry point, linking here
 * rather than repeating the breakdown. Each line here links to that
 * product's own purchase page (kit_catalog.product_page), except when the
 * viewer cannot see that product at all (disabled and not an admin): the
 * line still shows, since a disabled product still counts toward this
 * kit's composition, price and stock per product_kit's rules, but it is
 * not a link to a page that would just deny access.
 */
class KitPageController extends ControllerBase {

  public function __construct(
    protected readonly PriceFormatter $priceFormatter,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('product_kit.price_formatter'),
    );
  }

  /**
   * Title callback for the route.
   */
  public function title(KitInterface $kit): string {
    return $kit->label();
  }

  /**
   * Builds the page.
   */
  public function view(KitInterface $kit): array {
    $build['#attached']['library'][] = 'kit_catalog/storefront';

    $build['detail'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['kit-catalog-detail']],
    ];

    $description = (string) $kit->get('description')->value;
    if ($description !== '') {
      $build['detail']['description'] = [
        '#markup' => '<div class="kit-catalog-detail__description">' . Xss::filter($description) . '</div>',
      ];
    }

    $build['detail']['price'] = [
      '#markup' => '<div class="kit-catalog-detail__price">' . $this->priceFormatter->format($kit->getPrice()) . '</div>',
    ];

    $can_sell = $kit->canBeSold();
    $build['detail']['stock'] = [
      '#markup' => '<div class="kit-catalog-detail__stock">'
      . ($can_sell
        ? $this->t('@count in stock', ['@count' => $kit->getStock()])
        : $this->t('Out of stock'))
      . '</div>',
    ];

    $items = [];
    foreach ($kit->getLines() as $line) {
      $product = $line->getProduct();
      $label = $product
        ? $this->t('@qty × @name', ['@qty' => $line->getQuantity(), '@name' => $product->label()])
        : $this->t('@qty × (missing product)', ['@qty' => $line->getQuantity()]);

      $items[] = ($product && $product->access('view'))
        ? [
          '#type' => 'link',
          '#title' => $label,
          '#url' => Url::fromRoute('kit_catalog.product_page', ['product' => $product->id()]),
        ]
        : ['#markup' => $label];
    }

    if ($items) {
      $build['detail']['products'] = [
        '#theme' => 'item_list',
        '#title' => $this->t('What is in this kit'),
        '#items' => $items,
        '#attributes' => ['class' => ['kit-catalog-detail__products']],
      ];
    }

    $build['detail']['add_to_cart'] = $this->formBuilder()->getForm(KitAddToCartForm::class, $kit);

    return $build;
  }

}
