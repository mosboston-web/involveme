<?php

namespace Drupal\involveme\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Involve.me integration settings for this site.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'involveme_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['involveme.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['company_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Company name'),
      '#description' => $this->t('The Involve.me organization subdomain. For example, if your embed script URL is <code>https://museum-of-science.involve.me/embed</code>, enter <strong>museum-of-science</strong>.'),
      '#default_value' => $this->config('involveme.settings')->get('company_name'),
      '#required' => TRUE,
    ];

    $form['embed_wrapper_classes'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Embed wrapper classes'),
      '#description' => $this->t('CSS classes applied to the wrapper div around the Direct Embed block. Separate multiple classes with spaces.'),
      '#default_value' => $this->config('involveme.settings')->get('embed_wrapper_classes'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('involveme.settings')
      ->set('company_name', $form_state->getValue('company_name'))
      ->set('embed_wrapper_classes', $form_state->getValue('embed_wrapper_classes'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
