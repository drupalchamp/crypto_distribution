<?php

namespace Drupal\crypto_chart\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Url;

/**
 * Defines a Twitter Social Media Feeds Block type.
 *
 * @Block(
 *   id = "twitter_social_media_feeds_block",
 *   admin_label = @Translation("Twitter Social Media Feeds Block"),
 *   category = @Translation("Social Media Feeds Block"),
 * )
 */
class TwitterSocialMediaFeedsBlock extends BlockBase implements ContainerFactoryPluginInterface {

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
   * The storage handler class for nodes.
   *
   * @var \Drupal\node\NodeStorage
   */
  protected $nodeStorage;

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Path\CurrentPathStack $current_path_stack
   *   The current path stack service.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity
   *   The Entity type manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, CurrentPathStack $current_path_stack, AliasManagerInterface $alias_manager, EntityTypeManagerInterface $entity) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->currentPathStack = $current_path_stack;
    $this->aliasManager = $alias_manager;
    $this->nodeStorage = $entity->getStorage('node');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('path.current'),
      $container->get('path_alias.manager'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && !empty($path_args[3]) && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];
        $currency_data = $this->nodeStorage->load($currency_nid);
        if (!empty($currency_data) && ($currency_data->getType() == 'currency')) {
          if ($currency_data->hasField('field_twitter_link') && !$currency_data->get('field_twitter_link')->isEmpty()) {
            $url = $currency_data->get('field_twitter_link')->uri;
            $isValidURL = UrlHelper::isValid($url, $absolute = FALSE);
            if ($isValidURL) {
              $build = [
                '#type' => 'link',
                '#title' => $this->t('Twitter Timeline'),
                '#url' => Url::fromUri($url),
                '#attributes' => [
                  'class' => 'twitter-timeline',
                ],
                '#attached' => [
                  'library' => ['crypto_chart/twitter_widgets'],
                ],
              ];

              $build = [
                '#theme' => 'default',
                '#cache' => ['max-age' => 0],
                '#data' => render($build),
              ];

              return $build;
            }
          }
        }
      }
    }

    $build = [
      '#theme' => 'default',
      '#cache' => ['max-age' => 0],
      '#data' => '',
    ];

    return $build;
  }

}
