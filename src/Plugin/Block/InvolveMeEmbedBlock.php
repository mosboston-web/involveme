<?php

namespace Drupal\involveme\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides an Involve.me Embed block.
 *
 * @Block(
 *   id = "involveme_embed",
 *   admin_label = @Translation("Involve.me Embed"),
 *   category = @Translation("Involve Me"),
 * )
 */
class InvolveMeEmbedBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ConfigFactoryInterface $config_factory, RouteMatchInterface $route_match) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->configFactory = $config_factory;
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('config.factory'),
      $container->get('current_route_match'),
    );
  }

  /**
   * {@inheritdoc}
   *
   * Varying by route gives each a separate cache entry so that the layout
   * builder preview with placeholders and the actual rendered block won't end
   * up in the same block cache.
   */
  public function getCacheContexts(): array {
    return Cache::mergeContexts(parent::getCacheContexts(), ['route']);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTags(): array {
    return Cache::mergeTags(parent::getCacheTags(), ['config:involveme.settings']);
  }

  /**
   * {@inheritdoc}
   *
   * Overridden to return a dot-free suggestion. The default implementation
   * derives the name from the admin label ("Involve.me Embed"), which produces
   * a suggestion containing a dot. The dot causes BlockAddForm's CONTAINS query
   * in getUniqueMachineName() to fail to match the already-placed block, so
   * every subsequent placement receives the same ID and triggers an
   * EntityStorageException.
   */
  public function getMachineNameSuggestion(): string {
    return 'involveme_embed';
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration(): array {
    return [
      'project_id' => '',
      'embed_type' => 'direct',
      'popup_config' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function blockForm($form, FormStateInterface $form_state): array {
    $form['project_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project ID'),
      '#description' => $this->t('The Involve.me project slug. For example, if the embed code contains <code>data-project="pi-day"</code>, enter <strong>pi-day</strong>.'),
      '#default_value' => $this->configuration['project_id'],
      '#required' => TRUE,
    ];

    $form['embed_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Embed type'),
      '#options' => [
        'direct' => $this->t('Direct Embed'),
        'popup_exit_intent' => $this->t('Popup: Exit Intent'),
        'popup_page_load' => $this->t('Popup: Page Load'),
        'popup_time_delay' => $this->t('Popup: Time Delay'),
      ],
      '#default_value' => $this->configuration['embed_type'],
      '#required' => TRUE,
    ];

    $form['popup_config'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional popup configuration'),
      '#description' => $this->t('Paste key: value pairs from the Involve.me popup embed code, one per line. Trailing commas are optional. Supports <code>popupSize</code> and all options below it in the embed snippet. Example:<br><code>triggerTimer: "15",<br>closeOnCompletionTimer: "5",<br>loadColor: "#571717"</code>'),
      '#default_value' => $this->configuration['popup_config'],
      '#rows' => 6,
      '#states' => [
        'visible' => [
          ':input[name$="[embed_type]"]' => ['!value' => 'direct'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function blockSubmit($form, FormStateInterface $form_state): void {
    $this->configuration['project_id'] = $form_state->getValue('project_id');
    $this->configuration['embed_type'] = $form_state->getValue('embed_type');
    $this->configuration['popup_config'] = $form_state->getValue('popup_config');
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $project_id = $this->configuration['project_id'];
    $embed_type = $this->configuration['embed_type'] ?? 'direct';
    $company_name = $this->configFactory->get('involveme.settings')->get('company_name');
    // Expecting paths of layout_builder.overrides.node.view during load and
    // layout_builder_iframe_modal.rebuild after editing.
    $is_layout_builder = str_starts_with($this->routeMatch->getRouteName() ?? '', 'layout_builder');
    if ($is_layout_builder) {
      return $this->buildPlaceholder($project_id);
    }

    if (empty($project_id) || empty($company_name)) {
      return [];
    }

    $org_url = 'https://' . $company_name . '.involve.me';
    // Involve.me has two distinct libraries that are loaded with /embed and
    // /embed?type=popup. The former is only loaded for direct embeds, and we
    // can attach it during build here, but the latter is being used for popup
    // links that need decorated during rendering, so we only attach that
    // library via JS in involveme-popup.js because it should only happen once
    // and needs to be deferred until after links are decorated.
    if ($embed_type === 'direct') {
      $wrapper_classes = $this->configFactory->get('involveme.settings')->get('embed_wrapper_classes');
      return [
        '#theme' => 'involveme_embed_block',
        '#project_id' => $project_id,
        '#wrapper_classes' => $wrapper_classes,
        '#attached' => [
          'html_head' => [
            [
              [
                '#type' => 'html_tag',
                '#tag' => 'script',
                '#attributes' => [
                  'src' => $org_url . '/embed',
                  'async' => TRUE,
                ],
              ],
              'involveme_embed_script',
            ],
          ],
        ],
      ];
    }

    // Popup types.
    $trigger_event_map = [
      'popup_exit_intent' => 'exit',
      'popup_page_load' => 'load',
      'popup_time_delay' => 'timer',
    ];
    $trigger_event = $trigger_event_map[$embed_type] ?? 'load';

    $extra_config = $this->parsePopupConfig($this->configuration['popup_config'] ?? '');

    $base_config = [
      'projectUrl' => $project_id,
      'organizationUrl' => $org_url,
      'embedMode' => 'popup',
      'triggerEvent' => $trigger_event,
      'popupSize' => 'medium',
      'title' => $project_id,
    ];
    // This type of trigger won't work without a value for triggerTimer.
    if ($trigger_event === 'timer') {
      $base_config['triggerTimer'] = '15';
    }
    // Give extra textarea config precedence over defaults.
    $js_config = array_merge($base_config, $extra_config);

    $trigger_key = $project_id . '_' . $trigger_event;

    return [
      '#markup' => '',
      '#attached' => [
        'library' => ['involveme/popup'],
        'drupalSettings' => [
          'involveme' => [
            'organizationUrl' => $org_url,
            'popupTriggers' => [
              $trigger_key => $js_config,
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * Returns a placeholder render array for Layout Builder preview.
   *
   * @param string $project_id
   *   The configured project ID, if any.
   *
   * @return array
   *   A render array with placeholder markup.
   */
  private function buildPlaceholder(string $project_id): array {
    $embed_type = $this->configuration['embed_type'] ?? 'direct';
    $type_labels = [
      'direct' => 'Direct Embed',
      'popup_exit_intent' => 'Popup: Exit Intent',
      'popup_page_load' => 'Popup: Page Load',
      'popup_time_delay' => 'Popup: Time Delay',
    ];
    $type_label = $type_labels[$embed_type] ?? $embed_type;
    $project_label = $project_id ?: '(no project set)';
    $label = 'Involve.me — ' . $type_label . ': ' . $project_label;
    return [
      '#plain_text' => $label,
    ];
  }

  /**
   * Parses textarea popup config into a key-value array.
   *
   * Accepts one key: value pair per line (trailing commas optional).
   * String values may be quoted or unquoted.
   *
   * @param string $raw
   *   Raw textarea input.
   *
   * @return array
   *   Parsed key-value pairs.
   */
  private function parsePopupConfig(string $raw): array {
    $config = [];
    foreach (explode("\n", $raw) as $line) {
      // Strip whitespace and trailing comma.
      $line = rtrim(trim($line), ',');
      if (empty($line)) {
        continue;
      }
      // Match key: "quoted value" or key: unquoted value.
      if (preg_match('/^(\w+)\s*:\s*"([^"]*)"$/', $line, $matches)) {
        $config[$matches[1]] = $matches[2];
      }
      elseif (preg_match('/^(\w+)\s*:\s*(.+)$/', $line, $matches)) {
        $config[$matches[1]] = trim($matches[2]);
      }
    }
    return $config;
  }

}
