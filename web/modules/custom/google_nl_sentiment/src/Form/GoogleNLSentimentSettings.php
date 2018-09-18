<?php

namespace Drupal\google_nl_sentiment\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\field\Entity\FieldStorageConfig;

/**
 * Configure Google NL Autotag settings for this site.
 */
class GoogleNLSentimentSettings extends ConfigFormBase {

  private $entityTypeManager;

  /**
   * Constructor for settings form.
   */
  public function __construct() {
    $this->entityTypeManager = \Drupal::entityTypeManager();
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'google_nl_sentiment_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['google_nl_sentiment.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('google_nl_sentiment.settings');
    $form['sentiment_magnitude_threshold'] = [
      '#type' => 'number',
      '#step' => '.5',
      '#min' => 0,
      '#required' => TRUE,
      '#title' => $this->t('Sentiment magnitude threshold'),
      '#description' => $this->t('The threshold determines at what confidence level we consider a Google sentiment as valid. It should be a number greater than 0.'),
      '#default_value' => $config->get('sentiment_magnitude_threshold') ?? '1',
    ];
    foreach ($this->getContentTypeOptions() as $content_type_id => $content_type) {
      $form[$content_type_id] = [
        '#type' => 'fieldset',
        '#title' => $this->t('@type settings', [
          '@type' => $content_type,
        ]),
      ];
      $form[$content_type_id][$content_type_id . '_enabled'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Auto-sentiment'),
        '#default_value' => isset($config->get('content_types')[$content_type_id]),
        '#description' => $this->t('This is the list of content types that will receive the Google Auto Sentiment field. Note that unselecting one previously selected will delete the field and all field data.'),
      ];
      $text_fields = [
        'list_string',
        'text',
        'text_long',
        'text_with_summary',
        'string',
        'string_long',
      ];
      $fields = $this->entityTypeManager->getStorage('field_config')->loadByProperties(['entity_type' => 'node', 'bundle' => $content_type_id]);
      $field_options = [];
      foreach ($fields as $field_id => $field) {
        if (array_search($field->getType(), $text_fields)) {
          $field_options[$field_id] = $field->getLabel();
        }
      }
      $selected_fields = [];
      if ($configured_fields = $config->get('content_types')[$content_type_id] ?? NULL) {
        foreach ($configured_fields as $field) {
          $selected_fields[$field] = $field;
        }
      }
      $form[$content_type_id][$content_type_id . '_fields'] = [
        '#type' => 'checkboxes',
        '#options' => $field_options,
        '#title' => $this->t('Analysis fields'),
        '#description' => $this->t('All fields checked will be included in the Google sentiment analysis.'),
        '#default_value' => $selected_fields,
        '#states' => [
          'visible' => [
            ':input[name="' . $content_type_id . '_enabled' . '"]' => ['checked' => TRUE],
          ],
        ],
      ];
    }
    return parent::buildForm($form, $form_state);
  }

  /**
   * Reusable function to fetch available content types.
   *
   * @return array
   *   Array of content types, keyed by ID with labels as the values.
   */
  private function getContentTypeOptions() {
    $content_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $content_type_options = [];
    foreach ($content_types as $content_type) {
      $content_type_options[$content_type->id()] = $content_type->label();
    }
    return $content_type_options;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $content_types = [];
    foreach ($this->getContentTypeOptions() as $content_type_id => $content_type) {
      $cat_field = $this->entityTypeManager->getStorage('field_config')->loadByProperties([
        'entity_type' => 'node',
        'bundle' => $content_type_id,
        'field_name' => 'field_google_nl_sentiment',
      ]);
      if (count($cat_field) == 1 && !$form_state->getValue($content_type_id . '_enabled')) {
        reset($cat_field)->delete();
      }
      if ($form_state->getValue($content_type_id . '_enabled')) {
        $fields = [];
        foreach ($form_state->getValue($content_type_id . '_fields') as $field) {
          if ($field) {
            $fields[] = $field;
          }
        }
        $content_types[$content_type_id] = $fields;
      }
      if (count($cat_field) == 0 && $form_state->getValue($content_type_id . '_enabled')) {
        $field_storage = $this->entityTypeManager->getStorage('field_storage_config')->loadByProperties(['id' => 'node.field_google_nl_sentiment']);
        if (!$field_storage) {
          FieldStorageConfig::create([
            'field_name' => 'field_google_nl_sentiment',
            'type' => 'float',
            'entity_type' => 'node',
            'cardinality' => 1,
            'settings' => [],
            'locked' => FALSE,
            'translatable' => TRUE,
          ])->save();
        }
        $instance = [
          'field_name' => 'field_google_nl_sentiment',
          'entity_type' => 'node',
          'bundle' => $content_type_id,
          'label' => 'Google NL Sentiment',
          'description' => 'Google NL text sentiment.',
          'field_type' => 'float',
          'settings' => [
            'min' => '-1',
            'max' => '1',
            'prefix' => '',
            'suffix' => '',
          ],
        ];
        $this->entityTypeManager->getStorage('field_config')->create($instance)->save();
        $form_settings = $this->entityTypeManager
          ->getStorage('entity_form_display')
          ->load('node.' . $content_type_id . '.default');
        $content = $form_settings->get('content');
        $content['field_google_nl_sentiment'] = [
          'type' => 'number',
          'region' => 'content',
          'weight' => 11,
          'settings' => [],
          'third_party_settings' => [],
        ];
        $form_settings->set('content', $content);
        $form_settings->save();
      };
    }
    $this->config('google_nl_sentiment.settings')
      ->set('content_types', $content_types)
      ->set('sentiment_magnitude_threshold', $form_state->getValue('sentiment_magnitude_threshold'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
