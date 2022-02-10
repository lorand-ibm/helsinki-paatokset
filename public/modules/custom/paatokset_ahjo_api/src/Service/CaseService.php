<?php

namespace Drupal\paatokset_ahjo_api\Service;

use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;

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
  private $caseId;

  /**
   * Set case and decision entities based on path. Only works on case paths!
   */
  public function setEntitiesByPath(): void {
    $entityTypeIndicator = \Drupal::routeMatch()->getParameters()->keys()[0];
    $case = \Drupal::routeMatch()->getParameter($entityTypeIndicator);
    if ($case instanceof NodeInterface && $case->bundle() === self::CASE_NODE_TYPE) {
      $this->case = $case;
    }

    $this->caseId = $case->get('field_diary_number')->value;

    $decision_id = \Drupal::request()->query->get('decision');
    if (!$decision_id) {
      $this->selectedDecision = $this->getDefaultDecision($this->caseId);
    }
    else {
      $this->selectedDecision = $this->getDecision($decision_id);
    }
  }

  /**
   * Set case and decision entities based on IDs.
   *
   * @param string|null $case_id
   *   Case diary number or NULL if decision doesn't have a case.
   * @param string $decision_id
   *   Decision native ID.
   */
  public function setEntitiesById(?string $case_id = NULL, string $decision_id): void {
    if ($case_id !== NULL) {
      $case_nodes = $this->caseQuery([
        'case_id' => $case_id,
        'limit' => 1,
      ]);
      $this->case = array_shift($case_nodes);
      $this->caseId = $case_id;
    }

    $decision_nodes = $this->decisionQuery([
      'case_id' => $case_id,
      'decision_id' => $decision_id,
      'limit' => 1,
    ]);
    $this->selectedDecision = array_shift($decision_nodes);
  }

  /**
   * Get default decision for case.
   *
   * @param string $case_id
   *   Case diary number.
   *
   * @return Drupal\node\NodeInterface|null
   *   Default (latest) decision entity, if found.
   */
  private function getDefaultDecision(string $case_id): ?NodeInterface {
    $nodes = $this->decisionQuery([
      'case_id' => $case_id,
      'limit' => 1,
    ]);
    return array_shift($nodes);
  }

  /**
   * Get active decision, if set.
   *
   * @return Drupal\node\NodeInterface|null
   *   Active decision entity.
   */
  public function getSelectedDecision(): ?NodeInterface {
    return $this->selectedDecision;
  }

  /**
   * Get decision based on Native ID.
   *
   * @param string $decision_id
   *   Decision's native ID.
   *
   * @return Drupal\node\NodeInterface|null
   *   Decision entity, if found.
   */
  public function getDecision(string $decision_id): ?NodeInterface {
    $decision_nodes = $this->decisionQuery([
      'decision_id' => $decision_id,
      'limit' => 1,
    ]);
    return array_shift($decision_nodes);
  }

  /**
   * Get page main heading either from case or decision node.
   *
   * @return string|null
   *   Main heading or NULL if neither case or decision have been set.
   */
  public function getDecisionHeading(): ?string {
    if ($this->case instanceof NodeInterface && $this->case->hasField('field_full_title') && !$this->case->get('field_full_title')->isEmpty()) {
      return $this->case->get('field_full_title')->value;
    }

    if (!$this->selectedDecision instanceof NodeInterface) {
      return NULL;
    }

    if ($this->selectedDecision->hasField('field_decision_case_title') && !$this->selectedDecision->get('field_decision_case_title')->isEmpty()) {
      return $this->selectedDecision->get('field_decision_case_title')->value;
    }

    if ($this->selectedDecision->hasField('field_full_title') && !$this->selectedDecision->get('field_full_title')->isEmpty()) {
      return $this->selectedDecision->get('field_full_title')->value;
    }

    return $this->selectedDecision->title->value;
  }

  /**
   * Get active decision's PDF file.
   *
   * @return string|null
   *   URL for PDF.
   */
  public function getDecisionPdf(): ?string {
    if (!$this->selectedDecision instanceof NodeInterface || !$this->selectedDecision->hasField('field_decision_record') || $this->selectedDecision->get('field_decision_record')->isEmpty()) {
      return NULL;
    }

    $data = json_decode($this->selectedDecision->get('field_decision_record')->value, TRUE);
    if (!empty($data) && isset($data['FileURI'])) {
      return $data['FileURI'];
    }
    return NULL;
  }

  /**
   * Get meeting URL for selected decision.
   *
   * @return \Drupal\Core\Url|null
   *   Meeting URL, if found.
   */
  public function getDecisionMeetingLink(): ?Url {
    if (!$this->selectedDecision instanceof NodeInterface) {
      return NULL;
    }

    // Check if decision has meeting ID.
    if (!$this->selectedDecision->hasField('field_meeting_id') || $this->selectedDecision->get('field_meeting_id')->isEmpty()) {
      return NULL;
    }
    $meeting_id = $this->selectedDecision->get('field_meeting_id')->value;

    // Check if decision has policymaker ID.
    if (!$this->selectedDecision->hasField('field_policymaker_id') || $this->selectedDecision->get('field_policymaker_id')->isEmpty()) {
      return NULL;
    }
    $policymaker_id = $this->selectedDecision->get('field_policymaker_id')->value;

    /** @var \Drupal\paatokset_policymakers\Service\PolicymakerService $policymakerService */
    $policymakerService = \Drupal::service('paatokset_policymakers');

    return $policymakerService->getMinutesRoute($meeting_id, $policymaker_id);
  }

  /**
   * Get decision URL by title and section.
   *
   * @param string $title
   *   Decision title.
   * @param string $section
   *   Decision section, to differentiate between multiple same titles.
   * @param string $meeting_id
   *   Meeting ID, to differentiate between multiple same titles.
   *
   * @return Drupal\Core\Url|null
   *   Decision URL, if found.
   */
  public function getDecisionUrlByTitle(string $title, string $section, string $meeting_id): ?Url {
    $params = [
      'title' => $title,
      'section' => $section,
      'meeting_id' => $meeting_id,
      'limit' => 1,
    ];

    $nodes = $this->decisionQuery($params);
    if (empty($nodes)) {
      return NULL;
    }

    $node = array_shift($nodes);
    if ($node instanceof NodeInterface) {
      return $this->getDecisionUrlFromNode($node);
    }

    return NULL;
  }

  /**
   * Get decision URL by ID.
   *
   * @param Drupal\node\NodeInterface $decision
   *   Decision node.
   *
   * @return Drupal\Core\Url
   *   URL for case node with decision ID as parameter, or decision URL.
   */
  public function getDecisionUrlFromNode(NodeInterface $decision): Url {
    if (!$decision->hasField('field_decision_native_id') || $decision->get('field_decision_native_id')->isEmpty()) {
      return $decision->toUrl();
    }

    $decision_id = $decision->get('field_decision_native_id')->value;

    $case = NULL;
    if ($decision->hasField('field_diary_number') && !$decision->get('field_diary_number')->isEmpty()) {
      $case = $this->caseQuery([
        'case_id' => $decision->get('field_diary_number')->value,
        'limit' => 1,
      ]);
      $case = array_shift($case);
    }

    if ($case instanceof NodeInterface) {
      $case_url = $case->toUrl();
      $case_url->setOption('query', ['decision' => $decision_id]);
      return $case_url;
    }

    return $decision->toUrl();
  }

  /**
   * Get label for decision (organization name + date).
   *
   * @param string|null $decision_id
   *   Decision ID. Leave NULL to use active decision.
   *
   * @return string|null
   *   Decision label.
   */
  public function getDecisionLabel(?string $decision_id = NULL): ?string {
    if (!$decision_id) {
      $decision = $this->selectedDecision;
    }
    else {
      $decision = $this->getDecision($decision_id);
    }

    if (!$decision instanceof NodeInterface) {
      return NULL;
    }

    return $this->formatDecisionLabel($decision);
  }

  /**
   * Format decision label.
   *
   * @param Drupal\node\NodeInterface $node
   *   Decision node.
   *
   * @return string
   *   Formatted label.
   */
  private function formatDecisionLabel(NodeInterface $node): string {
    $meeting_number = $node->field_meeting_sequence_number->value;
    $decision_timestamp = strtotime($node->field_decision_date->value);
    $decision_date = date('d.m.Y', $decision_timestamp);

    if ($meeting_number) {
      $decision_date = $meeting_number . '/' . $decision_date;
    }

    if ($node->field_dm_org_name->value) {
      $label = $node->field_dm_org_name->value . ' ' . $decision_date;
    }
    else {
      $label = $node->title->value;
    }

    return $label;
  }

  /**
   * Get CSS class based on decision organization type.
   *
   * @param string|null $decision_id
   *   Decision ID. Leave NULL to use active decision.
   *
   * @return string
   *   CSS class based on org type.
   */
  public function getDecisionClass(?string $decision_id = NULL): string {
    if (!$decision_id) {
      $decision = $this->selectedDecision;
    }
    else {
      $decision = $this->getDecision($decision_id);
    }

    if (!$decision instanceof NodeInterface || !$decision->hasField('field_policymaker_id') || $decision->get('field_policymaker_id')->isEmpty()) {
      return 'color-sumu';
    }

    /** @var \Drupal\paatokset_policymakers\Service\PolicymakerService $policymakerService */
    $policymakerService = \Drupal::service('paatokset_policymakers');

    $class = $policymakerService->getPolicymakerClassById($decision->field_policymaker_id->value);

    return Html::cleanCssIdentifier($class);
  }

  /**
   * Get attachments for active decision.
   *
   * @return array
   *   Array of links.
   */
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

  /**
   * Get all decisions for case.
   *
   * @param string|null $case_id
   *   Case ID. Leave NULL to use active case.
   *
   * @return array
   *   Array of decision nodes.
   */
  public function getAllDecisions(?string $case_id = NULL): array {
    if (!$case_id) {
      $case_id = $this->caseId;
    }

    if ($case_id === NULL) {
      return [];
    }

    return $this->decisionQuery([
      'case_id' => $case_id
    ]);
  }

  /**
   * Get decisions list for dropdown.
   *
   * @param string|null $case_id
   *   Case ID. Leave NULL to use active case.
   *
   * @return array
   *   Dropdown contents.
   */
  public function getDecisionsList(?string $case_id = NULL): array {
    /** @var \Drupal\paatokset_policymakers\Service\PolicymakerService $policymakerService */
    $policymakerService = \Drupal::service('paatokset_policymakers');

    if (!$case_id) {
      $case_id = $this->caseId;
    }
    $decisions = $this->getAllDecisions($case_id);

    $results = [];
    foreach ($decisions as $node) {
      $label = $this->formatDecisionLabel($node);

      if ($node->field_policymaker_id->value) {
        $class = Html::cleanCssIdentifier($policymakerService->getPolicymakerClassById($node->field_policymaker_id->value));
      }
      else {
        $class = 'color-sumu';
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

  /**
   * Get next decision in list, if one exists.
   *
   * @param string|null $case_id
   *   Case ID. Leave NULL to use active case.
   * @param string|null $decision_nid
   *   Decision native ID. Leave NULL to use active selection.
   *
   * @return array|null
   *   ID and title of next decision in list.
   */
  public function getNextDecision(?string $case_id = NULL, ?string $decision_nid = NULL): ?array {
    return $this->getAdjacentDecision(-1, $case_id, $decision_nid);
  }

  /**
   * Get previous decision in list, if one exists.
   *
   * @param string|null $case_id
   *   Case ID. Leave NULL to use active case.
   * @param string|null $decision_nid
   *   Decision native ID. Leave NULL to use active selection.
   *
   * @return array|null
   *   ID and title of previous decision in list.
   */
  public function getPrevDecision(?string $case_id = NULL, ?string $decision_nid = NULL): ?array {
    return $this->getAdjacentDecision(1, $case_id, $decision_nid);
  }

  /**
   * Get adjacent decision in list, if one exists.
   *
   * @param int $offset
   *   Which offset to use (1 for previous, -1 for next, etc).
   * @param string|null $case_id
   *   Case ID. Leave NULL to use active case.
   * @param string|null $decision_nid
   *   Decision native ID. Leave NULL to use active selection.
   *
   * @return array|null
   *   ID and title of adjacent decision in list.
   */
  private function getAdjacentDecision(int $offset, ?string $case_id = NULL, ?string $decision_nid = NULL): ?array {
    if (!$case_id) {
      $case_id = $this->caseId;
    }

    if (!$decision_nid) {
      $decision_nid = $this->selectedDecision->id();
    }

    $all_decisions = array_values($this->getAllDecisions($case_id));
    $found_node = NULL;
    foreach ($all_decisions as $key => $value) {
      if ((string) $value->id() !== $decision_nid) {
        continue;
      }

      if (isset($all_decisions[$key + $offset])) {
        $found_node = $all_decisions[$key + $offset];
      }
      break;
    }

    if (!$found_node instanceof NodeInterface) {
      return [];
    }

    return [
      'title' => $found_node->title->value,
      'id' => $found_node->field_decision_native_id->value,
    ];
  }

  /**
   * Get formatted section label for decision, including agenda point.
   *
   * @return string|null|Drupal\Core\StringTranslation\TranslatableMarkup
   *   Formatted section label, if possible to generate.
   */
  public function getFormattedDecisionSection(): mixed {
    if (!$this->selectedDecision instanceof NodeInterface) {
      return NULL;
    }

    if (!$this->selectedDecision->hasField('field_decision_section') || $this->selectedDecision->get('field_decision_section')->isEmpty()) {
      return NULL;
    }

    $section = $this->selectedDecision->get('field_decision_section')->value;

    if (!$this->selectedDecision->hasField('field_decision_record') || $this->selectedDecision->get('field_decision_record')->isEmpty()) {
      return '§ ' . $section;
    }

    $data = json_decode($this->selectedDecision->get('field_decision_record')->value, TRUE);

    if (!empty($data) && isset($data['AgendaPoint'])) {
      return t('Case @point. / @section §', [
        '@point' => $data['AgendaPoint'],
        '@section' => $section,
      ]);
    }

    return '§ ' . $section;
  }

  /**
   * Parse decision content and motion data from HTML.
   *
   * @return array
   *   Render arrays.
   */
  public function parseContent(): array {
    if ($this->selectedDecision instanceof NodeInterface) {
      return [];
    }

    $content = $this->selectedDecision->get('field_decision_content')->value;
    $motion = $this->selectedDecision->get('field_decision_motion')->value;

    $content_dom = new \DOMDocument();
    if (!empty($content)) {
      @$content_dom->loadHTML($content);
    }
    $content_xpath = new \DOMXPath($content_dom);

    $motion_dom = new \DOMDocument();
    if (!empty($motion)) {
      @$motion_dom->loadHTML($motion);
    }
    $motion_xpath = new \DOMXPath($motion_dom);

    $output = [];

    $main_content = NULL;
    // Main decision content sections.
    $content_sections = $content_xpath->query("//*[contains(@class, 'SisaltoSektio')]");

    foreach ($content_sections as $section) {
      $main_content .= $section->ownerDocument->saveHTML($section);
    }

    if ($main_content) {
      $output['main'] = [
        '#markup' => $main_content,
      ];
    }

    // Motion content sections.
    // If decision content is empty, print motion content as main content.
    $motion_sections = $motion_xpath->query("//*[contains(@class, 'SisaltoSektio')]");
    if (!$main_content) {
      $motion_content = NULL;
      foreach ($motion_sections as $section) {
        $motion_content .= $section->ownerDocument->saveHTML($section);
      }
      $output['main'] = [
        '#markup' => $motion_content,
      ];
    }
    else {
      // If decision content is set, split motions sections into accordions.
      $motion_accordions = $this->getMotionSections($motion_sections);
      foreach ($motion_accordions as $accordion) {
        $output['accordions'][] = $accordion;
      }
    }

    // More information.
    $more_info = $content_xpath->query("//*[contains(@class, 'LisatiedotOtsikko')]");
    $more_info_content = NULL;
    if ($more_info) {
      $more_info_content = $this->getHtmlContentUntilBreakingElement($more_info);
    }

    if ($more_info_content) {
      $output['more_info'] = [
        'heading' => t('Ask for more info'),
        'content' => ['#markup' => $more_info_content],
      ];
    }

    // Presenter information.
    $presenter_info = $content_xpath->query("//*[contains(@class, 'EsittelijaTiedot')]");
    $presenter_content = NULL;
    if ($presenter_info) {
      $presenter_content = $this->getHtmlContentUntilBreakingElement($presenter_info);
    }

    if ($presenter_content) {
      $output['accordions'][] = [
        'heading' => t('Presenter information'),
        'content' => ['#markup' => $presenter_content],
      ];
    }

    // Appeal information.
    $appeal_info = $content_xpath->query("//*[contains(@class, 'MuutoksenhakuOtsikko')]");
    $appeal_content = NULL;
    if ($appeal_info) {
      $appeal_content = $this->getHtmlContentUntilBreakingElement($appeal_info);
    }

    if ($appeal_content) {
      $output['accordions'][] = [
        'heading' => t('Appeal process'),
        'content' => ['#markup' => $appeal_content],
      ];
    }

    return $output;
  }

  /**
   * Split motions into sections.
   *
   * @param \DOMNodeList $list
   *   Motion content sections.
   *
   * @return array
   *   Array of sections.
   */
  private function getMotionSections(\DOMNodeList $list): array {
    $output = [];
    if ($list->length < 1) {
      return [];
    }

    $section = ['content' => ['#markup' => NULL]];
    // First section should always be motion description.
    $current_item = $list->item(0);
    foreach ($current_item->childNodes as $node) {
      if ($node->nodeName === 'h3') {
        $section['heading'] = $node->nodeValue;
        continue;
      }

      $section['content']['#markup'] .= $node->ownerDocument->saveHtml($node);
    }

    $output[] = $section;

    // Move on to other sections.
    $other_sections = $list->item(1);
    if (!$other_sections instanceof \DOMNode) {
      return $output;
    }

    $section = ['content' => ['#markup' => NULL]];
    foreach ($other_sections->childNodes as $node) {
      // If a h3 is reached, start over a new section.
      if ($node->nodeName === 'h3') {
        if (!empty($section['content']['#markup'])) {
          $output[] = $section;
          $section = ['content' => ['#markup' => NULL]];
        }

        $section['heading'] = $node->nodeValue;
        continue;
      }

      $section['content']['#markup'] .= $node->ownerDocument->saveHtml($node);
    }

    if (!empty($section['content']['#markup'])) {
      $output[] = $section;
    }

    return $output;
  }

  /**
   * Get HTML content from first heading until next heading.
   *
   * @param \DOMNodeList $list
   *   Xpath query results.
   *
   * @return string|null
   *   HTML content as string, or NULL if content is empty.
   */
  private function getHtmlContentUntilBreakingElement(\DOMNodeList $list): ?string {
    $output = NULL;
    if ($list->length < 1) {
      return NULL;
    }

    $current_item = $list->item(0);
    while ($current_item->nextSibling instanceof \DOMNode) {

      // Iterate over to next sibling. This skips the first one.
      $current_item = $current_item->nextSibling;

      // H3 with a class is considered a breaking element.
      if ($current_item->nodeName === 'h3' && !empty($current_item->getAttribute('class'))) {
        break;
      }

      // Skip over any empty elements.
      if (empty($current_item->nodeValue)) {
        continue;
      }

      $output .= $current_item->ownerDocument->saveHTML($current_item);
    }

    return $output;
  }

  /**
   * Query for case nodes.
   *
   * @param array $params
   *   Parameters for query.
   *
   * @return array
   *   List of case nodes.
   */
  public function caseQuery(array $params): array {
    $params['type'] = self::CASE_NODE_TYPE;
    return $this->query($params);
  }

  /**
   * Query for decision nodes.
   *
   * @param array $params
   *   Parameters for query.
   *
   * @return array
   *   List of decision nodes.
   */
  public function decisionQuery(array $params): array {
    $params['type'] = self::DECISION_NODE_TYPE;
    $params['sort_by'] = 'field_decision_date';
    return $this->query($params);
  }

  /**
   * Main query. Can fetch cases and decisions.
   *
   * @param array $params
   *   Parameters for query.
   *
   * @return array
   *   List of nodes.
   */
  private function query(array $params): array {
    if (isset($params['sort'])) {
      $sort = $params['sort'];
    }
    else {
      $sort = 'DESC';
    }

    if (isset($params['sort_by'])) {
      $sort_by = $params['sort_by'];
    }
    else {
      $sort_by = 'field_created';
    }

    $query = \Drupal::entityQuery('node')
      ->condition('status', 1)
      ->condition('type', $params['type'])
      ->sort($sort_by, $sort);

    $query->sort('nid', 'ASC');

    if (isset($params['limit'])) {
      $query->range('0', $params['limit']);
    }

    if (isset($params['title'])) {
      $query->condition('field_full_title', $params['title']);
    }

    if (isset($params['section'])) {
      $query->condition('field_decision_section', $params['section']);
    }

    if (isset($params['meeting_id'])) {
      $query->condition('field_meeting_id', $params['meeting_id']);
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
