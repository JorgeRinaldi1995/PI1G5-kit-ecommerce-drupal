<?php

declare(strict_types=1);

namespace Drupal\product_kit\Plugin\Field\FieldWidget;

use Drupal\Core\Field\Attribute\FieldWidget;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\product_kit\PriceFormatter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lets a person enter an integer price field as a decimal amount.
 *
 * The field stores minor units (1050); the person types "10.50".
 */
#[FieldWidget(
  id: 'product_kit_price',
  label: new TranslatableMarkup('Price (decimal amount)'),
  field_types: ['integer'],
)]
class PriceWidget extends WidgetBase {

  public function __construct(
    string $plugin_id,
    mixed $plugin_definition,
    FieldDefinitionInterface $field_definition,
    array $settings,
    array $third_party_settings,
    #[Autowire(service: 'product_kit.price_formatter')]
    protected readonly PriceFormatter $priceFormatter,
  ) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $third_party_settings);
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(FieldItemListInterface $items, $delta, array $element, array &$form, FormStateInterface $form_state): array {
    $minor = $items[$delta]->value ?? NULL;

    $element += [
      '#type' => 'textfield',
      // Ungrouped, so what is shown is exactly what ::validateAmount() parses.
      '#default_value' => $minor === NULL || $minor === '' ? '' : $this->priceFormatter->format((int) $minor, FALSE),
      '#size' => 14,
      '#maxlength' => 24,
      '#attributes' => ['inputmode' => 'decimal', 'placeholder' => '0.00'],
      '#element_validate' => [[static::class, 'validateAmount']],
    ];
    return ['value' => $element];
  }

  /**
   * Element validator: turns the typed amount into minor units.
   *
   * Static because form element callbacks must be serializable with the form.
   */
  public static function validateAmount(array &$element, FormStateInterface $form_state): void {
    $input = trim((string) ($element['#value'] ?? ''));
    if ($input === '') {
      $form_state->setValueForElement($element, NULL);
      return;
    }

    $minor = \Drupal::service('product_kit.price_formatter')->parse($input);
    if ($minor === NULL) {
      $form_state->setError($element, new TranslatableMarkup('%title must be an amount such as 10.50: digits, with at most two decimals and no thousands separator.', [
        '%title' => $element['#title'] ?? new TranslatableMarkup('Price'),
      ]));
      return;
    }
    $form_state->setValueForElement($element, $minor);
  }

}
