<?php

namespace Drupal\paatokset_ahjo\Service;

use Drupal\Core\Url;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\paatokset_ahjo\Enum\PolicymakerRoutes;

/**
 * Service class for policymaker-related data.
 *
 * @package Drupal\paatokset_ahjo\Services
 */
class PolicymakerService {
  /**
   * Policymaker node.
   *
   * @var \Drupal\node\Entity\Node
   */
  private $policymaker;

  /**
   * Class constructor.
   */
  public function __construct($id = NULL) {
    $node = NULL;

    if ($id) {
      $node = Node::load($id);
    }
    else {
      $node = \Drupal::routeMatch()->getParameter('node');
      $routeName = \Drupal::routeMatch()->getRouteName();
      if (!$node) {
        $current_path = \Drupal::service('path.current')->getPath();
        $path_parts = explode('/', $current_path);
        array_pop($path_parts);
        $path_alias = implode('/', $path_parts);
        $path = \Drupal::service('path_alias.manager')->getPathByAlias($path_alias);
        if (preg_match('/node\/(\d+)/', $path, $matches)) {
          $node = Node::load($matches[1]);
        }
      }
    }

    $this->policymaker = $node;
  }

  /**
   * Transform org_type value to css class.
   *
   * @param string $org_type
   *   Org type value to transform.
   *
   * @return string
   *   Transformed css class
   */
  public static function transformType($org_type) {
    return str_replace('_', '-', $org_type);
  }

  /**
   * Check if route exists.
   */
  public static function routeExists($routeName) {
    $routeProvider = \Drupal::service('router.route_provider');

    // getRouteByName() throws an exception if route not found.
    try {
      $routeProvider->getRouteByName($routeName);
    }
    catch (\Throwable $throwable) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Return the current policymaker node.
   *
   * @return \Drupal\node\Entity\Node|null
   *   - Returns routematched Node or null
   */
  public function getPolicymaker() {
    return $this->policymaker;
  }

  /**
   * Transform organization type to CSS class.
   */
  public function getOrgTypeClass() {
    return $this->transformType($this->policymaker->get('field_organization_type')->value);
  }

  /**
   * Return route for policymaker documents.
   */
  public function getDocumentsRoute() {
    if ($this->policymaker->get('field_organization_type')->value === 'trustee') {
      return NULL;
    }

    $routes = PolicymakerRoutes::getOrganizationRoutes();
    $baseRoute = $routes['Documents'];
    $currentLanguage = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $localizedRoute = "$baseRoute.$currentLanguage";

    if ($this->routeExists($localizedRoute)) {
      return Url::fromRoute($localizedRoute, ['organization' => strtolower($this->policymaker->get('title')->value)]);
    }

    return NULL;
  }

  /**
   * Get all the decisions for one policymaker id.
   *
   * @return array
   *   of results.
   */
  public function getAgendasYears(): array {
    $database = \Drupal::database();
    $query = $database->select('paatokset_agenda_item_field_data', 'aifd')
      ->condition('meeting_policymaker_link', $this->policymaker->get('field_resource_uri')->value);
    $query->fields('aifd', ['meeting_policymaker_link']);
    $query->addExpression('YEAR(meeting_date)', 'date');
    $query->groupBy('date');
    $query->orderBy('date', 'DESC');
    $queryResult = $query->distinct()->execute()->fetchAll();
    $result = [];
    foreach ($queryResult as $row) {
      $result[$row->date][] = [
        '#type' => 'link',
        '#title' => $row->date,
      ];
    }

    return $result;
  }

  /**
   * Get all the decisions for one classification code.
   *
   * @return array
   *   of results.
   */
  public function getAgendasList($byYear = TRUE, $limit = NULL): array {
    $database = \Drupal::database();
    $query = $database->select('paatokset_agenda_item_field_data', 'aifd')
      ->fields('aifd', [
        'subject',
        'meeting_date',
        'meeting_number',
        'id',
        'issue_id',
      ]);
    $query->addExpression('YEAR(meeting_date)', 'year');
    $query->condition('meeting_policymaker_link', $this->policymaker->get('field_resource_uri')->value, '=');
    $query->orderBy('meeting_date', 'DESC');

    if ($limit) {
      $query->range(0, $limit);
    }

    $queryResult = $query->execute()->fetchAll();

    if (!$byYear) {
      return $queryResult;
    }

    $result = [];
    foreach ($queryResult as $row) {
      $result[$row->year][] = [
        '#type' => 'link',
        '#date' => date("d.m.Y", strtotime($row->meeting_date)),
        '#timestamp' => strtotime($row->meeting_date),
        '#meetingNumber' => $row->meeting_number,
        '#responsiveDate' => date("m-Y", strtotime($row->meeting_date)),
        '#responsiveTitle' => 'Pöytäkirja',
        '#year' => $row->year,
        '#title' => $row->subject,
        '#url' => Url::fromUri('internal:' . \Drupal::request()->getRequestUri()),
      ];
    }
    return $result;
  }

  /**
   * Get all meeting document-related data.
   *
   * @return array
   *   Array containing queried data
   */
  public function getMinutesOfDiscussion($limit = NULL) : array {
    $database = \Drupal::database();
    $query = $database->select('paatokset_meeting_field_data', 'pmfd')
      ->fields('pmfd', ['id', 'meeting_date']);
    $query->orderBy('meeting_date', 'DESC');
    $query->condition('policymaker_uri', $this->policymaker->get('field_resource_uri')->value, '=');

    if ($limit) {
      $query->range(0, $limit);
    }

    $result = $query->execute()->fetchAllKeyed();
    $mediaEntities = $this->getMeetingMediaEntities(array_keys($result));
    $list = [];
    $years = [];
    foreach ($mediaEntities as $id => $meeting) {
      foreach ($meeting as $entity) {
        $file_id = $entity->get('field_document')->target_id;
        if ($entity->get('field_document')->target_id) {
          $download_link = Url::fromUri(file_create_url(File::load($file_id)->getFileUri()));
        }
        $year = date('Y', strtotime($result[$id]));
        $title = t('Valtuuston kokous') . ' ' . date("d.m.Y", strtotime($result[$id]));
        $list[$year][] = [
          '#type' => 'link',
          '#responsiveDate' => date("m-Y", strtotime($result[$id])),
          '#responsiveTitle' => $title,
          '#date' => date("d.m.Y", strtotime($result[$id])),
          '#timestamp' => strtotime($result[$id]),
          '#year' => $year,
          '#title' => $title,
          '#url' => '',
          '#download_link' => $download_link ?? NULL,
          '#download_label' => str_replace(' ', '_', $title),
        ];

        if (!isset($years[$year])) {
          $years[$year][] = [
            '#type' => 'link',
            '#title' => $year,
          ];
        }
      }
    }

    return [
      'years' => $years,
      'list' => $list,
    ];
  }

  /**
   * Get all policymaker documents.
   *
   * @return array
   *   Array of resulting documents
   */
  public function getDocumentData() : array {
    $documents = $this->getMinutesOfDiscussion();
    $declarationsOfAffiliation = $this->getDeclarationsOfAffilition();

    foreach ($declarationsOfAffiliation as $declaration) {
      $date = $declaration->get('created')->value;
      $title = $declaration->name->value;
      $year = date('Y', $date);
      if (!isset($documents['years'])) {
        $documents['years'][$years] = [
          '#type' => 'link',
          '#title' => $year,
        ];
      }

      $file_id = $declaration->get('field_document')->target_id;
      if ($declaration->get('field_document')->target_id) {
        $download_link = Url::fromUri(file_create_url(File::load($file_id)->getFileUri()));
      }

      $documents['list'][$year][] = [
        '#type' => 'link',
        '#responsiveDate' => date("m-Y", $date),
        '#responsiveTitle' => $title,
        '#date' => date("d.m.Y", $date),
        '#timestamp' => $date,
        '#year' => $year,
        '#title' => $title,
        '#url' => '',
        '#download_link' => $download_link ?? NULL,
        '#download_label' => str_replace(' ', '_', $title),
      ];
    }

    return $documents;
  }

  /**
   * Get policymaker-related declarations of affiliation.
   *
   * @return array
   *   Array of resulting documents
   */
  private function getDeclarationsOfAffilition() {
    $ids = \Drupal::entityQuery('media')
      ->condition('bundle', 'declaration_of_affiliation')
      ->condition('field__policymaker_reference', $this->policymaker->id())
      ->execute();

    return Media::loadMultiple($ids);
  }

  /**
   * Get meeting-related documents.
   *
   * @return array
   *   Array of resulting documents
   */
  private function getMeetingMediaEntities($meetingids) {
    if (count($meetingids) === 0) {
      return [];
    }

    $ids = \Drupal::entityQuery('media')
      ->condition('bundle', 'minutes_of_the_discussion')
      ->condition('field_meetings_reference', $meetingids, 'IN')
      ->execute();
    $entities = Media::loadMultiple($ids);

    $result = [];
    foreach ($entities as $entity) {
      $meeting_id = $entity->get('field_meetings_reference')->target_id;
      $result[$meeting_id][] = $entity;
    }

    return $result;
  }

}