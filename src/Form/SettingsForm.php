<?php

namespace Drupal\file_upload_options\Form;

use Drupal\file_upload_options\Services\FileUploadService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Mail\MailManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a form to configure File Upload Options settings.
 */
class SettingsForm extends ConfigFormBase {

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;
  
  /**
   * File upload service.
   *
   * @var \Drupal\file_upload_options\Services\FileUploadService
   */
  protected $fileUploadServce;

  /**
   * Supported fields.
   *
   * @var array
   */
  protected $supportedFields;

  /**
   * The constructor.
   * 
   * @param EntityTypeManagerInterface $entityTypeManager
   * @param EntityFieldManagerInterface $entityFieldManager
   * @param EntityTypeBundleInfoInterface $entityTypeBundleInfo
   * @param FileSystemInterface $fileSystem
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, EntityFieldManagerInterface $entityFieldManager, EntityTypeBundleInfoInterface $entityTypeBundleInfo, FileSystemInterface $fileSystem, FileUploadService $fileUploadService) {
    $this->entityTypeManager = $entityTypeManager;
    $this->entityFieldManager = $entityFieldManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->fileSystem = $fileSystem;
    $this->fileUploadService = $fileUploadService;
    $this->supportedFields = $this->fileUploadService->getSupportedFields();
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('entity_type.bundle.info'),
      $container->get('file_system'),
      $container->get('file_upload_options.file_upload_service')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'file_upload_options_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['file_upload_options.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('file_upload_options.settings');
    
    if ($this->supportedFields) {
      foreach ($this->supportedFields as $entityType => $fields) {
        $form[$entityType] = [
          '#type' => 'details',
          '#title' => $entityType,
          '#open' => TRUE
        ];
        foreach ($fields as $fieldId => $fieldInfo) {
          $form[$entityType]['upload_option__' . str_replace('.', '_', $fieldId)] = [
            '#type' => 'select',
            '#title' => $fieldInfo['bundle']. ' - ' . $fieldInfo['field_name'],
            '#description' => $this->t('Select the method of handling where there is a file already with the same name as the file being uploaded.<br><em>Note that the Replace option will replace a file with the same name even if that file is being used/referenced by another file field. Use at your own risk.</em>'),
            '#default_value' => $config->get('upload_option.' . $fieldId, $this->fileSystem::EXISTS_RENAME),
            '#options' => [
              -1 => $this->t('Current behaviour'),
              $this->fileSystem::EXISTS_RENAME => $this->t('Rename the new file'),
              $this->fileSystem::EXISTS_REPLACE => $this->t('Replace the existing file.'),
              $this->fileSystem::EXISTS_ERROR => $this->t('Prevent the file from being uploaded.'),
            ],
          ];
        }
      }
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Check for supported fields first.
    if ($this->supportedFields) {
      $config = $this->config('file_upload_options.settings');
      $values = $form_state->getValues();
      foreach ($this->supportedFields as $entityType => $fields) {
        foreach ($fields as $fieldId => $fieldInfo) {          
          $config->set('upload_option.' . $fieldId, $values['upload_option__' . str_replace('.', '_', $fieldId)]);          
        }
      }
      $config->save();
    }

    parent::submitForm($form, $form_state);
  }

}
