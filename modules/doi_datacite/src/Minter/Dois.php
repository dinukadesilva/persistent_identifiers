<?php

namespace Drupal\doi_datacite\Minter;

use Drupal\persistent_identifiers\MinterInterface;
use Drupal\Core\Url;
use Drupal\Component\Utility\Html;

/**
 * DataCite DOI minter.
 */
class Dois implements MinterInterface
{

  /**
   * Constructor.
   */
  public function __construct()
  {
    $config = \Drupal::config('doi_datacite.settings');
  }

  /**
   *
   */
  public function getResourceTypes()
  {
    return [
      'Audiovisual' => 'Audiovisual',
      'Collection' => 'Collection',
      'Dataset' => 'Dataset',
      'Event' => 'Event',
      'Image' => 'Image',
      'InteractiveResource' => 'InteractiveResource',
      'Model' => 'Model',
      'PhysicalObject' => 'PhysicalObject',
      'Service' => 'Service',
      'Software' => 'Software',
      'Sound' => 'Sound',
      'Text' => 'Text',
      'Workflow' => 'Workflow',
      'Other' => 'Other',
    ];
  }

  /**
   * Returns the minter's name.
   *
   * @return string
   *   Appears in the Persistent Identifiers config form.
   */
  public function getName()
  {
    return t('DataCite DOI');
  }

  /**
   * Returns the minter's type.
   *
   * @return string
   *   Appears in the entity edit form next to the checkbox.
   */
  public function getPidType()
  {
    return t('DataCite DOI');
  }

  /**
   * Mints the identifier.
   *
   * @param object $entity
   *   The node.
   * @param mixed $extra
   *   The node edit form state or data from the Views Bulk Operations action.
   *
   * @return string|NULL;
   *   The DOI that will be saved in the persister's designated field.
   *   NULL if there was an error (i.e., non-201 response) POSTing to the API.
   */
  public function mint($entity, $extra = NULL)
  {
    global $base_url;
    $config = \Drupal::config('doi_datacite.settings');

    // This minter needs $extra.
    if (is_null($extra)) {
      return NULL;
    }

    if ($config->get('doi_datacite_suffix_source') == 'id') {
      $suffix = $entity->id();
      $doi = $config->get('doi_datacite_prefix') . '/' . $suffix;
    }
    if ($config->get('doi_datacite_suffix_source') == 'uuid') {
      $suffix = $entity->Uuid();
      $doi = $config->get('doi_datacite_prefix') . '/' . $suffix;
    }

    // If $extra is from the Views Bulk Operations Action
    // (i.e., it's an array).
    if (is_array($extra)) {
      $datacite_array = [];
      $creators = explode(';', $extra['doi_datacite_creator']);
      $datacite_creators = [];
      foreach ($creators as $creator) {
        $datacite_creators[] = ['name' => trim($creator)];
      }
      $datacite_titles = [];
      $datacite_titles[] = ['title' => $entity->title->value];
      $datacite_array['data']['type'] = 'dois';
      $attributes = [
        'event' => 'publish',
        'creators' => $datacite_creators,
        'titles' => $datacite_titles,
        'publisher' => $extra['doi_datacite_publisher'],
        'publicationYear' => $extra['doi_datacite_publication_year'],
        'types' => ['resourceTypeGeneral' => $extra['doi_datacite_resource_type']],
        'url' => $base_url . '/node/' . $entity->id(),
        'schemaVersion' => 'http://datacite.org/schema/kernel-4',
      ];
      $datacite_array['data']['attributes'] = $attributes;
    }

    // If $extra is from the node edit form (i.e., it's an instance of
    // Drupal\Core\Form\FormState).
    if (is_object($extra) && method_exists($extra, 'getValue')) {
      $datacite_array = [];
      $creators = explode(';', $extra->getValue('doi_datacite_creator'));
      $datacite_creators = [];
      foreach ($creators as $creator) {
        $datacite_creators[] = ['name' => trim($creator)];
      }
      $datacite_titles = [];
      $datacite_titles[] = ['title' => $entity->title->value];
      $datacite_array['data']['type'] = 'dois';
      $attributes = [
        'event' => 'publish',
        'creators' => $datacite_creators,
        'titles' => $datacite_titles,
        'publisher' => $extra->getValue('doi_datacite_publisher'),
        'publicationYear' => $extra->getValue('doi_datacite_publication_year'),
        'types' => ['resourceTypeGeneral' => $extra->getValue('doi_datacite_resource_type')],
        'url' => $base_url . '/node/' . $entity->id(),
        'schemaVersion' => 'http://datacite.org/schema/kernel-4',
      ];
      $datacite_array['data']['attributes'] = $attributes;
    }

    if ($config->get('doi_datacite_suffix_source') == 'auto') {
      $datacite_array['data']['attributes']['prefix'] = $config->get('doi_datacite_prefix');
    } else {
      $datacite_array['data']['id'] = $doi;
      $datacite_array['data']['attributes']['doi'] = $doi;
    }

    $datacite_json = json_encode($datacite_array);

    // Define a hook so people can write modules to alter the JSON.
    \Drupal::moduleHandler()->invokeAll('doi_datacite_json_alter', [$entity, $extra, &$datacite_json]);

    $minted_doi = $this->postToApi($entity->id(), $datacite_json);
    return $minted_doi;
  }

  public function save($doi = NULL, $extra = NULL)
  {
    global $base_url;
    $config = \Drupal::config('doi_datacite.settings');

    // This save needs $extra.
    if (is_null($extra)) {
      return NULL;
    }

    $datacite_json = [
      "data" => [
        "type" => "dois",
        "attributes" => [
          "doi" => $doi,
          "event" => "publish",
          "creators" => [[
            "name" => $extra['doi_datacite_creator']
          ]],
          "titles" => [[
            "title" => $extra['doi_datacite_title']
          ]],
          "publisher" => $extra['doi_datacite_publisher'],
          "publicationYear" => $extra['doi_datacite_publication_year'],
          "types" => [
            "resourceTypeGeneral" => $extra['doi_datacite_resource_type']
          ],
          //"url"=> "https://schema.datacite.org/meta/kernel-4.0/index.html",
          "url" => $extra['doi_datacite_url'],
          "schemaVersion" => "http://datacite.org/schema/kernel-4"
        ]
      ]
    ];

    if (is_null($doi)) {
      $datacite_json["data"]["attributes"]["prefix"] = $config->get('doi_datacite_prefix');
    } else {
      $datacite_json["data"]["id"] = $doi;
      $datacite_json["data"]["attributes"]["doi"] = $doi;
    }

    $datacite_json = json_encode($datacite_json);

    // Define a hook so people can write modules to alter the JSON.
    // \Drupal::moduleHandler()->invokeAll('doi_datacite_json_alter', [$entity, $extra, &$datacite_json]);

    $response = \Drupal::httpClient()->put($config->get('doi_datacite_api_endpoint') . "/" . $doi, [
      'auth' => [$config->get('doi_datacite_username'), $config->get('doi_datacite_password')],
      'body' => $datacite_json,
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/vnd.api+json',
      ],
    ]);

    $http_code = $response->getStatusCode();

    if ($http_code == 200) {
      $response_json = json_decode($response->getBody()->getContents(), TRUE)["data"];

      \Drupal::logger('doi_datacite')->warning(t("##### put Dinuka -----  @code ", [
        '@code' => json_encode($response_json)
      ]));

      return [
        "doi_datacite_identifier" => "",//$response_json["attributes"]["identifiers"][0]["identifier"],
        "doi_datacite_title" => $response_json["attributes"]["titles"][0]["title"],
        "doi_datacite_url" => $response_json["attributes"]["url"],
        "doi_datacite_creator" => $response_json["attributes"]["creators"][0]["name"],
        "doi_datacite_publisher" => $response_json["attributes"]["publisher"],
        "doi_datacite_publication_year" => $response_json["attributes"]["publicationYear"],
        "doi_datacite_resource_type" => $response_json["attributes"]["types"]["resourceTypeGeneral"]
      ];
    } else {
      switch ($http_code) {
        case 404:
          $hint = ' Hint: Check your repository ID, DOI prefix, and/or password.' . $datacite_json;
          break;
        case 400:
          $hint = ' Hint: The JSON being sent to DataCite may be invalid (' . $datacite_json . ').';
          break;
        case 422:
          $hint = '';
          break;
        default:
          $hint = '';
      }
      \Drupal::logger('doi_datacite')->warning(t("While saving DOI, the DataCite API returned @code status with the message '@message' @hint , @datacitejson", [
        '@code' => $http_code,
        '@message' => $response->getBody()->getContents(),
        '@hint' => $hint,
        '@datacitejson' => $datacite_json
      ]));
      return NULL;
    }
  }

  public function fetch($doi)
  {
    global $base_url;
    $config = \Drupal::config('doi_datacite.settings');

    $response = \Drupal::httpClient()->get($config->get('doi_datacite_api_endpoint') . "/" . $doi, [
      'auth' => [$config->get('doi_datacite_username'), $config->get('doi_datacite_password')],
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/vnd.api+json',
      ],
    ]);

    $http_code = $response->getStatusCode();

    if ($http_code == 200) {
      $response_json = json_decode($response->getBody()->getContents(), TRUE)["data"];

      \Drupal::logger('doi_datacite')->warning(t("##### fetch Dinuka -----  @code ", [
        '@code' => json_encode($response_json)
      ]));

      return [
        "doi_datacite_identifier" => "", //$response_json["attributes"]["identifiers"][0]["identifier"],
        "doi_datacite_title" => $response_json["attributes"]["titles"][0]["title"] ?? NULL,
        "doi_datacite_url" => $response_json["attributes"]["url"] ?? NULL,
        "doi_datacite_creator" => $response_json["attributes"]["creators"][0]["name"] ?? NULL,
        "doi_datacite_publisher" => $response_json["attributes"]["publisher"] ?? NULL,
        "doi_datacite_publication_year" => $response_json["attributes"]["publicationYear"] ?? NULL,
        "doi_datacite_resource_type" => $response_json["attributes"]["types"]["resourceTypeGeneral"] ?? NULL
      ];
    } else {
      switch ($http_code) {
        case 404:
          $hint = ' Hint: Check your repository ID, DOI prefix, and/or password.';
          break;
        case 400:
          $hint = ' Hint: The JSON being sent to DataCite may be invalid.';
          break;
        case 422:
          $hint = '';
          break;
        default:
          $hint = '';
      }
      \Drupal::logger('doi_datacite')->warning(t("While saving DOI, the DataCite API returned @code status with the message '@message' @hint", [
        '@code' => $http_code,
        '@message' => $response->getBody()->getContents(),
        '@hint' => $hint
      ]));
      return NULL;
    }
  }

  public function mintDraft()
  {
    global $base_url;
    $config = \Drupal::config('doi_datacite.settings');

    $datacite_array = [
      'data' => [
        'type' => 'dois',
        'attributes' => [
          'prefix' => $config->get('doi_datacite_prefix')
        ]
      ]
    ];

    $datacite_json = json_encode($datacite_array);

    $minted_doi = $this->postToApi($datacite_json);
    return $minted_doi;
  }


  /**
   * POSTs to DataCite REST API to create and publish the DOI.
   *
   * @param int $nid
   *   The node ID.
   * @param string $datacite_json
   *   The DataCite JSON. See https://support.datacite.org/docs/api-create-dois
   *   for more information.
   *
   * @return string|NULL
   *   The DOI string if successful, NULL if not.
   */
  public function postToApi($datacite_json)
  {
    $config = \Drupal::config('doi_datacite.settings');

    $response = \Drupal::httpClient()->post($config->get('doi_datacite_api_endpoint'), [
      'auth' => [$config->get('doi_datacite_username'), $config->get('doi_datacite_password')],
      'body' => $datacite_json,
      'http_errors' => FALSE,
      'headers' => [
        'Content-Type' => 'application/vnd.api+json',
      ],
    ]);

    $http_code = $response->getStatusCode();
    // DataCite's API returns a 201 if the request was successful.
    if ($http_code == 201) {
      $response_body_array = json_decode($response->getBody()->getContents(), TRUE);
      $doi = $response_body_array['data']['attributes']['doi'];
      return $doi;
    } else {
      switch ($http_code) {
        case 404:
          $hint = ' Hint: Check your repository ID, DOI prefix, and/or password.' . $datacite_json;
          break;
        case 400:
          $hint = ' Hint: The JSON being sent to DataCite may be invalid (' . $datacite_json . ').';
          break;
        case 422:
          $hint = '';
          break;
        default:
          $hint = '';
      }
      \Drupal::logger('doi_datacite')->warning(t("While minting DOI, the DataCite API returned @code status with the message '@message' @hint , @datacitejson", [
        '@code' => $http_code,
        '@message' => $response->getBody()->getContents(),
        '@hint' => $hint,
        '@datacitejson' => $datacite_json
      ]));
      return NULL;
    }
  }

  /**
   * Attempts to prepopulate DataCite-specific metadata fields.
   *
   * @param int $nid
   *   The node ID.
   *
   * @return array
   *   An associative array of metadata values that can be gotten from
   *   existing node fields, to provide default values for the required DataCite metadata.
   */
  public function getDataCiteElementValues($nid)
  {
    $config = \Drupal::config('doi_datacite.settings');
    $mappings = preg_split("/\\r\\n|\\r|\\n/", $config->get('doi_datacite_field_mappings'));
    $datacite_values = [
      'creators' => '',
      'publicationYear' => '',
      'publisher' => '',
    ];

    if (empty($nid)) {
      return $datacite_values;
    }

    $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
    // Creators.
    $creators_field_name = $config->get('doi_datacite_creators_mapping');
    if ($node->hasField($creators_field_name)) {
      $node_creators_field_values = $node->get($creators_field_name)->getValue();
      if (array_key_exists('value', $node_creators_field_values[0])) {
        $datacite_values['creators'] = $node_creators_field_values[0]['value'];
      }
    }
    // Publisher.
    $publisher_field_name = $config->get('doi_datacite_publisher_mapping');
    if ($node->hasField($publisher_field_name)) {
      $node_publisher_field_values = $node->get($publisher_field_name)->getValue();
      if (array_key_exists('value', $node_publisher_field_values[0])) {
        $datacite_values['publisher'] = $node_publisher_field_values[0]['value'];
      }
    }
    // Publication year.
    $publication_year_field_name = $config->get('doi_datacite_publicationyear_mapping');
    if ($node->hasField($publication_year_field_name)) {
      $node_publication_year_field_values = $node->get($publication_year_field_name)->getValue();
      if (array_key_exists('value', $node_publication_year_field_values[0])) {
        $datacite_values['publicationYear'] = $node_publication_year_field_values[0]['value'];
      }
    }

    return $datacite_values;
  }

}
