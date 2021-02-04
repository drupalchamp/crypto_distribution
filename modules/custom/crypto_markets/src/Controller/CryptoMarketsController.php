<?php

namespace Drupal\crypto_markets\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;

/**
 * Declare CryptoMarketsController class.
 */
class CryptoMarketsController extends ControllerBase {

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
   * The storage handler class for nodes.
   *
   * @var \Drupal\node\NodeStorage
   */
  protected $entityTypeManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * An http client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $current_path_stack
   *   The current path stack service.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity
   *   The Entity type manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http_client.
   */
  public function __construct(CurrentPathStack $current_path_stack, AliasManagerInterface $alias_manager, Connection $database, EntityTypeManagerInterface $entity, ConfigFactoryInterface $config_factory, ClientInterface $http_client) {
    $this->currentPathStack = $current_path_stack;
    $this->aliasManager = $alias_manager;
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.current'),
      $container->get('path_alias.manager'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('config.factory'),
      $container->get('http_client')
    );
  }

  /**
   * Return the title of the markets page.
   */
  public function title() {
    $title = $this->t('Markets');

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && $path_args[3] == 'markets' && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];
        $currency_name = $this->database->query("SELECT title FROM {node_field_data} WHERE nid = :currency_nid", [':currency_nid' => $currency_nid])->fetchField();
        $title = $this->t('@name Top USD Markets', ['@name' => $currency_name]);
      }
    }

    return $title;
  }

  /**
   * Return the markets lists of the currency.
   */
  public function content() {
    $output = ['#markup' => $this->t('Currency Top USD Markets')];

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && $path_args[3] == 'markets' && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];

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
          // Fetch the USD records from the default exchange.
          if ($node->hasField('field_symbol') && !$node->get('field_symbol')->isEmpty()) {
            $symbol = $node->get('field_symbol')->value;
          }
        }

        if (isset($symbol)) {
          $fsym = $symbol;
          $tsym = 'USD';
          $method = 'GET';
          $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
          $url = "https://min-api.cryptocompare.com/data/top/exchanges/full?fsym=$fsym&tsym=$tsym&limit=2000&api_key=$key";
          $options = [
            'timeout' => 600,
            'headers' => [
              'Accept' => 'application/json',
            ],
          ];

          try {
            $response = $this->httpClient->request($method, $url, $options);
            $code = $response->getStatusCode();
            if ($code == 200) {
              $body = $response->getBody()->getContents();
              $response_data = json_decode($body);
              $count = 1;
              $rows = [];
              foreach ($response_data->Data->Exchanges as $data) {
                $exchange_url = str_replace(" ", "-", strtolower($data->MARKET));
                $row = [
                  $count,
                  Link::fromTextAndUrl(
                    $data->MARKET,
                    Url::fromUserInput('/exchanges/' . $exchange_url, [
                      'absolute' => TRUE,
                    ])
                  ),
                  $data->FROMSYMBOL . '/' . $data->TOSYMBOL,
                  '$' . $data->PRICE,
                  '$' . round($data->VOLUME24HOURTO, 2),
                  date('d/m/Y', $data->LASTUPDATE),
                ];

                $count++;
                $rows[] = $row;
              }

              $markets = [
                '#theme' => 'table',
                '#header' => [
                  '#',
                  'Exchange',
                  'Market Name',
                  'Price',
                  'Volume(24h)',
                  'Last Updated',
                ],
                '#rows' => $rows,
                '#attributes' => ['id' => 'markets-table'],
              ];

              $output = render($markets);
            }
          }
          catch (\Exception $e) {
            return FALSE;
          }

        }

      }
    }

    $build = [
      '#theme' => 'default',
      '#cache' => ['max-age' => 0],
      '#data' => $output,
    ];

    return $build;
  }

}
