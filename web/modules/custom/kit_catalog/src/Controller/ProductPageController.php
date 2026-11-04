<?php

declare(strict_types=1);

namespace Drupal\kit_catalog\Controller;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Controller\ControllerBase;
use Drupal\kit_catalog\Form\ProductAddToCartForm;
use Drupal\product_kit\Entity\ProductInterface;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * The public purchase page for one product (kit_catalog.product_page).
 *
 * Reachable on its own, and linked to from a kit's detail page
 * (KitPageController) for each product the kit contains -- the "link na
 * tela do kit que redirecione para cada produto" this route exists for.
 */
class ProductPageController extends ControllerBase {

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
  public function title(ProductInterface $product): string {
    return $product->label();
  }

  /**
   * Builds the page.
   */
  public function view(ProductInterface $product): array {
    $build['#attached']['library'][] = 'kit_catalog/storefront';

    $build['detail'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['kit-catalog-detail']],
    ];

    if (!$product->get('image')->isEmpty()) {
      $build['detail']['image'] = $product->get('image')->view(['label' => 'hidden', 'type' => 'image']);
    }

    $description = (string) $product->get('description')->value;
    if ($description !== '') {
      $build['detail']['description'] = [
        '#markup' => '<div class="kit-catalog-detail__description">' . Xss::filter($description) . '</div>',
      ];
    }

    $build['detail']['price'] = [
      '#markup' => '<div class="kit-catalog-detail__price">' . $this->priceFormatter->format($product->getPrice()) . '</div>',
    ];

    $can_sell = $product->canBeSold();
    $build['detail']['stock'] = [
      '#markup' => '<div class="kit-catalog-detail__stock">'
      . ($can_sell
        ? $this->t('@count in stock', ['@count' => $product->getStock()])
        : $this->t('Out of stock'))
      . '</div>',
    ];

    $build['detail']['add_to_cart'] = $this->formBuilder()->getForm(ProductAddToCartForm::class, $product);

    return $build;
  }

}
