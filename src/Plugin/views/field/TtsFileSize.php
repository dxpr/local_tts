<?php

namespace Drupal\local_tts\Plugin\views\field;

use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\NumericField;
use Drupal\views\ResultRow;

/**
 * Displays a file size in human-readable format.
 */
#[ViewsField("local_tts_file_size")]
class TtsFileSize extends NumericField {

  /**
   * {@inheritdoc}
   */
  public function render(ResultRow $values) {
    $value = $this->getValue($values);
    if ($value === NULL) {
      return '';
    }
    return ByteSizeMarkup::create((int) $value);
  }

}
