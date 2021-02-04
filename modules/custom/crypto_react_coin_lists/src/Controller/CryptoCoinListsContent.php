<?php

namespace Drupal\crypto_react_coin_lists\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Declare CryptoCoinListsContent class.
 */
class CryptoCoinListsContent extends ControllerBase {

  /**
   * It will return html data.
   *
   * @return html
   *   Return html output.
   */
  public function content() {

    $output[] = [
      '#theme' => 'crypto_top_currencies',
    ];

    $output[] = [
      '#theme' => 'crypto_lists_content',
    ];

    return $output;
  }

}
