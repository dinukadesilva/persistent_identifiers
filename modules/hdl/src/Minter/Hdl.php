<?php

namespace Drupal\hdl\Minter;

use Drupal\persistent_identifiers\MinterInterface;
use function mysql_xdevapi\getSession;

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
    $admin_handle = $config->get('hdl_admin_handle');
    $endpoint_url = $config->get('hdl_handle_api_endpoint');
    $url = $extra["url"] ?? "";
    $handle_json = [
      [
        'index' => 1,
        'type' => "URL",
        'data' => [
          'format' => "string",
          'value' => $url,
        ],
      ]
    ];

    $sessionId = $this->getSessionId();
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

  public function save($handle, $extra = NULL)
  {
    $config = \Drupal::config('hdl.settings');
    $endpoint_url = $config->get('hdl_handle_api_endpoint');
    $url = $extra["url"] ?? "";
    $handle_json = [
      [
        'index' => 1,
        'type' => "URL",
        'data' => [
          'format' => "string",
          'value' => $url,
        ],
      ]
    ];

    $sessionId = $this->getSessionId();
    $response3 = \Drupal::httpClient()->PUT($endpoint_url . "/api/handles/" . $handle, [
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

  public function fetch($handle)
  {
    $config = \Drupal::config('hdl.settings');
    $endpoint_url = $config->get('hdl_handle_api_endpoint');
    $sessionId = $this->getSessionId();
    $response3 = \Drupal::httpClient()->GET($endpoint_url . "/api/handles/" . $handle, [
      'headers' => [
        'Authorization' => 'Handle sessionId="' . $sessionId . '"',
        'Content-Type' => 'application/json',
        'Accept' => 'application/json'
      ]
    ]);
    $response3_body_array = json_decode($response3->getBody()->getContents(), TRUE);

    if (isset($response3_body_array["values"])) {
      foreach ($response3_body_array["values"] as $value) {
        if ($value["type"] == "URL") {
          return ["url" => $value["data"]["value"]];
        }
      }
    }

    return ["url" => NULL];
  }

  public function getSessionId()
  {
    $config = \Drupal::config('hdl.settings');
    $endpoint_url = $config->get('hdl_handle_api_endpoint');
    $admin_handle = $config->get('hdl_admin_handle');
    $handle_admin_index = $config->get('hdl_admin_index');
    $password = $config->get('hdl_handle_basic_auth_password');
    $private_key_pem = $password; // $config->get('hdl_handle_private_key');

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

    return $sessionId;
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


