<?php

namespace Drupal\paatokset_ahjo_api\Controller;

use Drupal\node\NodeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\node\Controller\NodeViewController;
use Drupal\paatokset_ahjo_api\Service\CaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Defines a controller to render a single node.
 */
class CaseNodeViewController extends NodeViewController {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * The entity repository service.
   *
   * @var \Drupal\Core\Entity\EntityRepositoryInterface
   */
  protected $entityRepository;

  /**
   * Case and decision service.
   *
   * @var \Drupal\paatokset_ahjo_api\Service\CaseService
   */
  private $caseService;

  /**
   * Creates a NodeViewController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Render\RendererInterface $renderer
   *   The renderer service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\paatokset_ahjo_api\Service\CaseService $case_service
   *   Case service.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, RendererInterface $renderer, AccountInterface $current_user, EntityRepositoryInterface $entity_repository, CaseService $case_service) {
    parent::__construct($entity_type_manager, $renderer, $current_user, $entity_repository);
    $this->caseService = $case_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('renderer'),
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('paatokset_ahjo_cases')
    );
  }

  /**
   * Return untranslated case (or decision) node on other languages.
   *
   * @param string $case_id
   *   Case diary number (or decision native ID).
   */
  public function case(string $case_id) {
    $node = $this->caseService->caseQuery([
      'case_id' => $case_id,
      'limit' => 1,
    ]);

    // If we don't get a case node, try to get a decision instead.
    if (empty($node)) {
      $decision_id = '{' . strtoupper($case_id) . '}';
      $node = $this->caseService->decisionQuery([
        'decision_id' => $decision_id,
        'limit' => 1,
      ]);
    }

    $node = reset($node);

    if ($node instanceof EntityInterface) {
      return parent::view($node, 'full');
    }

    throw new NotFoundHttpException();
  }

  /**
   * Return untranslated decision node on other languages.
   *
   * @param string $case_id
   *   Case diary number.
   * @param string $decision_id
   *   Decision native ID.
   */
  public function decision(string $case_id, string $decision_id) {
    $decision_id = '{' . strtoupper($decision_id) . '}';
    $this->caseService->setEntitiesById($case_id, $decision_id);
    $node = $this->caseService->getSelectedDecision();

    if ($node instanceof EntityInterface) {
      return parent::view($node, 'full');
    }

    throw new NotFoundHttpException();
  }

  /**
   * Return title for untranslated case (or decision) node.
   */
  public function caseTitle() {
    $node = $this->caseService->getSelectedCase();
    if ($node instanceof NodeInterface) {
      return $node->title->value;
    }

    $node = $this->caseService->getSelectedDecision();
    if ($node instanceof NodeInterface) {
      return $node->title->value . ' - ' . $node->field_dm_org_name->value;
    }
  }

  /**
   * Return title for untranslated decision node.
   */
  public function decisionTitle() {
    $node = $this->caseService->getSelectedDecision();
    if ($node instanceof NodeInterface) {
      return $node->title->value . ' - ' . $node->field_dm_org_name->value;
    }
  }

}
