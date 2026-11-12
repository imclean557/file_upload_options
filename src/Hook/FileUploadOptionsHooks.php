<?php

namespace Drupal\file_upload_options\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Element;
use Drupal\file_upload_options\Plugin\rest\resource\FileUploadOptionsResource;
use Drupal\file_upload_options\Services\FileUploadService;
use Drupal\file_upload_options\UploadOption;

/**
 * Hook implementations for file_upload_options.
 */
class FileUploadOptionsHooks {

  /**
   * Element types that contain other form elements.
   */
  protected const CONTAINERS = [
    'details',
    'fieldset',
    'field_group',
    'vertical_tabs',
    '_layout_builder',
  ];

  public function __construct(
    protected FileUploadService $fileUploadService,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * Implements hook_form_alter().
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    $form_object = $form_state->getFormObject();
    // Check there's an entity to get.
    if (method_exists($form_object, 'getEntity')) {
      $entity = $form_object->getEntity();
      $entity_bundle = $entity->getEntityTypeId() . '.' . $entity->bundle() . '.';
      $this->addCallbacks($form, $this->fileUploadService->getSupportedFields(), $entity_bundle);
    }
    else {
      $this->processCustomFields($form);
    }
  }

  /**
   * Implements hook_inline_entity_form_entity_form_alter().
   */
  #[Hook('inline_entity_form_entity_form_alter')]
  public function inlineEntityFormEntityFormAlter(array &$entity_form, FormStateInterface $form_state): void {
    $entity_bundle = $entity_form['#entity_type'] . '.' . $entity_form['#bundle'] . '.';
    $this->addCallbacks($entity_form, $this->fileUploadService->getSupportedFields(), $entity_bundle);
  }

  /**
   * Implements hook_element_info_alter().
   */
  #[Hook('element_info_alter')]
  public function elementInfoAlter(array &$info): void {
    if (array_key_exists('managed_file', $info)) {
      $info['managed_file']['#value_callback'] = [FileUploadService::class, 'valueCallback'];
    }
  }

  /**
   * Implements hook_rest_resource_alter().
   */
  #[Hook('rest_resource_alter')]
  public function restResourceAlter(array &$definitions): void {
    if (isset($definitions['file:upload'])) {
      $definitions['file:upload']['class'] = FileUploadOptionsResource::class;
    }
  }

  /**
   * Sets the value callback on supported file fields, recursing containers.
   *
   * @param array $form
   *   The form, or a container within it.
   * @param array $supported_fields
   *   Supported file fields, from FileUploadService::getSupportedFields().
   * @param string $entity_bundle
   *   The entity type and bundle prefix, e.g. "node.article.".
   */
  protected function addCallbacks(array &$form, array $supported_fields, string $entity_bundle): void {
    foreach (Element::children($form) as $field_name) {
      $field = &$form[$field_name];
      if (!empty(Element::children($field)) && (!array_key_exists('#type', $field) || $this->isContainer($field))) {
        $this->addCallbacks($field, $supported_fields, $entity_bundle);
        continue;
      }
      foreach ($supported_fields as $field_data) {
        if (array_key_exists($entity_bundle . $field_name, $field_data)) {
          foreach (Element::children($form[$field_name]['widget']) as $delta) {
            $form[$field_name]['widget'][$delta]['#value_callback'] = [FileUploadService::class, 'value'];
          }
        }
      }
    }
  }

  /**
   * Checks whether an element is a container.
   */
  protected function isContainer(array $element): bool {
    return in_array($element['#type'], static::CONTAINERS, TRUE);
  }

  /**
   * Registers file elements on forms without an entity as custom fields.
   *
   * @param array $form
   *   The form array.
   */
  protected function processCustomFields(array &$form): void {
    $fields = $this->getFileFields($form);
    if (!$fields) {
      return;
    }
    $config = $this->configFactory->getEditable('file_upload_options.settings');
    $custom_fields = $config->get('custom_fields') ?? [];
    $changed = FALSE;
    foreach (array_keys($fields) as $field_name) {
      if (!array_key_exists($field_name, $custom_fields)) {
        // Set a default value.
        $config->set('custom_fields.' . $field_name, UploadOption::Rename->value);
        $changed = TRUE;
      }
    }
    if ($changed) {
      $config->save();
    }
  }

  /**
   * Gets managed_file and image elements, recursing into containers.
   *
   * @param array $container
   *   Form array or other element container.
   *
   * @return array
   *   File elements keyed by element name.
   */
  protected function getFileFields(array &$container): array {
    $fields = [];
    foreach (Element::children($container) as $field_name) {
      $field = &$container[$field_name];
      if (!array_key_exists('#type', $field) || in_array($field['#type'], ['details', 'fieldset'], TRUE)) {
        $fields += $this->getFileFields($field);
      }
      elseif (in_array($field['#type'], ['managed_file', 'image'], TRUE)) {
        $fields[$field_name] = &$field;
      }
    }
    return $fields;
  }

}
