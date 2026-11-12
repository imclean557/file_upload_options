<?php

namespace Drupal\file_upload_options\Plugin\rest\resource;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\Exception\FileExistsException;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Lock\LockAcquiringException;
use Drupal\file\Plugin\rest\resource\FileUploadResource;
use Drupal\file\Upload\ContentDispositionFilenameParser;
use Drupal\file\Upload\InputStreamUploadedFile;
use Drupal\image\Plugin\Field\FieldType\ImageItem;
use Drupal\rest\ModifiedResourceResponse;
use Symfony\Component\HttpFoundation\File\Exception\CannotWriteFileException;
use Symfony\Component\HttpFoundation\File\Exception\NoFileException;
use Symfony\Component\HttpFoundation\File\Exception\UploadException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Extends FileUploadResource to allow specifying file exists handling.
 */
class FileUploadOptionsResource extends FileUploadResource {

  /**
   * {@inheritdoc}
   *
   * Mirrors the core implementation, but uses the configured file exists
   * behaviour instead of always renaming.
   */
  public function post(Request $request, $entity_type_id, $bundle, $field_name) {
    $file_exists = \Drupal::service('file_upload_options.file_upload_service')
      ->getFileExists($entity_type_id . '.' . $bundle . '.' . $field_name);

    $field_definition = $this->validateAndLoadFieldDefinition($entity_type_id, $bundle, $field_name);
    $destination = $this->getUploadDestination($field_definition);

    // Check the destination file path is writable.
    if (!$this->fileSystem->prepareDirectory($destination, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new HttpException(500, 'Destination file path is not writable');
    }

    $settings = $field_definition->getSettings();
    $validators = $this->getFileUploadValidators($settings);
    if (is_a($field_definition->getItemDefinition()->getClass(), ImageItem::class, TRUE) && !array_key_exists('FileIsImage', $validators)) {
      $validators['FileIsImage'] = [];
    }

    if (!array_key_exists('FileExtension', $validators) && $settings['file_extensions'] === '') {
      // An empty array means 'all file extensions'.
      // @see \Drupal\file\Upload\FileUploadHandler::handleExtensionValidation
      $validators['FileExtension'] = [];
    }

    try {
      $filename = ContentDispositionFilenameParser::parseFilename($request);
      $temp_path = $this->inputStreamFileWriter->writeStreamToFile();
      $uploaded_file = new InputStreamUploadedFile($filename, $filename, $temp_path, @filesize($temp_path));

      // With FileExists::Replace, the handler reuses the existing file entity.
      $result = $this->fileUploadHandler->handleFileUpload($uploaded_file, $validators, $destination, $file_exists);
    }
    catch (LockAcquiringException $e) {
      throw new HttpException(503, $e->getMessage(), NULL, ['Retry-After' => 1]);
    }
    catch (UploadException $e) {
      $this->logger->error('Input data could not be read');
      throw new HttpException(500, 'Input file data could not be read', $e);
    }
    catch (CannotWriteFileException $e) {
      $this->logger->error('Temporary file data for could not be written');
      throw new HttpException(500, 'Temporary file data could not be written', $e);
    }
    catch (NoFileException $e) {
      $this->logger->error('Temporary file could not be opened for file upload');
      throw new HttpException(500, 'Temporary file could not be opened', $e);
    }
    catch (FileExistsException $e) {
      // Thrown when the configured behaviour is FileExists::Error.
      throw new HttpException(409, $e->getMessage(), $e);
    }
    catch (FileException) {
      throw new HttpException(500, 'Temporary file could not be moved to file location');
    }

    if ($result->hasViolations()) {
      $message = "Unprocessable Entity: file validation failed.\n";
      $errors = [];
      foreach ($result->getViolations() as $violation) {
        $errors[] = PlainTextOutput::renderFromHtml($violation->getMessage());
      }
      $message .= implode("\n", $errors);
      throw new UnprocessableEntityHttpException($message);
    }

    return new ModifiedResourceResponse($result->getFile(), 201);
  }

}
