<?php

declare(strict_types=1);

namespace Drupal\kit_assistant\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\kit_assistant\OpenAiClient;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Minimal settings: enable/disable the widget, pick the OpenAI models.
 *
 * The API key itself is deliberately not a field here -- config is
 * exportable (and, in this project, intended to be committed), which is
 * exactly where a secret must never live. See OpenAiClient's docblock and
 * this module's README for where the key actually comes from.
 */
class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $configFactory,
    TypedConfigManagerInterface $typedConfigManager,
    protected readonly OpenAiClient $client,
  ) {
    parent::__construct($configFactory, $typedConfigManager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('kit_assistant.openai_client'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['kit_assistant.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'kit_assistant_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('kit_assistant.settings');

    if (!$this->client->isConfigured()) {
      $form['no_key'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' . $this->t("No OPENAI_API_KEY is configured yet (environment variable, or \$settings['kit_assistant_openai_api_key']). The chat widget will show as unavailable until one is set. See the kit_assistant module README.") . '</div>',
      ];
    }

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable the chat widget'),
      '#default_value' => $config->get('enabled'),
    ];
    $form['model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Chat model'),
      '#default_value' => $config->get('model'),
      '#description' => $this->t('An OpenAI chat-completions model ID that supports tool calling, e.g. gpt-4o-mini.'),
      '#required' => TRUE,
    ];
    $form['moderation_model'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Moderation model'),
      '#default_value' => $config->get('moderation_model'),
      '#required' => TRUE,
    ];
    $form['max_tool_rounds'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum tool-call rounds per message'),
      '#default_value' => $config->get('max_tool_rounds'),
      '#min' => 1,
      '#max' => 10,
      '#description' => $this->t('Caps how many times, in a single visitor message, the assistant may call a tool and send the result back to the model before it must answer in plain text. Bounds worst-case API cost per message.'),
      '#required' => TRUE,
    ];
    $form['history_turns'] = [
      '#type' => 'number',
      '#title' => $this->t('Conversation turns remembered'),
      '#default_value' => $config->get('history_turns'),
      '#min' => 1,
      '#max' => 50,
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('kit_assistant.settings')
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('model', trim((string) $form_state->getValue('model')))
      ->set('moderation_model', trim((string) $form_state->getValue('moderation_model')))
      ->set('max_tool_rounds', (int) $form_state->getValue('max_tool_rounds'))
      ->set('history_turns', (int) $form_state->getValue('history_turns'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
