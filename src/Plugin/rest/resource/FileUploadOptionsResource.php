<?php

namespace Drupal\file_upload_options\Plugin\rest\resource;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Plugin\rest\resource\FileUploadResource;
use Drupal\rest\ModifiedResourceResponse;
use Drupal\file\Entity\File;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Extends FileUploadResource to allow specifying file exists handling.
 */
class FileUploadOptionsResource extends FileUploadResource {

  /**
   * The config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $fileUploadOptionsConfig;

  /**
   * Gets configuration options.
   */
  public function getFileUploadOptionsConfig() {
    $this->fileUploadOptionsConfig = \Drupal::config('file_upload_options.settings');
  }

  /**
   * Creates a file from an endpoint.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $entity_type_id
   *   The entity type ID.
   * @param string $bundle
   *   The entity bundle. This will be the same as $entity_type_id for entity
   *   types that don't support bundles.
   * @param string $field_name
   *   The field name.
   *
   * @return \Drupal\rest\ModifiedResourceResponse
   *   A 201 response, on success.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\HttpException
   *   Thrown when temporary files cannot be written, a lock cannot be acquired,
   *   or when temporary files cannot be moved to their new location.
   */
  public function post(Request $request, $entity_type_id, $bundle, $field_name) {

    // Get selected file exists option.
    $this->getFileUploadOptionsConfig();
    $file_id = $entity_type_id . '.' . $bundle . '.' . $field_name;
    $replace = $this->fileUploadOptionsConfig->get('upload_option.' . $file_id);

    $filename = $this->validateAndParseContentDispositionHeader($request);

    $field_definition = $this->validateAndLoadFieldDefinition($entity_type_id, $bundle, $field_name);

    $destination = $this->getUploadLocation($field_definition->getSettings());

    // Check the destination file path is writable.
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    $validators = $this->getUploadValidators($field_definition);

    $prepared_filename = $this->prepareFilename($filename, $validators);

    // Create the file.
    $file_uri = "{$destination}/{$prepared_filename}";

    $temp_file_path = $this->streamUploadData();

    // Check for replace option.
    if ($replace > -1) {
      $file_uri = $this->fileSystem->getDestinationFilename($file_uri, $replace);
    }
    else {
      // If not, follow current behaviour.
      $file_uri = $this->fileSystem->getDestinationFilename($file_uri, FileSystemInterface::EXISTS_RENAME);
    }

    // Lock based on the prepared file URI.
    $lock_id = $this->generateLockIdFromFileUri($file_uri);

    if (!$this->lock->acquire($lock_id)) {
      throw new HttpException(503, sprintf('File "%s" is already locked for writing'), NULL, ['Retry-After' => 1]);
    }

    // If we are replacing an existing file, load it.
    if ($replace == FileSystemInterface::EXISTS_REPLACE && $existing_files = $this->entityTypeManager->getStorage('file')->loadByProperties(['uri' => $file_uri])) {
      $file = reset($existing_files);
      $file->setPermanent();
    }
    else {
      // Begin building file entity.
      $file = File::create([]);
      $file->setOwnerId($this->currentUser->id());
      $file->setFilename($prepared_filename);
      $file->setMimeType($this->mimeTypeGuesser->guess($prepared_filename));
      $file->setFileUri($file_uri);
      // Set the size. This is done in File::preSave() but we validate the file
      // before it is saved.
      $file->setSize(@filesize($temp_file_path));
    }

    // Validate the file entity against entity-level validation and field-level
    // validators.
    $this->validate($file, $validators);

    // Move the file to the correct location after validation.
    try {
      // Check for replace option.
      if ($replace > -1) {
        $this->fileSystem->move($temp_file_path, $file_uri, $replace);
      }
      else {
        // If not, follow current behaviour.
        $this->fileSystem->move($temp_file_path, $file_uri, FileSystemInterface::EXISTS_RENAME);
      }
    }
    catch (FileException $e) {
      throw new HttpException(500, 'Temporary file could not be moved to file location');
    }

    $file->save();

    $this->lock->release($lock_id);

    // 201 Created responses return the newly created entity in the response
    // body. These responses are not cacheable, so we add no cacheability
    // metadata here.
    return new ModifiedResourceResponse($file, 201);
  }

}
