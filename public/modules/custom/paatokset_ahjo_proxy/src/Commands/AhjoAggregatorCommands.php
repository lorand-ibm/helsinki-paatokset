<?php

declare(strict_types = 1);

namespace Drupal\paatokset_ahjo_proxy\Commands;

use Drush\Commands\DrushCommands;
use Drupal\Core\File\FileSystemInterface;
use Drupal\paatokset_ahjo_proxy\AhjoProxy;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\node\NodeStorageInterface;
use Drupal\node\Entity\Node;

/**
 * Ahjo Aggregator drush commands.
 *
 * @package Drupal\paatokset_ahjo_proxy\Commands
 */
class AhjoAggregatorCommands extends DrushCommands {

  /**
   * Ahjo proxy service.
   *
   * @var \Drupal\paatokset_ahjo_proxy\AhjoProxy
   */
  protected $ahjoProxy;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Node storage service.
   *
   * @var \Drupal\node\NodeStorageInterface
   */
  protected NodeStorageInterface $nodeStorage;

  /**
   * Constructor for Ahjo Aggregator Commands.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   Logger service.
   * @param \Drupal\paatokset_ahjo_proxy\AhjoProxy $ahjo_proxy
   *   Ahjo Proxy service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   Entity type manager.
   */
  public function __construct(LoggerChannelFactoryInterface $logger_factory, AhjoProxy $ahjo_proxy, EntityTypeManagerInterface $entity_type_manager) {
    $this->ahjoProxy = $ahjo_proxy;
    $this->entityTypeManager = $entity_type_manager;
    $this->nodeStorage = $this->entityTypeManager->getStorage('node');
    $this->logger = $logger_factory->get('paatokset_ahjo_proxy');
  }

  /**
   * Aggregates data from Ahjo API.
   *
   * @param string $endpoint
   *   Endpoint to aggregate data from.
   * @param array $options
   *   Additional options for the command.
   *
   * @command ahjo-proxy:aggregate
   *
   * @option dataset
   *   Which dataset to get (latest, all, etc)
   * @option start
   *   Custom timestamp for fetching data.
   * @option end
   *   Custom timestamp for fetching data.
   * @option retry
   *   Filename to retry from.
   * @option append
   *   File to append to. Useful when retrying.
   * @option filename
   *   Filename to use instead of default. Can be used to split/batch results.
   *
   * @usage ahjo-proxy:aggregate meetings --dataset=latest
   *   Stores latest meetings into meetings_latest.json
   * @usage ahjo-proxy:aggregate meetings --dataset=all --retry=failed_meetings_all.json --append=meetings_all.json
   *   Retries failed aggregation based on stored file.
   *
   * @aliases ap:agg
   */
  public function aggregate(string $endpoint, array $options = [
    'dataset' => NULL,
    'start' => NULL,
    'end' => NULL,
    'retry' => NULL,
    'filename' => NULL,
    'append' => NULL,
  ]): void {

    $allowed_datasets = [
      'all',
      'latest',
    ];

    if (in_array($options['dataset'], $allowed_datasets)) {
      $dataset = $options['dataset'];
    }
    else {
      $dataset = 'latest';
    }

    $options = $this->setDefaultOptions($endpoint, $dataset, $options);

    $data = [];
    $list_key = $this->getListKey($endpoint);

    $this->logger->info('Fetching from ' . $endpoint . ' with query string: ' . $options['query_string']);
    if (!empty($options['retry'])) {
      $this->logger->info('Resuming from file: ' . $options['retry']);
      $data = $this->ahjoProxy->getStatic($options['retry']);
    }
    else {
      $data = $this->ahjoProxy->getData($endpoint, $options['query_string']);
    }

    if (!empty($options['append'])) {
      $append_results = $this->ahjoProxy->getStatic($options['append']);
      $this->logger->info('Combining with file: ' . $options['append']);
    }

    if (!empty($options['filename'])) {
      $filename = $options['filename'];
      $this->logger->info('Using filename: ' . $filename);
    }
    else {
      $filename = NULL;
    }

    if (empty($data[$list_key])) {
      $this->logger->info('Empty result.');
      return;
    }

    $this->logger->info('Processing ' . count($data[$list_key]) . ' results.');

    $operations = [];
    $count = 0;
    foreach ($data[$list_key] as $item) {
      $count++;
      $data = [
        'item' => $item,
        'list_key' => $list_key,
        'endpoint' => $endpoint,
        'dataset' => $dataset,
        'count' => $count,
        'append' => NULL,
        'filename' => $filename,
      ];

      if ($count === 1 && !empty($append_results[$list_key])) {
        $data['append'] = $append_results[$list_key];
      }

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processBatchItem',
        [$data],
      ];
    }

    batch_set([
      'title' => 'Aggregating: ' . $endpoint . ' with dataset:' . $dataset,
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishBatch',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Gets and stores a single request from Ahjo API.
   *
   * @param string $endpoint
   *   Endpoint to get data from.
   * @param array $options
   *   Additional options for the command.
   *
   * @command ahjo-proxy:get
   *
   * @option start
   *   Custom timestamp for fetching data.
   * @option end
   *   Custom timestamp for fetching data.
   * @option filename
   *   Filename to use instead of default. Can be used to split/batch results.
   *
   * @usage ahjo-proxy:get decisionmakers
   *   Stores all decisionmakers into decisionmakers.json
   * @usage ahjo-proxy:get decisionmakers --start=021100VH1 --filename=decisionmakers_021100VH1.json
   *   Stores decisionmakers under the 021100VH1 organisation.
   *
   * @aliases ap:get
   */
  public function get(string $endpoint, array $options = [
    'start' => NULL,
    'end' => NULL,
    'filename' => NULL,
  ]): void {
    $data = [];
    $list_key = $this->getListKey($endpoint);

    $query_string = '';
    if (!empty($options['start'])) {
      $query_string .= 'start=' . $options['start'];
    }

    $this->logger->info('Fetching from ' . $endpoint . ' with query string: ' . $query_string);

    $data = $this->ahjoProxy->getData($endpoint, $query_string);

    if (!empty($options['filename'])) {
      $filename = $options['filename'];
      $this->logger->info('Using filename: ' . $filename);
    }
    else {
      $filename = $endpoint . '.json';
    }

    if (empty($data[$list_key])) {
      $this->logger->info('Empty result.');
      return;
    }

    $this->logger->info('Received ' . count($data[$list_key]) . ' results.');
    file_save_data(json_encode($data), 'public://' . $filename, FileSystemInterface::EXISTS_REPLACE);
    $this->logger->info('Stores data into public://' . $filename);
  }

  /**
   * Aggregates position of trust data from Ahjo API.
   *
   * @command ahjo-proxy:get-positionsoftrust
   *
   * @usage ahjo-proxy:get-positionsoftrust
   *   Stores all positions of trust into a file.
   *
   * @aliases ap:pt
   */
  public function positionsoftrust(): void {
    $this->logger->info('Fetching decision making organizations...');
    $org_data = $this->ahjoProxy->getData('organization/decisionmakingorganizations', NULL);
    if (empty($org_data)) {
      $this->logger->info('Empty result.');
      $org_data = [
        'organizations' => [],
      ];
    }
    else {
      $this->logger->info('Processing ' . count($org_data['organizations']) . ' results.');
    }

    $this->logger->info('Fetching council groups...');
    $group_data = $this->ahjoProxy->getData('councilgroups', NULL);
    if (empty($group_data)) {
      $this->logger->info('Empty result.');
      $group_data = [
        'agentPublicList' => [],
      ];
    }
    else {
      $this->logger->info('Processing ' . count($group_data['agentPublicList']) . ' results.');
    }

    $operations = [];
    $count = 0;
    foreach ($org_data['organizations'] as $org) {
      $count++;
      $data = [
        'endpoint' => 'agents/positionoftrust',
        'count' => $count,
        'query_string' => 'org=' . $org['ID'],
      ];

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processGroupItem',
        [$data],
      ];
    }

    foreach ($group_data['agentPublicList'] as $group) {
      $count++;
      $data = [
        'endpoint' => 'agents/positionoftrust',
        'count' => $count,
        'filename' => 'positionsoftrust.json',
        'query_string' => 'org=' . $group['ID'],
      ];

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processGroupItem',
        [$data],
      ];
    }

    batch_set([
      'title' => 'Aggregating council groups and organizations.',
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishGroups',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Aggregates city council position of trust data from Ahjo API.
   *
   * @command ahjo-proxy:get-council-positionsoftrust
   *
   * @usage ahjo-proxy:get-council-positionsoftrust
   *   Stores all positions of trust into a file.
   *
   * @aliases ap:cpt
   */
  public function councilPositionsOfTrust(): void {
    $council_groups = [
      '02900',
      '00400',
    ];

    $count = 0;
    foreach ($council_groups as $id) {
      $count++;
      $data = [
        'endpoint' => 'agents/positionoftrust',
        'count' => $count,
        'filename' => 'positionsoftrust_council.json',
        'query_string' => 'org=' . $id,
      ];

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processGroupItem',
        [$data],
      ];
    }

    batch_set([
      'title' => 'Aggregating council groups and organizations.',
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishGroups',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Aggregates trustees from Ahjo API. Requires positions to be aggregated.
   *
   * @param string $filename
   *   Filename to get initial data from.
   *
   * @command ahjo-proxy:get-trustees
   *
   * @usage ahjo-proxy:get-trustees
   *   Stores all positions of trust into a file.
   *
   * @aliases ap:trust
   */
  public function trustees(string $filename = 'positionsoftrust.json'): void {
    $this->logger->info('Fetching trustees organizations...');
    $data = $this->ahjoProxy->getStatic($filename);
    $operations = [];
    $count = 0;
    $ids = [];
    $duplicates = 0;
    foreach ($data as $group) {
      foreach ($group['agentPublicList'] as $item) {
        if (isset($ids[$item['ID']])) {
          $duplicates++;
        }
        else {
          $ids[$item['ID']] = $item['ID'];
          $count++;
          $data = [
            'endpoint' => 'agents/positionoftrust/' . $item['ID'],
            'count' => $count,
          ];
          if ($filename === 'positionsoftrust_council.json') {
            $data['filename'] = 'trustees_council.json';
          }
          $operations[] = [
            '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processTrusteeItem',
            [$data],
          ];
        }
      }
    }

    $this->logger->info('Prosessing individual positions of trust, total of ' . $count . ' unique items and ' . $duplicates . ' duplicates.');

    batch_set([
      'title' => 'Aggregating positions of trust.',
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishTrustees',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Updates migrated decision nodes with record, case and meeting info.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command ahjo-proxy:update-decisions
   *
   * @option update
   *   Update all decisions, not just ones with missing records.
   * @option localdata
   *   Use only local and placeholder data, doesn't require VPN connection.
   * @option limit
   *   Limit processing to certain amount of nodes.
   *
   * @usage ahjo-proxy:update-decisions
   *   Fetches data for decisions where the record field is null.
   * @usage ahjo-proxy:update-decisions --update
   *   Fetches and updates data for all decisions.
   *
   * @aliases ap:ud
   */
  public function updateDecisions(array $options = [
    'update' => NULL,
    'localdata' => NULL,
    'limit' => NULL,
  ]): void {

    if (!empty($options['update'])) {
      $update_all = TRUE;
    }
    else {
      $update_all = FALSE;
    }

    if (!empty($options['localdata'])) {
      $use_local_data = TRUE;
    }
    else {
      $use_local_data = FALSE;
    }

    if (!empty($options['limit'])) {
      $limit = (int) $options['limit'];
    }
    else {
      $limit = 0;
    }

    if ($update_all) {
      $this->logger->info('Updating all nodes...');
    }
    else {
      $this->logger->info('Only updating nodes with missing records...');
    }

    if ($use_local_data) {
      $this->logger->info('Using local data...');
    }
    else {
      $this->logger->info('Fetching data from API...');
    }

    $this->logger->info('Limiting nodes to: ' . $limit);

    $query = $this->nodeStorage->getQuery()
      ->condition('type', 'decision')
      ->condition('status', 1)
      ->latestRevision();

    if (!$update_all) {
      $query->notExists('field_decision_record');
    }

    if ($limit) {
      $query->range('0', $limit);
    }

    $ids = $query->execute();
    $this->logger->info('Total nodes: ' . count($ids));

    $nodes = Node::loadMultiple($ids);

    $operations = [];
    $count = 0;
    foreach ($nodes as $node) {
      if (!$node->hasField('field_decision_native_id') || $node->get('field_decision_native_id')->isEmpty()) {
        continue;
      }

      $meeting_id = NULL;
      if ($node->hasField('field_meeting_id')) {
        $meeting_id = $node->field_meeting_id->value;
      }

      $case_id = NULL;
      if ($node->hasField('field_diary_number')) {
        $case_id = $node->field_diary_number->value;
      }

      $endpoint = NULL;
      if (!$use_local_data) {
        $endpoint = 'records/' . $node->field_decision_native_id->value;
      }

      $count++;
      $data = [
        'nid' => $node->id(),
        'native_id' => $node->field_decision_native_id->value,
        'count' => $count,
        'case_id' => $case_id,
        'meeting_id' => $meeting_id,
        'endpoint' => $endpoint,
      ];

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::processDecisionItem',
        [$data],
      ];
    }

    batch_set([
      'title' => 'Aggregating data for decisions.',
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishDecisions',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Parses decision content and motion fields from raw HTML.
   *
   * @param array $options
   *   Additional options for the command.
   *
   * @command ahjo-proxy:parse-decision-content
   *
   * @option limit
   *   Limit processing to certain amount of nodes.
   *
   * @usage ahjo-proxy:parse-decision-content --limit=50
   *   Parses fields for the first 50 decisions where content is empty.
   *
   * @aliases ap:dc
   */
  public function parseDecisionContents(array $options = [
    'limit' => NULL,
  ]): void {
    if (!empty($options['limit'])) {
      $limit = (int) $options['limit'];
    }
    else {
      $limit = 0;
    }

    $this->logger->info('Limiting nodes to: ' . $limit);

    $query = $this->nodeStorage->getQuery()
      ->condition('type', 'decision')
      ->condition('status', 1)
      ->notExists('field_decision_content_parsed')
      ->condition('field_decision_content', '', '<>')
      ->latestRevision();

    if ($limit) {
      $query->range('0', $limit);
    }

    $ids = $query->execute();
    $this->logger->info('Total nodes: ' . count($ids));

    $operations = [];
    $count = 0;
    foreach ($ids as $nid) {
      $count++;
      $data = [
        'nid' => $nid,
        'count' => $count,
      ];

      $operations[] = [
        '\Drupal\paatokset_ahjo_proxy\AhjoProxy::parseDecisionItem',
        [$data],
      ];
    }

    batch_set([
      'title' => 'Parsing data for decisions.',
      'operations' => $operations,
      'finished' => '\Drupal\paatokset_ahjo_proxy\AhjoProxy::finishDecisions',
    ]);

    drush_backend_batch_process();
  }

  /**
   * Store static files into filesystem.
   *
   * @command ahjo-proxy:store-static-files
   *
   * @usage ahjo-proxy:store-static-files
   *   Stores default static files into filesystem (for debugging migrations).
   *
   * @aliases ap:fs
   */
  public function storeStaticFiles() {
    $static_files = [
      'cases_all.json',
      'cases_latest.json',
      'meetings_all.json',
      'meetings_latest.json',
      'decisions_all.json',
      'decisions_latest.json',
      'initiatives_all.json',
      'initiatives_latest.json',
      'resolutions_all.json',
      'resolutions_latest.json',
      'decisionmakers.json',
      'positionsoftrust.json',
      'positionsoftrust_council.json',
      'trustees.json',
      'trustees_council.json',
    ];

    foreach ($static_files as $file) {
      $file_path = \Drupal::service('extension.list.module')->getPath('paatokset_ahjo_proxy') . '/static/' . $file;
      $file_contents = file_get_contents($file_path);
      if (!empty($file_contents)) {
        file_save_data($file_contents, 'public://' . $file, FileSystemInterface::EXISTS_REPLACE);
        $this->logger->info('Saved file into public://' . $file);
      }
      else {
        $this->logger->info('Could not load ' . $file);
      }
    }
  }

  /**
   * Set default options.
   *
   * @param string $endpoint
   *   Endpoint to set options for.
   * @param string $dataset
   *   Dataset to set options for.
   * @param array $options
   *   Options from command line parameters.
   *
   * @return array
   *   Set options.
   */
  private function setDefaultOptions(string $endpoint, string $dataset, array $options): array {
    switch ($endpoint) {
      case 'meetings':
        $timestamp_key = 'start';
        $timestamp_key_end = 'end';
        break;

      default:
        $timestamp_key = 'handledsince';
        $timestamp_key_end = 'handledbefore';
        break;
    }

    if (empty($options['start'])) {
      if ($dataset === 'all') {
        $query_string = $timestamp_key . '=2001-10-01T12:34:45Z';
      }
      else {
        $week_ago = strtotime("-1 week");
        $timestamp = date('Y-m-d\TH:i:s\Z', $week_ago);
        $query_string = $timestamp_key . '=' . $timestamp;
      }
    }
    else {
      $query_string = $timestamp_key . '=' . $options['start'];
    }

    if (!empty($options['end'])) {
      $query_string .= '&' . $timestamp_key_end . '=' . $options['end'];
    }

    if ($endpoint === 'cases' || $endpoint === 'decisions') {
      $query_string .= '&size=1000&count_limit=1000';
    }

    $options['query_string'] = $query_string;

    return $options;
  }

  /**
   * Get list key for data array.
   *
   * @param string $endpoint
   *   Endpoint to get key for.
   *
   * @return string|null
   *   List key.
   */
  private function getListKey(string $endpoint): ?string {

    switch ($endpoint) {
      case 'cases':
        $key = 'cases';
        break;

      case 'decisions':
        $key = 'decisions';
        break;

      case 'meetings':
        $key = 'meetings';
        break;

      case 'decisionmakers':
        $key = 'decisionMakers';
        break;

      case 'resolutions':
        $key = 'resolutions';
        break;

      case 'initiatives':
        $key = 'initiatives';
        break;

      default:
        $key = $endpoint;
        break;
    }

    return $key;
  }

}
