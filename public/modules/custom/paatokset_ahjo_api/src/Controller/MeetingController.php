<?php

namespace Drupal\paatokset_ahjo_api\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for retrieving meeting data.
 */
class MeetingController extends ControllerBase {
  /**
   * Instance of MeetingService.
   *
   * @var \Drupal\paatokset_ahjo_api\Service\MeetingService
   */
  private $meetingService;

  /**
   * Class constuctor.
   */
  public function __construct() {
    $this->meetingService = \Drupal::service('paatokset_ahjo_meetings');
  }

  /**
   * Retrieves matching meeting data.
   *
   * See MeetingService class for parameter definition.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   Automatically injected Request object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   Return response with queried data or errors.
   */
  public function query(Request $request) : Response {
    $params = [];

    $allowedParams = [
      'from',
      'to',
      'agenda_published',
      'minutes_published',
      'policymaker',
    ];

    foreach ($allowedParams as $param) {
      if ($request->query->get($param)) {
        $params[$param] = $request->query->get($param);
      }
    }

    try {
      $meetings = $this->meetingService->query($params);
    }
    catch (\throwable $error) {
      return new Response(
        json_encode([
          'errors' => $error->getMessage(),
        ]),
        Response::HTTP_BAD_REQUEST
      );
    }

    return new Response(
      json_encode([
        'data' => $meetings,
      ]),
      Response::HTTP_OK,
      ['content-type' => 'application/json']
    );
  }

}
