<?php

namespace Drupal\ezid\Minter;

use Drupal\persistent_identifiers\MinterInterface;
use GuzzleHttp\Exception\RequestException;

/**
 * A Handle class.
 *
 * Mints a persistent identifier using a configurable
 * namespace and a random string.
 */
class Ezid implements MinterInterface {

  /**
   * Returns the minter's name.
   *
   * @return string
   *   Appears in the Persistent Identifiers config form.
   */
  public function getName() {
    return t('EZID ARK Minter');
  }

  /**
   * Returns the minter's type.
   *
   * @return string
   *   Appears in the entity edit form next to the checkbox.
   */
  public function getPidType() {
    return t('ARK');
  }

  /**
   * Mints the identifier.
   *
   * Issues a mint request to the EZID service for an ARK.
   *
   * @param object $entity
   *   The entity.
   * @param mixed $extra
   *   Extra data the minter needs, for example from the node edit form.
   *
   * @return string
   *   The identifier.
   */
  public function mint($entity, $extra = NULL) {
    $config = \Drupal::config('ezid.settings');
    $ezid_user = $config->get('ezid_user');
    $password = $config->get('ezid_password');
    $ezid_api_endpoint = $config->get('ezid_api_endpoint');
    $ezid_shoulder = $config->get('ezid_shoulder');

    $client = \Drupal::httpClient();
    try {
      $request = $client->request(
        'POST',
        $ezid_api_endpoint . '/shoulder/' . $ezid_shoulder,
        [
          'auth' => [$ezid_user, $password],
          'headers' => ['Content-Type' => 'text/plain; charset=UTF-8']
        ]);
      \Drupal::logger('persistent identifiers')->info(print_r($request, TRUE));
      $message = $request->getBody();
      if (strpos($message, "success: ") === 0) {
        return substr($message, 9);
      }
      \Drupal::logger('persistent identifiers')->error("Could not mint ark: $message");
      return FALSE;
    }
    catch (Exception $e) {
      $message = $e->getMessage();
      \Drupal::logger('persistent identifiers')->error(preg_replace('/Authorization: Basic \w+/', 'Authentication Redacted', $message));
      return FALSE;
    }
  }

  public function save($pid, $extra = NULL) {
    $config = \Drupal::config('ezid.settings');
    $ezid_user = $config->get('ezid_user');
    $password = $config->get('ezid_password');
    $ezid_api_endpoint = $config->get('ezid_api_endpoint');

    $data = "";
    foreach ($extra as $key => $value){
      $data = $data . "$key: $value\n";
    }

    $client = \Drupal::httpClient();
    try {
      $request = $client->request(
        'POST',
        $ezid_api_endpoint . '/id/' . $pid,
        [
          'auth' => [$ezid_user, $password],
          'headers' => ['Content-Type' => 'text/plain; charset=UTF-8'],
          'body' => $data,
        ]);
      \Drupal::logger('persistent identifiers')->info(print_r($request, TRUE));
      $message = $request->getBody();
      if (strpos($message, "success: ") === 0) {
        return substr($message, 9);
      }
      \Drupal::logger('persistent identifiers')->error("Could not save ark identifier metadata : $message");
      return FALSE;
    }
    catch (Exception $e) {
      $message = $e->getMessage();
      \Drupal::logger('persistent identifiers')->error(preg_replace('/Authorization: Basic \w+/', 'Authentication Redacted', $message));
      return FALSE;
    }
  }


  public function fetch($pid) {
    $config = \Drupal::config('ezid.settings');
    $ezid_user = $config->get('ezid_user');
    $password = $config->get('ezid_password');
    $ezid_api_endpoint = $config->get('ezid_api_endpoint');

    $client = \Drupal::httpClient();
    try {
      $request = $client->request(
        'GET',
        $ezid_api_endpoint . '/id/' . $pid,
        [
          'auth' => [$ezid_user, $password],
          'headers' => ['Content-Type' => 'text/plain; charset=UTF-8']
        ]);
      \Drupal::logger('persistent identifiers')->info(print_r($request, TRUE));
      $message = $request->getBody();
      if (strpos($message, "success: ") === 0) {
        $response_json = [];
        $response_lines = explode("\n", $message);
        foreach ($response_lines as $response_line) {
          $response_line_words = explode(": ", $response_line);
          $response_json[$response_line_words[0]] = substr($response_line, strlen($response_line_words[0]) + 2);
        }

        return $response_json;
      }
      \Drupal::logger('persistent identifiers')->error("Could not fetch ark identifier : $message");
      return FALSE;
    }
    catch (Exception $e) {
      $message = $e->getMessage();
      \Drupal::logger('persistent identifiers')->error(preg_replace('/Authorization: Basic \w+/', 'Authentication Redacted', $message));
      return FALSE;
    }
  }

}
