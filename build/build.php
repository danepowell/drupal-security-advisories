<?php

use GuzzleHttp\Subscriber\Cache\CacheSubscriber;

require __DIR__ . '/vendor/autoload.php';

$results = array();

$versionParser = new \Composer\Package\Version\VersionParser();

$client = new \GuzzleHttp\Client();
$storage = new \GuzzleHttp\Subscriber\Cache\CacheStorage(new \Doctrine\Common\Cache\FilesystemCache(__DIR__ . '/cache'));
CacheSubscriber::attach($client, ['storage' => $storage]);

$data = json_decode($client->get('https://www.drupal.org/api-d7/node.json?type=project_release&taxonomy_vocabulary_7=100')->getBody());

$projects = [];
$conflict = [];

class UrlHelper {

  public static function prepareUrl($url) {
    return str_replace('https://www.drupal.org/api-d7/node', 'https://www.drupal.org/api-d7/node.json', $url);
  }

}

while (isset($data) && isset($data->list)) {
  $results = array_merge($results, $data->list);

  if (isset($data->next)) {
    $data = json_decode($client->get(UrlHelper::prepareUrl($data->next))->getBody());
  }
  else {
    $data = NULL;
  }
}

foreach ($results as $result) {
  if ($result->field_release_build_type == 'dynamic') {
    continue;
  }

  $nid = $result->field_release_project->id;
  $version = new \Drupal\ParseComposer\Version($result->field_release_version);

  // Skip D6 and older.
  if ($version->getCore() < 7) {
    continue;
  }

  try {
    if (!isset($projects[$nid])) {
      $project = json_decode($client->get('https://www.drupal.org/api-d7/node.json?nid=' . $nid)->getBody());
      $projects[$nid] = $project->list[0];
    }
  } catch (\GuzzleHttp\Exception\ServerException $e) {
    // @todo: log exception
    continue;
  }

  $project = $projects[$nid];
  if ($project->field_project_machine_name == 'drupal') {
    // @todo: fix core version parser.
    continue;
  }

  try {
    $constraint = '<' . $version->getSemver();
    $versionParser->parseConstraints($constraint);
    $conflict['drupal/' . $project->field_project_machine_name][] = '<' . $version->getSemver();
  } catch (\Exception $e) {
    // @todo: log exception
    continue;
  }
}

$composer = [
  'name' => 'webflo/security-advisories',
  'type' => 'metapackage',
  'license' => 'GPL-2.0+',
  'conflict' => []
];

foreach ($conflict as $package => $constraints) {
  sort($constraints);
  $composer['conflict'][$package] = implode(',', $constraints);
}

ksort($composer['conflict']);
file_put_contents(__DIR__ . '/../composer.json', json_encode($composer, JSON_PRETTY_PRINT));






