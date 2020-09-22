<?php

namespace Drupal\file_upload_options\Services;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;
use Drupal\file\Entity\File;

/**
 * File Upload Service.
 */
class FileUploadService {

  /**
   * Field definition.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Entity Type Manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected $entityTypeBundleInfo;

  /**
   * The constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The configuration factory service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    EntityFieldManagerInterface $entityFieldManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    ConfigFactoryInterface $configFactory
    ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->configFactory = $configFactory;
  }

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
      if ($files = file_upload_options_file_managed_file_save_upload($element, $form_state)) {
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
