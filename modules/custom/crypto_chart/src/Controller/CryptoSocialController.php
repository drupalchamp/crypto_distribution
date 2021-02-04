<?php

namespace Drupal\crypto_chart\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Database\Connection;

/**
 * Define CryptoSocialController class.
 */
class CryptoSocialController extends ControllerBase {

  /**
   * The current path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPathStack;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected $aliasManager;

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $current_path_stack
   *   The current path stack service.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(CurrentPathStack $current_path_stack, AliasManagerInterface $alias_manager, Connection $database) {
    $this->currentPathStack = $current_path_stack;
    $this->aliasManager = $alias_manager;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.current'),
      $container->get('path_alias.manager'),
      $container->get('database')
    );
  }

  /**
   * Declare title method here.
   */
  public function title() {
    $title = $this->t('Social');

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && $path_args[3] == 'social' && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];
        $currency_name = $this->database->query("SELECT title FROM {node_field_data} WHERE nid = :currency_nid", [':currency_nid' => $currency_nid])->fetchField();
        $title = $this->t('@name Social', ['@name' => $currency_name]);
      }
    }

    return $title;
  }

  /**
   * Declare Content method here.
   */
  public function content() {
    $output = '';
    return [
      '#type' => 'markup',
      '#markup' => $output,
    ];
  }

}
