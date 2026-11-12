<?php

namespace Drupal\file_upload_options\Services;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;
use Drupal\file_upload_options\UploadOption;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * File Upload Service.
 */
class FileUploadService {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected ConfigFactoryInterface $configFactory,
    protected FileSystemInterface $fileSystem,
    protected RequestStack $requestStack,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Gets a list of fields supported by this module.
   *
   * @return array
   *   Array of supported fields.
   */
  public function getSupportedFields() {
    $supportedFields = [];
    $fileFields = $this->entityFieldManager->getFieldMapByFieldType('file');
    $fileFields = array_merge_recursive($fileFields, $this->entityFieldManager->getFieldMapByFieldType('image'));
    foreach ($fileFields as $entityType => $fields) {
      $bundles = $this->entityTypeBundleInfo->getBundleInfo($entityType);
      $entityDefinitions = $this->entityTypeManager->getDefinition($entityType);
      $entityName = $entityDefinitions->getLabel()->__toString();
      foreach ($fields as $fieldName => $field) {
        foreach ($field['bundles'] as $bundle) {
          $fieldDefinitions = $this->entityFieldManager->getFieldDefinitions($entityType, $bundle);
          // Check for ID.
          if (!method_exists($fieldDefinitions[$fieldName], 'id')) {
            $storageId = $fieldDefinitions[$fieldName]->getTargetEntityTypeId() . '.';
            $storageId .= $bundle . '.';
            $storageId .= $fieldDefinitions[$fieldName]->getName();
            if (method_exists($fieldDefinitions[$fieldName], 'label')) {
              $label = $fieldDefinitions[$fieldName]->label();
            }
            else {
              $label = $fieldDefinitions[$fieldName]->getLabel();
            }
            $supportedFields[$entityName][(string) $storageId] = [
              'bundle' => $bundles[$bundle]['label'],
              'field_name' => $label,
            ];
          }
          else {
            $supportedFields[$entityName][$fieldDefinitions[$fieldName]->id()] = [
              'bundle' => $bundles[$bundle]['label'],
              'field_name' => $fieldDefinitions[$fieldName]->label(),
            ];
          }
        }
      }
    }
    return $supportedFields;
  }

  /**
   * Get File Upload Settings configuration.
   *
   * @return config
   *   File Upload Options settings.
   */
  public function getConfig() {
    return $this->configFactory->get('file_upload_options.settings');
  }

  /**
   * Gets the configured file exists behaviour.
   *
   * @param string $file_id
   *   The entity_type.bundle.field_name identifier, or the element name for
   *   custom fields.
   * @param bool $is_custom_field
   *   Whether the element is a custom (non-entity) field.
   *
   * @return \Drupal\Core\File\FileExists
   *   The file exists behaviour. Defaults to rename.
   */
  public function getFileExists(string $file_id, bool $is_custom_field = FALSE): FileExists {
    $key = ($is_custom_field ? 'custom_fields.' : 'upload_option.') . $file_id;
    return UploadOption::fromConfig($this->getConfig()->get($key))->toFileExists();
  }

  /**
   * Saves files uploaded into a managed_file element.
   *
   * Equivalent to file_managed_file_save_upload(), using the configured file
   * exists behaviour.
   *
   * @param array $element
   *   The FAPI element whose values are being saved.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return \Drupal\file\FileInterface[]|false
   *   File entities keyed by file ID, or FALSE if the upload directory could
   *   not be created or no files were uploaded.
   */
  public function saveUpload(array $element, FormStateInterface $form_state): array|false {
    [$file_id, $is_custom_field] = $this->getFileId($element, $form_state);

    $upload_name = implode('_', $element['#parents']);
    $all_files = $this->requestStack->getCurrentRequest()->files->get('files', []);
    if (empty($all_files[$upload_name])) {
      return FALSE;
    }
    $file_upload = $all_files[$upload_name];

    $destination = $element['#upload_location'] ?? NULL;
    if (isset($destination) && !$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      $this->logger->notice('The upload directory %directory for the file field %name could not be created or is not accessible. A newly uploaded file could not be saved in this directory as a consequence, and the upload was canceled.', ['%directory' => $destination, '%name' => $element['#field_name']]);
      $form_state->setError($element, t('The file could not be uploaded.'));
      return FALSE;
    }

    $files_uploaded = $element['#multiple'] && count(array_filter($file_upload)) > 0;
    $files_uploaded |= !$element['#multiple'] && !empty($file_upload);
    if (!$files_uploaded) {
      return [];
    }

    $files = _file_save_upload_from_form($element, $form_state, NULL, $this->getFileExists($file_id, $is_custom_field));
    if (!$files) {
      $this->logger->notice('The file upload failed. %upload', ['%upload' => $upload_name]);
      return [];
    }

    // Value callback expects FIDs to be keys.
    $files = array_filter($files);
    $fids = array_map(fn ($file) => $file->id(), $files);
    return empty($files) ? [] : array_combine($fids, $files);
  }

  /**
   * Works out the config identifier for a managed_file element.
   *
   * @return array
   *   The file ID, and whether it is a custom (non-entity) field.
   */
  protected function getFileId(array $element, FormStateInterface $form_state): array {
    $form_object = $form_state->getFormObject();

    // Inline entity form: resolve the bundle of the referenced entity.
    if ($form_state->get('inline_entity_form')
      && (in_array('inline_entity_form', $element['#field_parents'] ?? [])
        || in_array('inline_entity_form', $element['#array_parents']))
    ) {
      $field_parent = in_array('inline_entity_form', $element['#array_parents'])
        ? $element['#array_parents'][0]
        : $element['#field_parents'][0];
      $ief_state = $form_state->get('inline_entity_form');
      $ief_id = $form_state->getCompleteForm()[$field_parent]['widget']['#ief_id'] ?? NULL;

      if (!$ief_id) {
        // Use the first target bundle from the field config.
        $field_config = $form_object->getEntity()->getFieldDefinition($field_parent);
        $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'];
        $bundle = reset($target_bundles);
      }
      elseif (isset($ief_state[$ief_id]['entities'][0])) {
        $bundle = $ief_state[$ief_id]['entities'][0]['entity']->bundle();
      }
      elseif (isset($ief_state[$ief_id]['form settings']['bundle'])) {
        $bundle = $ief_state[$ief_id]['form settings']['bundle'];
      }
      else {
        $field_config = reset($ief_state[$ief_id]);
        $target_bundles = $field_config->getSetting('handler_settings')['target_bundles'];
        $bundle = reset($target_bundles);
      }
      return [$element['#entity_type'] . '.' . $bundle . '.' . $element['#field_name'], FALSE];
    }

    // Entity form that actually contains the element.
    if (method_exists($form_object, 'getEntity') && array_key_exists('#entity_type', $element)) {
      return [$element['#entity_type'] . '.' . $form_object->getEntity()->bundle() . '.' . $element['#field_name'], FALSE];
    }

    // A custom field without an entity, or a name that doesn't match an
    // entity field.
    return [$element['#name'], TRUE];
  }

  /**
   * Overrides Drupal\file\Plugin\Field\FieldWidget\FileWidget::value().
   *
   * Form API callback. Retrieves the value for the file_generic field element.
   *
   * This method is assigned as a #value_callback in formElement() method.
   */
  public static function value($element, $input, FormStateInterface $form_state) {
    if ($input) {
      // Checkboxes lose their value when empty.
      // If the display field is present make sure its unchecked value is saved.
      if (empty($input['display'])) {
        $input['display'] = $element['#display_field'] ? 0 : 1;
      }
    }

    // We depend on the managed file element to handle uploads.
    $return = self::valueCallback($element, $input, $form_state);

    // Ensure that all the required properties are returned even if empty.
    $return += [
      'fids' => [],
      'display' => 1,
      'description' => '',
    ];

    return $return;
  }

  /**
   * Overrides Drupal\file\Element\ManagedFile::valueCallback().
   */
  public static function valueCallback(&$element, $input, FormStateInterface $form_state) {
    // Find the current value of this field.
    $fids = !empty($input['fids']) ? explode(' ', $input['fids']) : [];
    foreach ($fids as $key => $fid) {
      $fids[$key] = (int) $fid;
    }
    $force_default = FALSE;

    // Process any input and save new uploads.
    if ($input !== FALSE) {
      $input['fids'] = $fids;
      $return = $input;

      // Uploads take priority over all other values.
      if ($files = \Drupal::service('file_upload_options.file_upload_service')->saveUpload($element, $form_state)) {
        if ($element['#multiple']) {
          $fids = array_merge($fids, array_keys($files));
        }
        else {
          $fids = array_keys($files);
        }
      }
      else {
        // Check for #filefield_value_callback values.
        // Because FAPI does not allow multiple #value_callback values like it
        // does for #element_validate and #process, this fills the missing
        // functionality to allow File fields to be extended through FAPI.
        if (isset($element['#file_value_callbacks'])) {
          foreach ($element['#file_value_callbacks'] as $callback) {
            $callback($element, $input, $form_state);
          }
        }

        // Load files if the FIDs have changed to confirm they exist.
        if (!empty($input['fids'])) {
          $fids = [];
          foreach ($input['fids'] as $fid) {
            if ($file = File::load($fid)) {
              $fids[] = $file->id();
              // Temporary files that belong to other users should never be
              // allowed.
              if ($file->isTemporary()) {
                if ($file->getOwnerId() != \Drupal::currentUser()->id()) {
                  $force_default = TRUE;
                  break;
                }
                // Since file ownership can't be determined for anonymous users,
                // they are not allowed to reuse temporary files at all. But
                // they do need to be able to reuse their own files from earlier
                // submissions of the same form, so to allow that, check for the
                // token added by $this->processManagedFile().
                elseif (\Drupal::currentUser()->isAnonymous()) {
                  $token = NestedArray::getValue($form_state->getUserInput(), array_merge($element['#parents'], ['file_' . $file->id(), 'fid_token']));
                  $file_hmac = Crypt::hmacBase64('file-' . $file->id(), \Drupal::service('private_key')->get() . Settings::getHashSalt());
                  if ($token === NULL || !hash_equals($file_hmac, $token)) {
                    $force_default = TRUE;
                    break;
                  }
                }
              }
            }
          }
          if ($force_default) {
            $fids = [];
          }
        }
      }
    }

    // If there is no input or if the default value was requested above, use the
    // default value.
    if ($input === FALSE || $force_default) {
      if ($element['#extended']) {
        $default_fids = isset($element['#default_value']['fids']) ? $element['#default_value']['fids'] : [];
        $return = isset($element['#default_value']) ? $element['#default_value'] : ['fids' => []];
      }
      else {
        $default_fids = isset($element['#default_value']) ? $element['#default_value'] : [];
        $return = ['fids' => []];
      }

      // Confirm that the file exists when used as a default value.
      if (!empty($default_fids)) {
        $fids = [];
        foreach ($default_fids as $fid) {
          if ($file = File::load($fid)) {
            $fids[] = $file->id();
          }
        }
      }
    }

    $return['fids'] = $fids;
    return $return;
  }

}
