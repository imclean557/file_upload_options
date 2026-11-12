<?php

namespace Drupal\file_upload_options;

use Drupal\Core\File\FileExists;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Upload options, as stored in file_upload_options.settings.
 *
 * Values match the removed FileSystemInterface::EXISTS_* constants so
 * existing config keeps working.
 */
enum UploadOption: int {

  case Default = -1;
  case Rename = 0;
  case Replace = 1;
  case Error = 2;

  /**
   * Gets the core file exists behaviour for this option.
   */
  public function toFileExists(): FileExists {
    return match ($this) {
      self::Replace => FileExists::Replace,
      self::Error => FileExists::Error,
      self::Default, self::Rename => FileExists::Rename,
    };
  }

  /**
   * Gets the option for a stored config value.
   *
   * @param mixed $value
   *   The stored value. NULL or unknown values give the default.
   */
  public static function fromConfig(mixed $value): self {
    return is_numeric($value) ? (self::tryFrom((int) $value) ?? self::Default) : self::Default;
  }

  /**
   * Gets select element options, keyed by stored value.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup[]
   *   Option labels.
   */
  public static function options(): array {
    return [
      self::Default->value => new TranslatableMarkup('Current behaviour'),
      self::Rename->value => new TranslatableMarkup('Rename the new file'),
      self::Replace->value => new TranslatableMarkup('Replace the existing file'),
      self::Error->value => new TranslatableMarkup('Prevent the file from being uploaded'),
    ];
  }

}
