<?php

namespace Drupal\crypto_import\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Declare ImportDataController class.
 */
class ImportDataController extends ControllerBase {

  /**
   * Declare Content method here.
   */
  public function content() {
    import_currencies_data();
    return ['#markup' => $this->t('Currency Data Updated Successfully.')];
  }

}
