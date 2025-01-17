<?php

namespace Drupal\paatokset_ahjo_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for Case ajax events.
 */
class CaseController extends ControllerBase {

  /**
   * CaseService for getting case and decision data.
   *
   * @var \Drupal\paatokset_ahjo_api\Service\CaseService
   */
  private $caseService;

  /**
   * Class constructor.
   */
  public function __construct() {
    // Include twig engine.
    include_once \Drupal::root() . '/core/themes/engines/twig/twig.engine';
    $this->caseService = \Drupal::service('paatokset_ahjo_cases');
  }

  /**
   * Load a decision and return data as REST response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   JSON object containing data
   */
  public function loadDecision(string $case_id) : Response {
    $decision_id = \Drupal::request()->query->get('decision');
    $this->caseService->setEntitiesById($case_id, $decision_id);

    $data['decision_pdf'] = $this->caseService->getDecisionPdf();
    $data['selectedDecision'] = $this->caseService->getSelectedDecision();
    $data['decision_section'] = $this->caseService->getFormattedDecisionSection();
    $data['decision_org_name'] = $this->caseService->getDecisionOrgName();
    $data['selected_class'] = $this->caseService->getDecisionClass();
    $data['attachments'] = $this->caseService->getAttachments();
    $data['next_decision'] = $this->caseService->getNextDecision();
    $data['previous_decision'] = $this->caseService->getPrevDecision();
    $data['decision_content'] = $this->caseService->parseContent();
    $data['vote_results'] = $this->caseService->getVotingResults();
    $all_decisions_link = $this->caseService->getDecisionMeetingLink();
    $other_decisions_link = $this->caseService->getPolicymakerDecisionsLink();

    if ($all_decisions_link instanceof Url) {
      $all_decisions_link = $all_decisions_link->toString();
    }

    if ($other_decisions_link instanceof Url) {
      $other_decisions_link = $other_decisions_link->toString();
    }

    $languages = \Drupal::languageManager()->getLanguages();
    $language_urls = [];
    foreach ($languages as $langcode => $language) {
      $lang_url = $this->caseService->getCaseUrlFromNode(NULL, $langcode);
      if ($lang_url) {
        $lang_url->setOption('language', $language);
        $language_urls[$langcode] = $lang_url->toString();
      }
    }

    $content = twig_render_template(
      drupal_get_path('theme', 'helfi_paatokset') . '/templates/components/decision-content.html.twig',
      [
        'selectedDecision' => $data['selectedDecision'],
        'selected_class' => $data['selected_class'],
        'decision_org_name' => $data['decision_org_name'],
        'decision_content' => $data['decision_content'],
        'decision_section' => $data['decision_section'],
        'vote_results' => $data['vote_results'],
      ]
    );

    $attachments = twig_render_template(
      drupal_get_path('theme', 'helfi_paatokset') . '/templates/components/case-attachments.html.twig',
      [
        'attachments' => $data['attachments'],
      ]
    );

    $decision_navigation = twig_render_template(
      drupal_get_path('theme', 'helfi_paatokset') . '/templates/components/decision-navigation.html.twig',
      [
        'next_decision' => $data['next_decision'],
        'previous_decision' => $data['previous_decision'],
      ]
    );

    return new Response(json_encode([
      'content' => $content,
      'language_urls' => $language_urls,
      'attachments' => $attachments,
      'decision_navigation' => $decision_navigation,
      'show_warning' => !empty($data['next_decision']),
      'decision_pdf' => $data['decision_pdf'],
      'all_decisions_link' => $all_decisions_link,
      'other_decisions_link' => $other_decisions_link,
    ]));
  }

}
