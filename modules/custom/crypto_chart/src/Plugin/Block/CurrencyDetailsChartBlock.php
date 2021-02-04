<?php

namespace Drupal\crypto_chart\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Defines a Currency Details Chart Block type.
 *
 * @Block(
 *   id = "currency_details_chart_block",
 *   admin_label = @Translation("Currency Details Chart Block"),
 *   category = @Translation("Currency Details Chart Block"),
 * )
 */
class CurrencyDetailsChartBlock extends BlockBase implements ContainerFactoryPluginInterface {

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  protected $routeMatch;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Class constructor.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param string $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Entity type manager service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, RouteMatchInterface $route_match, EntityTypeManagerInterface $entityTypeManager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {

    $output = $series_data = [];
    $node = $this->routeMatch->getParameter('node');
    if ($node instanceof NodeInterface) {
      if ($node->getType() == 'currency') {

        $currency_nid = $node->id(); /* Currency Node ID. */

        $results = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'markets')
          ->condition('status', 1)
          ->condition('field_base_currency', $currency_nid)
          ->execute();

        if (!empty($results)) {
          $market_nid = current($results);
          $market_data = $this->entityTypeManager->getStorage('node')->load($market_nid);

          if ($market_data->hasField('field_base_symbol') && !$market_data->get('field_base_symbol')->isEmpty()) {
            $symbol = $market_data->get('field_base_symbol')->value;
          }
        }
        else {
          // IF no any markets added then automatically
          // fetch the USD records from the default exchange.
          if ($node->hasField('field_symbol') && !$node->get('field_symbol')->isEmpty()) {
            $symbol = $node->get('field_symbol')->value;
          }
        }

        $output['symbol'] = @$symbol;
      }
    }

    $build = [
      '#theme' => 'currency_detail_chart',
      '#cache' => ['max-age' => 0],
      '#data' => $output,
    ];

    return $build;
  }

}
