<?php

namespace Drupal\crypto_exchanges\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Crypto Exchanges Controller Class.
 */
class CryptoExchangesController extends ControllerBase {
  /**
   * The current path service.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected $currentPathStack;

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
   * Class constructor.
   *
   * @param \Drupal\Core\Path\CurrentPathStack $current_path_stack
   *   The current path stack service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The http_client.
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   */
  public function __construct(CurrentPathStack $current_path_stack, ConfigFactoryInterface $config_factory, ClientInterface $http_client, Connection $database) {
    $this->currentPathStack = $current_path_stack;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('path.current'),
      $container->get('config.factory'),
      $container->get('http_client'),
      $container->get('database')
    );
  }

  /**
   * Return the title of the markets page.
   */
  public function title() {
    $title = $this->t('Exchange');

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[2]) && $path_args[1] == 'exchanges') {
      $title = $this->t('@name Exchange', ['@name' => @$path_args[2]]);
    }

    return $title;
  }

  /**
   * Return the markets lists of the currency.
   */
  public function content() {
    $output = ['#markup' => $this->t('No any USD Markets available.')];

    $current_path = $this->currentPathStack->getPath();
    $path_args = explode('/', $current_path);

    if (isset($path_args[2]) && $path_args[1] == 'exchanges') {
      $exchange_url_name = @$path_args[2];

      /* FETCH EXCHANGE GENERAL INFO */
      $exchange_info = [];
      try {
        $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
        $response = $this->httpClient->request(
          "GET",
          "https://min-api.cryptocompare.com/data/exchanges/general?api_key=$key",
          ['timeout' => 600, 'headers' => ['Accept' => 'application/json']]
        );

        $code = $response->getStatusCode();
        if ($code == 200) {
          $body = $response->getBody()->getContents();
          $response_data = json_decode($body);

          foreach ($response_data->Data as $data) {
            if ($data->Url == "/exchanges/$exchange_url_name/overview" && isset($data->AffiliateUrl)) {
              $exchange_info = $data;
              $exchange_link = Link::fromTextAndUrl(
                'Visit Exchange!',
                Url::fromURI(
                  $data->AffiliateUrl,
                  [
                    'absolute' => TRUE,
                    'attributes' => ['class' => ['btn', 'pull-right']],
                  ]
                )
              );
            }
          }
        }
      }
      catch (\Exception $e) {
        return FALSE;
      }

      if (!empty($exchange_info)) {
        $query = $this->database->select('node_field_data', 'nfd');
        $query->fields('nfd', ['nid', 'title']);
        $query->condition('nfd.type', 'markets');
        $query->condition('nfd.status', 1, '=');

        $query->leftjoin('node__field_base_symbol', 'nfbs', 'nfbs.entity_id = nfd.nid');
        $query->addField('nfbs', 'field_base_symbol_value', 'base_symbol');

        $query->leftjoin('node__field_target_symbol', 'nfts', 'nfts.entity_id = nfd.nid');
        $query->addField('nfts', 'field_target_symbol_value', 'target_symbol');

        $query->leftjoin('node__field_base_currency', 'nfbc', 'nfbc.entity_id = nfd.nid');
        $query->addField('nfbc', 'field_base_currency_target_id', 'target_id');

        $query->distinct();

        $results = $query->execute();

        $target_currency = $base_symbols = $target_symbols = [];
        if (!empty($results)) {
          foreach ($results as $result) {
            $base_symbols[$result->base_symbol] = $result->base_symbol;
            $target_symbols[$result->target_symbol] = $result->target_symbol;
            $target_currency[$result->base_symbol] = $result->target_id;
          }
        }

        /* FETCH EXCHNAGE SYMBOL INFO */
        $coin_details = [];
        try {
          $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
          $response = $this->httpClient->request(
            'GET',
            "https://min-api.cryptocompare.com/data/v2/all/exchanges?api_key=$key",
            ['timeout' => 600, 'headers' => ['Accept' => 'application/json']]
          );

          $code = $response->getStatusCode();
          if ($code == 200) {
            $body = $response->getBody()->getContents();
            $response_data = json_decode($body);

            $supported_base_symbols = $supported_target_symbols = [];
            foreach ($response_data->Data->{$exchange_info->InternalName}->pairs as $fsym => $pairs) {
              $supported_base_symbols[$fsym] = $fsym;
              foreach ($pairs as $pair) {
                $supported_target_symbols[$pair] = $pair;
              }
            }

            $matches_bases = array_intersect($base_symbols, $supported_base_symbols);
            $matches_targets = array_intersect($target_symbols, $supported_target_symbols);

            $count = 1;
            $rows = [];
            if (!empty($matches_bases) && !empty($matches_targets)) {

              /* FETCH COINS INFO */
              $coin_details = [];
              try {
                $key = $this->configFactory->get('crypto_api_key.settings')->get('api_key');
                $response = $this->httpClient->request(
                  "GET",
                  "https://min-api.cryptocompare.com/data/all/coinlist?api_key=$key",
                  [
                    'timeout' => 600,
                    'headers' => ['Accept' => 'application/json'],
                  ]
                );

                $code = $response->getStatusCode();
                if ($code == 200) {
                  $body = $response->getBody()->getContents();
                  $response_data = json_decode($body);
                  foreach ($response_data->Data as $data) {
                    $coin_details[$data->Symbol]['CoinName'] = $data->CoinName;
                  }
                }
              }
              catch (\Exception $e) {
                return FALSE;
              }

              /* FETCH PRICE DETAILS */
              try {
                $response = $this->httpClient->request(
                  "GET",
                  'https://min-api.cryptocompare.com/data/pricemultifull?fsyms=' . implode(',', $matches_bases) . '&tsyms=' . implode(',', $matches_targets),
                  [
                    'timeout' => 600,
                    'headers' => ['Accept' => 'application/json'],
                  ]
                );

                $code = $response->getStatusCode();
                if ($code == 200) {
                  $body = $response->getBody()->getContents();
                  $response_data = json_decode($body);

                  foreach ($matches_bases as $matches_base) {
                    foreach ($matches_targets as $matches_target) {
                      if (isset($response_data->DISPLAY->{$matches_base}->{$matches_target})) {
                        $data = $response_data->DISPLAY->{$matches_base}->{$matches_target};
                        $pair_data[$matches_base][$matches_target]['OPENDAY'] = $data->OPENDAY;
                        $pair_data[$matches_base][$matches_target]['PRICE'] = $data->PRICE;
                        $pair_data[$matches_base][$matches_target]['HIGHDAY'] = $data->HIGHDAY;
                        $pair_data[$matches_base][$matches_target]['LOWDAY'] = $data->LOWDAY;
                        $pair_data[$matches_base][$matches_target]['VOLUME24HOURTO'] = $data->VOLUME24HOURTO;
                      }
                    }
                  }
                }
              }
              catch (\Exception $e) {
                return FALSE;
              }

              foreach ($matches_bases as $matches_base) {
                foreach ($matches_targets as $matches_target) {
                  if (isset($pair_data) && isset($pair_data[$matches_base][$matches_target])) {

                    $link = '';
                    if (isset($target_currency[$matches_base])) {
                      $url = Url::fromRoute('entity.node.canonical', ['node' => $target_currency[$matches_base]], ['absolute' => TRUE]);
                      $link = Link::fromTextAndUrl($coin_details[$matches_base]['CoinName'], $url);
                    }
                    else {
                      $url = Url::fromURI('#', ['absolute' => TRUE]);
                      $link = Link::fromTextAndUrl($coin_details[$matches_base]['CoinName'], $url);
                    }

                    $row = [
                      $count,
                      $link,
                      $matches_base . '/' . $matches_target,
                      $pair_data[$matches_base][$matches_target]['OPENDAY'],
                      $pair_data[$matches_base][$matches_target]['PRICE'],
                      $pair_data[$matches_base][$matches_target]['HIGHDAY'],
                      $pair_data[$matches_base][$matches_target]['LOWDAY'],
                      $pair_data[$matches_base][$matches_target]['VOLUME24HOURTO'],
                    ];

                    $count++;
                    $rows[] = $row;
                  }
                }
              }

              $coins = [
                '#theme' => 'table',
                '#header' => [
                  '#',
                  'Coin',
                  'Pair',
                  'Today Open',
                  'Last Price',
                  'Today High',
                  'Today Low',
                  'Volume(24h)',
                ],
                '#rows' => $rows,
                '#attributes' => ['id' => 'coins_list_table'],
                '#prefix' => @$exchange_link,
              ];

              $output = render($coins);

            }
          }
        }
        catch (\Exception $e) {
          return FALSE;
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
