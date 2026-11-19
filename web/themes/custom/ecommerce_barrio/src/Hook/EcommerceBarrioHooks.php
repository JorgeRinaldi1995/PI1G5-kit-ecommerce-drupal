<?php

namespace Drupal\ecommerce_barrio\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
/**
 * Hook implementations for ecommerce_barrio.
 */
class EcommerceBarrioHooks
{
    use StringTranslationTrait;
    /**
     * Implements hook_form_system_theme_settings_alter() for settings form.
     *
     * Replace Barrio setting options with subtheme ones.
     *
     * Example on how to alter theme settings form
     */
    #[Hook('form_system_theme_settings_alter')]
    public function formSystemThemeSettingsAlter(&$form, FormStateInterface $form_state)
    {
        $form['components']['navbar']['bootstrap_barrio_navbar_top_background']['#options'] = [
            'bg-primary' => $this->t('Primary'),
            'bg-secondary' => $this->t('Secondary'),
            'bg-light' => $this->t('Light'),
            'bg-dark' => $this->t('Dark'),
            'bg-white' => $this->t('White'),
            'bg-transparent' => $this->t('Transparent'),
        ];
        $form['components']['navbar']['bootstrap_barrio_navbar_background']['#options'] = [
            'bg-primary' => $this->t('Primary'),
            'bg-secondary' => $this->t('Secondary'),
            'bg-light' => $this->t('Light'),
            'bg-dark' => $this->t('Dark'),
            'bg-white' => $this->t('White'),
            'bg-transparent' => $this->t('Transparent'),
        ];
    }
}
