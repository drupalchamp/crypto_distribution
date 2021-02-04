<?php

namespace Drupal\crypto_history\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Logger\LoggerChannelFactory;
use GuzzleHttp\Exception\RequestException;

/**
 * Define CryptoHistoryController class.
 */
class CryptoHistoryController extends ControllerBase {

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
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $request;

  /**
   * Logger Factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactory
   */
  protected $logger;

  /**
   * Class constructor.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $current_path_stack
   *   The current path stack service.
   * @param \Drupal\path_alias\AliasManagerInterface $aliasManager
   *   The path alias manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http_client.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The Entity type manager service.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request
   *   The Request stack service.
   * @param \Drupal\Core\Logger\LoggerChannelFactory $logger
   *   The Logger Channel Factory service.
   */
  public function __construct(CurrentPathStack $current_path_stack, AliasManagerInterface $alias_manager, ConfigFactoryInterface $config_factory, ClientInterface $http_client, Connection $database, EntityTypeManagerInterface $entityTypeManager, RequestStack $request, LoggerChannelFactory $logger) {
    $this->currentPathStack = $current_path_stack;
    $this->aliasManager = $alias_manager;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->request = $request;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.current'),
      $container->get('path_alias.manager'),
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('request_stack'),
      $container->get('logger.factory')
    );
  }

  /**
   * Declare title method here.
   */
  public function title() {
    $title = $this->t('Historical Data');

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && $path_args[3] == 'historical-data' && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];
        $currency_name = $this->database->query("SELECT title FROM {node_field_data} WHERE nid = :currency_nid", [':currency_nid' => $currency_nid])->fetchField();
        $title = $this->t('@name Historical Data', ['@name' => $currency_name]);
      }
    }

    return $title;
  }

  /**
   * Declare Content method here.
   */
  public function content() {
    // Initialize history data empty results...
    $output = $this->t('<h2>Sorry, no data available.</h2> Something wrong in fetching history data of this currency, Please reload the page.');

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[3]) && $path_args[3] == 'historical-data' && !empty($path_args[2]) && $path_args[1] == 'currencies') {
      unset($path_args[3]);

      $path_alias = implode('/', $path_args);
      $path = $this->aliasManager->getPathByAlias($path_alias);

      if (preg_match('/node\/(\d+)/', $path, $matches)) {
        $currency_nid = $matches[1];

        // Bitfinex Exchange Node ID.
        $results = $this->entityTypeManager->getStorage('node')->getQuery()
          ->condition('type', 'markets')
          ->condition('status', 1)
          ->condition('field_base_currency', $currency_nid)
          ->execute();

        if (!empty($results)) {
          $market_nid = current($results);
          $market_data = $this->entityTypeManager->getStorage('node')->load($market_nid);

          $pairname = [];
          if ($market_data->hasField('field_base_symbol') && !$market_data->get('field_base_symbol')->isEmpty()) {
            $pairname[] = $market_data->get('field_base_symbol')->value;
          }

          if ($market_data->hasField('field_target_symbol') && !$market_data->get('field_target_symbol')->isEmpty()) {
            $pairname[] = $market_data->get('field_target_symbol')->value;
          }

          $pair_name = implode('', $pairname);
        }
        else {
          // IF no any markets added then automatically
          // fetch the USD records from the default exchange.
          $currency_data = $this->entityTypeManager->getStorage('node')->load($currency_nid);

          if ($currency_data->hasField('field_symbol') && !$currency_data->get('field_symbol')->isEmpty()) {
            $symbol = $currency_data->get('field_symbol')->value;
          }

          if ($symbol) {
            $pair_name = $symbol;
          }
        }

        $history_data = [];
        if (isset($pair_name)) {
          $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
          $selected_range = '-Select Range-';
          $param = $this->request->getCurrentRequest()->query->all();

          if (isset($param['start']) && isset($param['end'])) {
            $date1 = date_create()->setTimestamp($param['start'] / 1000);
            $date2 = date_create()->setTimestamp($param['end'] / 1000);
            $diff = date_diff($date1, $date2);
            $limit = $diff->format("%a");
            $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
            $toTs = floor($param['end'] / 1000);
            $apiURL = 'https://min-api.cryptocompare.com/data/histoday?fsym=' . $pairname[0] . '&tsym=USD&limit=' . $limit . '&toTs=' . $toTs . '&api_key=' . $key;
          }
          else {
            $apiURL = 'https://min-api.cryptocompare.com/data/histoday?fsym=' . $pairname[0] . '&tsym=USD&limit=30&api_key=' . $key;
          }

          try {
            $result = $this->httpClient->get($apiURL,
              [
                'timeout' => 600,
                'headers' => [
                  'Accept' => 'application/json',
                ],
              ]);
            if ($result->getStatusCode() == 200) {
              $response = (string) $result->getBody();
              if (empty($response)) {
                return FALSE;
              }

              /*
              Reference URL
              https://docs.bitfinex.com/v2/reference#rest-public-candles
              response with Section = "hist"
              [
              [ MTS, OPEN, CLOSE, HIGH, LOW, VOLUME ],
              ...
              ]
               */
              $response_datas = json_decode($response);

              $response_data_sorted = array_reverse($response_datas->Data);

              foreach ($response_data_sorted as $response_data) {
                $responsedata[0] = date('M j, Y', ($response_data->time)); /* Convert date format. */
                $responsedata[1] = round($response_data->open, 2); /* Round OPEN data. */
                $responsedata[2] = round($response_data->close, 2); /* Round CLOSE data. */
                $responsedata[3] = round($response_data->high, 2); /* Round HIGH data. */
                $responsedata[4] = round($response_data->low, 2); /* Round LOW data. */
                $responsedata[5] = round($response_data->volumeto, 2); /* Round VOLUME data. */
                $history_data[] = $responsedata;
              }

              $history_table = [
                '#theme' => 'table',
                '#header' => [
                  $this->t('MTS'),
                  $this->t('OPEN'),
                  $this->t('CLOSE'),
                  $this->t('HIGH'),
                  $this->t('LOW'),
                  $this->t('VOLUME'),
                ],
                '#rows' => @$history_data,
                '#empty' => $this->t('<h2>Sorry, no data available.</h2> Something wrong in fetching history data of this currency, Please reload the page.'),
                '#prefix' => '<div id="historyfilter_wrapper">
			      <div id="historyfilter">
			        <i class="fa fa-calendar"></i>&nbsp;
                				    <span>' . $selected_range . '</span>
				    <i class="fa fa-caret-down"></i>
				  </div>
                				</div>',
                '#attached' => ['library' => ['crypto_history/crypto_history']],
              ];

              $output = $history_table;
            }
          }
          catch (RequestException $e) {
            $output = $this->t('<h2>Sorry, no data available.</h2> Something wrong in fetching history data of this currency, Please reload the page.');
            $this->logger->get('history_data_request_exception')->error($e->getMessage());
          }
        }
      }
    }

    return [
      '#theme' => 'default',
      '#data' => $output,
      '#prefix' => '<div id="history_wrapper">',
      '#suffix' => '</div>',
    ];
  }

}
