<?php

namespace Drupal\hdl\Minter;

use Drupal\persistent_identifiers\MinterInterface;

/**
 * A Handle class.
 *
 * Mints a persistent identifier using a configurable
 * namespace and a random string.
 */
class Hdl implements MinterInterface
{

  /**
   * Returns the minter's name.
   *
   * @return string
   *   Appears in the Persistent Identifiers config form.
   */
  public function getName()
  {
    return t('Handle Minter');
  }

  /**
   * Returns the minter's type.
   *
   * @return string
   *   Appears in the entity edit form next to the checkbox.
   */
  public function getPidType()
  {
    return t('Handle');
  }

  /**
   * Mints the identifier.
   *
   * This sample minter simply returns a random string prepended by
   * a namespace, but this method is where you would request a new
   * DOI, ARK, etc.
   *
   * @param object $entity
   *   The entity.
   * @param mixed $extra
   *   Extra data the minter needs, for example from the node edit form.
   *
   * @return string
   *   The identifier.
   */
  public function mint($entity, $extra = NULL)
  {
    $config = \Drupal::config('hdl.settings');
    $handle_prefix = $config->get('hdl_prefix');

    if ($extra && array_key_exists('hdl_qualifier', $extra)) {
      $handle_type_qualifier = $extra['hdl_qualifier'];
    } else {
      $handle_type_qualifier = $config->get('hdl_qualifier');
    }
    $handle = $handle_prefix . '/';// . $handle_type_qualifier . '.' . $entity->id();
    $host = \Drupal::request()->getSchemeAndHttpHost();
    $url = "http://example.com"; //$host . $entity->toUrl()->toString();
    $admin_handle = $config->get('hdl_admin_handle');
    $handle_admin_index = $config->get('hdl_admin_index');
    $endpoint_url = $config->get('hdl_handle_api_endpoint');
    $permissions = $config->get('hdl_handle_permissions');
    $password = $config->get('hdl_handle_basic_auth_password');
    $private_key_pem = $password; // $config->get('hdl_handle_private_key');
    $handle_json = [
      [
        'index' => 1,
        'type' => "URL",
        'data' => [
          'format' => "string",
          'value' => $url,
        ],
      ],
      [
        'index' => 100,
        'type' => 'HS_ADMIN',
        'data' => [
          'format' => 'admin',
          'value' => [
            'handle' => $admin_handle,
            'index' => $handle_admin_index,
            'permissions' => $permissions,
          ],
        ],
      ],
    ];

    \Drupal::logger('persistent identifiers')->debug("##### Dinuka signature start");
    $cnonce = $this->generateRandomString(16);

    $response1 = \Drupal::httpClient()->post($endpoint_url . "/api/sessions", [
      'headers' => [
        'Authorization' => 'Handle cnonce="' . $cnonce . '"',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
      ],
    ]);
    $response1_body_array = json_decode($response1->getBody()->getContents(), TRUE);

    $sessionId = $response1_body_array["sessionId"];
    $nonce = $response1_body_array["nonce"];

    $data = base64_decode($nonce) . base64_decode($cnonce);
    openssl_sign($data, $signature, $private_key_pem, OPENSSL_ALGO_SHA256);
    $signature = base64_encode($signature);

    $response2 = \Drupal::httpClient()->PUT($endpoint_url . "/api/sessions/this", [
      'headers' => [
        'Authorization' => 'Handle sessionId="' . $sessionId . '",id="' . $handle_admin_index . ':' . $admin_handle . '",type="HS_PUBKEY",cnonce="' . $cnonce . '",alg="SHA256",signature="' . $signature . '"',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
      ],
    ]);
    $response2_body_array = json_decode($response2->getBody()->getContents(), TRUE);

    $response3 = \Drupal::httpClient()->PUT($endpoint_url . "/api/handles/" . $admin_handle . "/?overwrite=false&mintNewSuffix=true", [
      'headers' => [
        'Authorization' => 'Handle sessionId="' . $sessionId . '"',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
      ],
      'json' => $handle_json
    ]);
    $response3_body_array = json_decode($response3->getBody()->getContents(), TRUE);

    return $response3_body_array["handle"];
  }

  public function generateRandomString($length = 10)
  {
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
      $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
  }
}


