<?php

namespace Drupal\paatokset_ahjo\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Controller class for policymaker custom routes.
 */
class PolicymakerController extends ControllerBase {

  /**
   * Controller for policymaker subpages.
   */
  public function __construct() {
    $this->policymakerService = \Drupal::service('Drupal\paatokset_ahjo\Service\PolicymakerService');
  }

  /**
   * Policymaker documents route.
   */
  public function documents($organization) {
    $build = [
      '#title' => t('Documents: @title', ['@title' => $this->policymakerService->getPolicymaker()->get('title')->value]),
      '#markup' => '
        <p class="container"> ' .
      t('The documents are in both pdf and html format. In the HTML version, page layouts may differ from the original.
          The documents are divided into items according to the table of contents, but a print version of the entire document in pdf format is also available.') .
      '</p>',
    ];
    return $build;
  }

  /**
   * Return title as translatable string.
   */
  public static function getDocumentsTitle() {
    return t('Documents');
  }

  /**
   * Policymaker decisions route.
   */
  public function decisions($organization) {
    $build = ['#title' => t('Decisions: @title', ['@title' => $this->policymakerService->getPolicymaker()->get('title')->value])];
    return $build;
  }

  /**
   * Return title as translatable string.
   */
  public static function getDecisionsTitle() {
    return t('Decisions');
  }

  /**
   * Policymaker dicussion minutes route.
   */
  public function discussionMinutes() {
    $build = ['#title' => t('Discussion minutes: @title', ['@title' => $this->policymakerService->getPolicymaker()->get('title')->value])];

    $minutes = $this->policymakerService->getMinutesOfDiscussion(NULL, TRUE);

    if (!empty($minutes)) {
      $build['years'] = array_keys($minutes);
      $build['list'] = $minutes;
    }

    return $build;
  }

  /**
   * Return view for singular minutes.
   */
  public function minutes($organization, $id) {
    $meetingData = $this->policymakerService->getMeetingAgenda($id);

    $build = [
      '#theme' => 'policymaker_minutes',
    ];

    if ($meetingData) {
      $build['meeting'] = $meetingData['meeting'];
      $build['list'] = $meetingData['list'];
      $build['file'] = $meetingData['file'];
    }

    return $build;
  }

  /**
   * Return title as translatable string.
   */
  public static function getDiscussionMinutesTitle() {
    return t('Discussion minutes');
  }

  /**
   * Return translatable title for minutes.
   */
  public static function getMinutesTitle() {
    return t('Minutes');
  }

}
