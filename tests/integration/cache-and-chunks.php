<?php

/**
 * @file
 * Run with drush php:script on an installed sandbox site.
 *
 * Cache table changes are rolled back; files use a unique temporary directory.
 */

use Drupal\local_tts\Controller\TtsController;
use Symfony\Component\HttpFoundation\Request;
use Drupal\local_tts\Form\LocalTtsBatchForm;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Config\MemoryStorage;
use Drupal\local_tts\Service\TtsCacheManager;
use Drupal\local_tts\Service\TtsCronService;

$failures = [];
$check = static function ($condition, $label) use (&$failures) {
  echo ($condition ? 'PASS ' : 'FAIL ') . $label . PHP_EOL;
  if (!$condition) {
    $failures[] = $label;
  }
};
$generator = Drupal::service('local_tts.tts_generator');
foreach ([str_repeat('word ', 1500), str_repeat('界', 5001), str_repeat('A sentence. ', 600)] as $text) {
  $chunks = $generator->splitIntoChunks($text);
  $check(max(array_map('mb_strlen', $chunks)) <= 2000, 'Every chunk respects the 2000 character limit');
  $check(preg_replace('/\s+/u', '', implode('', $chunks)) === preg_replace('/\s+/u', '', $text), 'Chunking preserves all non-whitespace text');
}
$invalidRejected = FALSE;
try {
  $generator->splitIntoChunks('text', 0);
}
catch (InvalidArgumentException $e) {
  $invalidRejected = TRUE;
}
$check($invalidRejected, 'Reject invalid chunk size');

$database = Drupal::database();
$transaction = $database->startTransaction();
$directory = sys_get_temp_dir() . '/local-tts-review-' . bin2hex(random_bytes(8));
mkdir($directory);
try {
  $database->delete('local_tts_cache')->execute();
  $storage = new MemoryStorage();
  $config = Drupal::config('local_tts.settings')->getRawData();
  $config['audio_directory'] = $directory;
  $config['cache_max_size'] = 150;
  $config['rate_limit_enabled'] = TRUE;
  $config['rate_limit_threshold'] = 2;
  $storage->write('local_tts.settings', $config);
  $factory = new ConfigFactory($storage, Drupal::service('event_dispatcher'), Drupal::service('config.typed'));
  $cache = new TtsCacheManager($factory, Drupal::service('file_system'), $database, Drupal::time(), Drupal::service('logger.factory'));
  $key = md5('review shared file');
  file_put_contents($directory . '/' . $key . '.ogg', str_repeat('a', 100));
  foreach (['first', 'second'] as $id) {
    $cache->saveMetadata($key, 'review shared file', 'af_sky', 1, ['entity_type' => 'review', 'entity_id' => $id]);
  }
  $check($database->schema()->indexExists('local_tts_cache', 'entity_variant'), 'Entity variant uniqueness constraint is installed');
  $usage = $cache->getDiskUsage();
  $check($usage === ['total_size' => 100, 'file_count' => 1], 'Shared file counted once in disk usage');
  $database->update('local_tts_cache')->fields(['created' => 123])->execute();
  $cache->saveMetadata($key, 'review shared file', 'af_sky', 1, ['entity_type' => 'review', 'entity_id' => 'first']);
  $created = $database->select('local_tts_cache', 'c')->fields('c', ['created'])->condition('entity_id', 'first')->execute()->fetchField();
  $check((int) $created === 123, 'Cached access preserves creation time');
  $oldPart = $directory . '/' . $key . '.deadbeef.part';
  file_put_contents($oldPart, 'incomplete');
  touch($oldPart, time() - 7200);
  $youngPart = $directory . '/' . $key . '.new.part';
  file_put_contents($youngPart, 'active');
  // A private in-memory state avoids altering the site's cron timestamp.
  $state = new class() implements StateInterface {
    /**
     * In-memory state values.
     */
    private array $values = [];

    /**
     * {@inheritdoc}
     */
    public function get($key, $default = NULL) {
      return $this->values[$key] ?? $default;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys) {
      return array_intersect_key($this->values, array_flip($keys));
    }

    /**
     * {@inheritdoc}
     */
    public function set($key, $value) {
      $this->values[$key] = $value;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $data) {
      $this->values = $data + $this->values;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($key) {
      unset($this->values[$key]);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys) {
      foreach ($keys as $key) {
        $this->delete($key);
      }
    }

    /**
     * {@inheritdoc}
     */
    public function resetCache() {}

    /**
     * {@inheritdoc}
     */
    public function getValuesSetDuringRequest(string $key): ?array {
      return array_key_exists($key, $this->values) ? ['value' => $this->values[$key], 'original' => NULL] : NULL;
    }

  };
  $cron = new TtsCronService($factory, $database, Drupal::entityTypeManager(), Drupal::service('file_system'), $cache, Drupal::time(), $state, Drupal::service('logger.factory'));
  $cron->cleanupOrphanFiles();
  $check(!file_exists($oldPart), 'Old partial output removed even when final cache key exists');
  $check(file_exists($youngPart), 'Active partial output retained');
  $cron->enforceSizeLimit();
  $check(file_exists($directory . '/' . $key . '.ogg'), 'Shared cache references do not trigger early eviction');
  $cache->deleteAudioForEntity('review', 'first');
  $check(file_exists($directory . '/' . $key . '.ogg'), 'Deleting first reference preserves shared file');
  $cache->deleteAudioForEntity('review', 'second');
  $check(!file_exists($directory . '/' . $key . '.ogg'), 'Deleting final reference removes shared file');
}
finally {
  $transaction->rollBack();
  Drupal::service('file_system')->deleteRecursive($directory);
}

foreach (['1garbage', 0, 2.1, INF, NAN] as $speed) {
  $rejected = FALSE;
  try {
    Drupal::service('local_tts.tts_service')->generateSpeech('Invalid speed must never run inference.', [
      'speed' => $speed,
      'use_cache' => FALSE,
    ]);
  }
  catch (InvalidArgumentException $e) {
    $rejected = TRUE;
  }
  $check($rejected, 'Reject malformed or out-of-range synthesis speed');
}
$batchForm = Drupal::formBuilder()->getForm(LocalTtsBatchForm::class);
$threshold = $batchForm['load_management']['max_load_threshold'];
$check($threshold['#default_value'] >= $threshold['#min'] && $threshold['#default_value'] <= $threshold['#max'], 'Batch load threshold default passes its own validation');
// Exercise the real flood backend without consuming the site's visitor quota.
$floodTransaction = Drupal::database()->startTransaction();
$requestStack = Drupal::service('request_stack');
$requestStack->push(Request::create('/', 'POST', [], [], [], ['REMOTE_ADDR' => '192.0.2.123']));
try {
  $controller = TtsController::create(Drupal::getContainer());
  $property = new ReflectionProperty($controller, 'configFactory');
  $property->setValue($controller, $factory);
  $method = new ReflectionMethod($controller, 'checkRateLimit');
  $flood = Drupal::service('flood');
  $flood->clear('local_tts.generate');
  $check($method->invoke($controller), 'Generation quota initially permits requests');
  for ($i = 0; $i < $config['rate_limit_threshold']; $i++) {
    $flood->register('local_tts.generate', 3600);
  }
  $check(!$method->invoke($controller), 'Generation quota blocks requests at configured threshold');
}
finally {
  $requestStack->pop();
  $floodTransaction->rollBack();
}
if ($failures) {
  throw new RuntimeException(count($failures) . ' regression checks failed.');
}
