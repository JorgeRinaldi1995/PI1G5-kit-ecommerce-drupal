<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Displays an integer price field, stored in minor units, as a decimal amount.
 */
#[FieldFormatter(
  id: 'product_kit_price',
  label: new TranslatableMarkup('Price (decimal amount)'),
  field_types: ['integer'],
)]
class PriceFormatterPlugin extends FormatterBase {

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    string $label,
    string $view_mode,
    array $third_party_settings,
    #[Autowire(service: 'product_kit.price_formatter')]
    protected readonly PriceFormatter $priceFormatter,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode): array {
    $elements = [];
    foreach ($items as $delta => $item) {
      $elements[$delta] = ['#plain_text' => $this->priceFormatter->format((int) $item->value)];
    }
    return $elements;
  }

}
