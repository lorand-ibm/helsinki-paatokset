<?php

namespace Drupal\paatokset_ahjo_api\Service;

use Drupal\media\MediaInterface;
use Drupal\media\Entity\Media;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Component\Utility\Html;

/**
 * Service class for retrieving case and decision-related data.
 *
 * @package Drupal\paatokset_ahjo_api\Services
 */
class CaseService {
  /**
   * Machine name for case node type.
   */
  const CASE_NODE_TYPE = 'case';

  /**
   * Machine name for decision node type.
   */
  const DECISION_NODE_TYPE = 'decision';

  /**
   * Machine name for meeting document media type.
   */
  const DOCUMENT_TYPE = 'ahjo_document';

  /**
   * Case node.
   *
   * @var \Drupal\node\Entity\Node
   */
  private $case;

  /**
   * Decision node.
   *
   * @var \Drupal\node\Entity\Node
   */
  private $selectedDecision;

  /**
   * Case diary number.
   *
   * @var string
   */
  private $case_id;


  public function setEntitiesByPath(): void {
    $entityTypeIndicator = \Drupal::routeMatch()->getParameters()->keys()[0];
    $case = \Drupal::routeMatch()->getParameter($entityTypeIndicator);
    if ($case instanceof NodeInterface && $case->bundle() === self::CASE_NODE_TYPE) {
      $this->case = $case;
    }

    $this->case_id = $case->get('field_diary_number')->value;

    $decision_id = \Drupal::request()->query->get('decision');
    if (!$decision_id) {
      $this->selectedDecision = $this->getDefaultDecision($this->case_id);
    }
    else {
      $this->selectedDecision = $this->getDecision($decision_id);
    }
  }

  public function setEntitiesById(string $case_id, string $decision_id): void {
    $case_nodes = $this->caseQuery([
      'case_id' => $case_id,
      'limit' => 1,
    ]);
    $this->case = array_shift($case_nodes);
    $this->case_id = $case_id;

    $decision_nodes = $this->decisionQuery([
      'case_id' => $case_id,
      'decision_id' => $decision_id,
      'limit' => 1,
    ]);
    $this->selectedDecision = array_shift($decision_nodes);
  }

  private function getDefaultDecision(string $case_id): ?NodeInterface {
    $nodes = $this->decisionQuery([
      'case_id' => $case_id,
      'limit' => 1,
    ]);
    return array_shift($nodes);
  }

  public function getSelectedDecision(): ?NodeInterface {
    return $this->selectedDecision;
  }

  public function getDecision(string $decision_id): ?NodeInterface {
    $decision_nodes = $this->decisionQuery([
      'decision_id' => $decision_id,
      'limit' => 1,
    ]);
    return array_shift($decision_nodes);
  }

  public function getDecisionPdf(string $decision_id = NULL): ?string {
    if (!$this->case instanceof NodeInterface || !$this->case->hasField('field_case_records') || $this->case->get('field_case_records')->isEmpty()) {
      return NULL;
    }

    if (!$decision_id) {
      $decision_id = $this->selectedDecision->field_decision_native_id->value;
    }

    $pdf_url = NULL;
    foreach ($this->case->get('field_case_records') as $field) {
      $data = json_decode($field->value, TRUE);
      if ($data['NativeId'] === $decision_id) {
        $pdf_url = $data['FileURI'];
        break;
      }
    }

    return $pdf_url;
  }

  public function getDecisionLabel(string $decision_id = NULL): ?string {
    if (!$decision_id) {
      $decision = $this->selectedDecision;
    }
    else {
      $decision = $this->getDecision($decision_id);
    }

    if (!$decision instanceof NodeInterface) {
      return NULL;
    }

    if (!$decision->hasField('field_dm_org_name') || !$decision->hasField('field_meeting_date')) {
      return $decision->title->value;
    }

    $decision_date = strtotime($decision->field_meeting_date->value);
    return $decision->field_dm_org_name->value . ' ' . date('d.m.Y', $decision_date);
  }

  public function getDecisionClass(string $decision_id = NULL): string {
    if (!$decision_id) {
      $decision = $this->selectedDecision;
    }
    else {
      $decision = $this->getDecision($decision_id);
    }

    if (!$decision instanceof NodeInterface || !$decision->hasField('field_organization_type') || $decision->get('field_organization_type')->isEmpty()) {
      return 'council';
    }

    return Html::cleanCssIdentifier(strtolower($decision->field_organization_type->value));
  }

  public function getAttachments(): array {
    if (!$this->selectedDecision instanceof NodeInterface || !$this->selectedDecision->hasField('field_decision_attachments')) {
      return [];
    }

    $attachments = [];
    foreach ($this->selectedDecision->get('field_decision_attachments') as $field) {

      $data = json_decode($field->value, TRUE);
      $attachments[] = [
        'file_url' => $data['FileURI'],
        'title' => $data['Title'],
      ];
    }

    return $attachments;
  }

  public function getAllDecisions(string $case_id = NULL): array {
    if (!$case_id) {
      $case_id = $this->case_id;
    }
    return $this->decisionQuery(['case_id' => $case_id]);
  }

  public function getDecisionsList(string $case_id = NULL): array {
    if (!$case_id) {
      $case_id = $this->case_id;
    }
    $decisions = $this->getAllDecisions($case_id);

    $results = [];
    foreach ($decisions as $node) {
      $decision_date = strtotime($node->field_meeting_date->value);
      if ($node->field_dm_org_name->value) {
        $label = $node->field_dm_org_name->value . ' ' . date('d.m.Y', $decision_date);
      }
      else {
        $label = $node->title->value;
      }

      if ($node->field_organization_type->value) {
        $class = Html::cleanCssIdentifier(strtolower($node->field_organization_type->value));
      }
      else {
        $class = 'council';
      }

      $results[] = [
        'id' => $node->id(),
        'native_id' => $node->field_decision_native_id->value,
        'title' => $node->title->value,
        'organization' => $node->field_dm_org_name->value,
        'organization_type' => $node->field_organization_type->value,
        'label' => $label,
        'class' => $class,
      ];
    }

    return $results;
  }

  public function getNextDecision(string $case_id = NULL, string $decision_nid = NULL): ?array {
    return $this->getAdjacentDecision(-1, $case_id, $decision_nid);
  }

  public function getPrevDecision(string $case_id = NULL, string $decision_nid = NULL): ?array {
    return $this->getAdjacentDecision(1, $case_id, $decision_nid);
  }

  private function getAdjacentDecision(int $offset, string $case_id = NULL, string $decision_nid = NULL): ?array {
    if (!$case_id) {
      $case_id = $this->case_id;
    }

    if (!$decision_nid) {
      $decision_nid = $this->selectedDecision->id();
    }

    $all_decisions = $this->getAllDecisions($case_id);
    $all_nids = array_keys($all_decisions);
    $next_nid = NULL;
    foreach ($all_nids as $key => $id) {
      if ((string) $id !== $decision_nid) {
        continue;
      }

      if (isset($all_nids[$key + $offset])) {
        $next_nid = (string) $all_nids[$key + $offset];
      }
      break;
    }

    if (!isset($all_decisions[$next_nid])) {
      return [];
    }

    $node = $all_decisions[$next_nid];

    return [
      'title' => $node->title->value,
      'id' => urlencode($node->field_decision_native_id->value),
    ];
  }

  public function caseQuery(array $params): array {
    $params['type'] = self::CASE_NODE_TYPE;
    return $this->query($params);
  }

  public function decisionQuery(array $params): array {
    $params['type'] = self::DECISION_NODE_TYPE;
    return $this->query($params);
  }

  private function query(array $params): array {
    if (isset($params['sort'])) {
      $sort = $params['sort'];
    }
    else {
      $sort = 'DESC';
    }

    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', $params['type'])
      ->sort('field_meeting_date', $sort);

    if (isset($params['limit'])) {
      $query->range('0', $params['limit']);
    }

    if (isset($params['case_id'])) {
      $query->condition('field_diary_number', $params['case_id']);
    }

    if (isset($params['decision_id'])) {
      $query->condition('field_decision_native_id', $params['decision_id']);
    }

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    return Node::loadMultiple($ids);
  }

}