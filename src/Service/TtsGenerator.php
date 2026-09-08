<?php

namespace Drupal\local_tts\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\local_tts\Exception\TtsServiceUnavailableException;
use Drupal\local_tts\Exception\TtsTimeoutException;

/**
 * Handles TTS binary execution, chunking and ffmpeg transcoding.
 */
class TtsGenerator {

  const CHUNK_SIZE = 2000;

  /**
   * The logger channel.
   */
  protected LoggerChannelInterface $logger;

  /**
   * Path to the local_tts module.
   */
  protected string $modulePath;

  public function __construct(
    protected readonly ConfigFactoryInterface $configFactory,
    protected readonly FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
    ModuleExtensionList $extensionListModule,
  ) {
    $this->logger = $loggerFactory->get('local_tts');
    $this->modulePath = $extensionListModule->getPath('local_tts');
  }

  /**
   * Generate an OGG Opus audio file from text.
   *
   * @return string
   *   Absolute path to the generated .ogg file.
   */
  public function generate(string $text, string $cacheKey, string $voice, float $speed, string $espeakLang, int $timeout): string {
    $config = $this->configFactory->get('local_tts.settings');
    $audioDir = $config->get('audio_directory') ?: 'public://local-tts';

    if (!$this->fileSystem->prepareDirectory($audioDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      throw new TtsServiceUnavailableException(sprintf('Failed to create audio directory: %s', $audioDir));
    }
    $directory = $this->fileSystem->realpath($audioDir);
    if (!$directory) {
      throw new TtsServiceUnavailableException(sprintf('Audio directory path could not be resolved: %s', $audioDir));
    }

    $oggOutput = $directory . '/' . $cacheKey . '.ogg';
    $binaryPath = $this->getBinaryPath();
    $dataDir = $this->getDataDir();
    $modelPath = $dataDir . '/kokoro-v1.0.onnx';
    $dataPath = $dataDir . '/voices-v1.0.bin';

    $this->validateBinary($binaryPath, $modelPath, $dataPath);
    $this->checkServerLoad($config);

    if (mb_strlen($text) > self::CHUNK_SIZE) {
      $this->generateChunked($text, $oggOutput, $binaryPath, $modelPath, $dataPath, $voice, $speed, $espeakLang, $cacheKey, $directory, $timeout);
    }
    else {
      $this->generateSingle($text, $oggOutput, $binaryPath, $modelPath, $dataPath, $voice, $speed, $espeakLang, $directory, $timeout);
    }

    return $oggOutput;
  }

  /**
   * Get the absolute path to the koko binary.
   */
  public function getBinaryPath(): string {
    return DRUPAL_ROOT . '/' . $this->modulePath . '/bin/koko';
  }

  /**
   * Get the absolute path to the data directory.
   */
  public function getDataDir(): string {
    return DRUPAL_ROOT . '/' . $this->modulePath . '/data';
  }

  /**
   * Resolve the absolute path to the ffmpeg binary.
   */
  public function findFfmpeg(): string {
    $candidates = [
      '/usr/bin/ffmpeg',
      '/usr/local/bin/ffmpeg',
      '/opt/homebrew/bin/ffmpeg',
    ];
    foreach ($candidates as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    $output = [];
    $code = 0;
    @exec('which ffmpeg 2>/dev/null', $output, $code);
    if ($code === 0 && !empty($output[0]) && is_executable($output[0])) {
      return $output[0];
    }
    throw new TtsServiceUnavailableException('ffmpeg not found. Install ffmpeg for audio transcoding.');
  }

  /**
   * Split text into chunks at sentence boundaries.
   */
  public function splitIntoChunks(string $text, int $maxLength = self::CHUNK_SIZE): array {
    if ($maxLength < 1) {
      throw new \InvalidArgumentException('Chunk size must be at least one character.');
    }
    $sentences = preg_split('/(?<=[.!?])\s+/', $text, -1, PREG_SPLIT_NO_EMPTY);
    $chunks = [];
    $currentChunk = '';

    foreach ($sentences as $sentence) {
      // A single sentence (or text without punctuation) can exceed the limit.
      // Prefer word boundaries, but also bound unbroken and CJK text.
      if (mb_strlen($sentence) > $maxLength) {
        if ($currentChunk !== '') {
          $chunks[] = $currentChunk;
          $currentChunk = '';
        }
        while (mb_strlen($sentence) > $maxLength) {
          $prefix = mb_substr($sentence, 0, $maxLength);
          $boundary = mb_strrpos($prefix, ' ');
          $length = $boundary !== FALSE && $boundary > 0 ? $boundary : $maxLength;
          $chunks[] = mb_substr($sentence, 0, $length);
          $sentence = ltrim(mb_substr($sentence, $length));
        }
      }
      $candidate = $currentChunk === '' ? $sentence : $currentChunk . ' ' . $sentence;
      if (mb_strlen($candidate) <= $maxLength) {
        $currentChunk = $candidate;
      }
      else {
        if ($currentChunk !== '') {
          $chunks[] = $currentChunk;
        }
        $currentChunk = $sentence;
      }
    }

    if ($currentChunk !== '') {
      $chunks[] = $currentChunk;
    }

    return $chunks;
  }

  /**
   * Execute a command with timeout support.
   */
  public function execWithTimeout(string $command, int $timeout = 60): array {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    $env = NULL;
    $config = $this->configFactory->get('local_tts.settings');
    $espeakDataPath = $config->get('espeak_data_path');

    if ($espeakDataPath && is_dir($espeakDataPath)) {
      $espeakParent = dirname($espeakDataPath);
      $serverEnv = array_filter($_SERVER, 'is_string');
      $env = array_merge($serverEnv, [
        'ESPEAK_DATA_PATH' => $espeakParent,
        'PIPER_ESPEAKNG_DATA_DIRECTORY' => $espeakParent,
      ]);
    }

    $process = proc_open($command, $descriptors, $pipes, NULL, $env);

    if (!is_resource($process)) {
      return [
        'output' => ['Failed to start process'],
        'return_code' => -1,
        'timeout' => FALSE,
      ];
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], FALSE);
    stream_set_blocking($pipes[2], FALSE);

    $startTime = time();
    $output = '';
    $errorOutput = '';

    while (time() - $startTime < $timeout) {
      $status = proc_get_status($process);
      $output .= stream_get_contents($pipes[1]);
      $errorOutput .= stream_get_contents($pipes[2]);

      if (!$status['running']) {
        $output .= stream_get_contents($pipes[1]);
        $errorOutput .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return [
          'output' => array_filter(explode("\n", $output . $errorOutput)),
          'return_code' => $status['exitcode'],
          'timeout' => FALSE,
        ];
      }

      usleep(100000);
    }

    proc_terminate($process, 9);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return [
      'output' => array_filter(explode("\n", $output . $errorOutput)),
      'return_code' => -1,
      'timeout' => TRUE,
    ];
  }

  /**
   * Validate that the binary and model files exist and are usable.
   */
  protected function validateBinary(string $binaryPath, string $modelPath, string $dataPath): void {
    if (!file_exists($binaryPath)) {
      $this->logger->error('Koko binary not found at: @path', ['@path' => $binaryPath]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not found at: %s. Please run: composer install', $binaryPath));
    }

    if (!is_executable($binaryPath)) {
      $this->logger->error('Koko binary not executable at: @path', ['@path' => $binaryPath]);
      throw new TtsServiceUnavailableException(sprintf('TTS binary not executable at: %s. Please run: chmod +x %s', $binaryPath, $binaryPath));
    }

    if (!file_exists($modelPath)) {
      $this->logger->error('Model file not found at: @path', ['@path' => $modelPath]);
      throw new TtsServiceUnavailableException(sprintf('Model file not found at: %s. Please run: composer install', $modelPath));
    }
    if (!file_exists($dataPath)) {
      $this->logger->error('Data file not found at: @path', ['@path' => $dataPath]);
      throw new TtsServiceUnavailableException(sprintf('Data file not found at: %s. Please run: composer install', $dataPath));
    }
  }

  /**
   * Check server load and throw if it exceeds the configured threshold.
   */
  protected function checkServerLoad(mixed $config): void {
    $maxLoad = $config->get('max_server_load') ?? 2;
    if ($maxLoad > 0 && function_exists('sys_getloadavg')) {
      $load = sys_getloadavg();
      if ($load !== FALSE) {
        $currentLoad = (float) $load[0];
        if ($currentLoad > $maxLoad) {
          $this->logger->warning('TTS generation blocked due to high server load: current @current exceeds threshold @max', [
            '@current' => number_format($currentLoad, 2),
            '@max' => number_format($maxLoad, 2),
          ]);
          throw new TtsServiceUnavailableException('Server is experiencing high load. Audio generation is temporarily disabled to maintain performance.');
        }
      }
    }
  }

  /**
   * Generate audio for a single (short) text and transcode to OGG Opus.
   */
  protected function generateSingle(string $text, string $oggOutput, string $binaryPath, string $modelPath, string $dataPath, string $voice, float $speed, string $espeakLang, string $directory, int $timeout): void {
    $wavFile = $directory . '/' . md5($oggOutput) . '_' . bin2hex(random_bytes(8)) . '_single.wav';

    $command = sprintf(
      '%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
      escapeshellarg($binaryPath),
      escapeshellarg($espeakLang),
      escapeshellarg($modelPath),
      escapeshellarg($dataPath),
      escapeshellarg($voice),
      escapeshellarg((string) $speed),
      escapeshellarg($text),
      escapeshellarg($wavFile)
    );

    $this->logger->info('Executing TTS command for single text');

    try {
      $result = $this->execWithTimeout($command, $timeout);
      $this->handleExecResult($result, $wavFile, $timeout);
      $this->transcodeToOpus($wavFile, $oggOutput);
    }
    finally {
      if (file_exists($wavFile)) {
        @unlink($wavFile);
      }
    }
  }

  /**
   * Generate audio for long text by splitting into chunks.
   */
  protected function generateChunked(string $text, string $oggOutput, string $binaryPath, string $modelPath, string $dataPath, string $voice, float $speed, string $espeakLang, string $cacheKey, string $directory, int $timeout): void {
    $cacheKey .= '_' . bin2hex(random_bytes(8));
    $chunks = $this->splitIntoChunks($text);
    $chunkFiles = [];

    try {
      foreach ($chunks as $index => $chunkText) {
        $chunkWav = $directory . '/' . $cacheKey . '_chunk' . $index . '.wav';
        $chunkFiles[] = $chunkWav;

        $command = sprintf(
          '%s --lan %s --model %s --data %s --style %s --speed %s text %s --output %s 2>&1',
          escapeshellarg($binaryPath),
          escapeshellarg($espeakLang),
          escapeshellarg($modelPath),
          escapeshellarg($dataPath),
          escapeshellarg($voice),
          escapeshellarg((string) $speed),
          escapeshellarg($chunkText),
          escapeshellarg($chunkWav)
        );

        $this->logger->info('Generating chunk @i of @total', [
          '@i' => $index + 1,
          '@total' => count($chunks),
        ]);

        $result = $this->execWithTimeout($command, $timeout);
        $this->handleExecResult($result, $chunkWav, $timeout);
      }

      $this->concatAndTranscode($chunkFiles, $oggOutput, $directory, $cacheKey);
    }
    finally {
      foreach ($chunkFiles as $file) {
        if (file_exists($file)) {
          @unlink($file);
        }
      }
      $listFile = $directory . '/' . $cacheKey . '_concat.txt';
      if (file_exists($listFile)) {
        @unlink($listFile);
      }
    }
  }

  /**
   * Handle the result of a koko TTS execution.
   */
  protected function handleExecResult(array $result, string $outputFile, int $timeout): void {
    if ($result['return_code'] !== 0) {
      if ($result['timeout']) {
        $this->logger->error('Koko TTS timed out after @timeout seconds', ['@timeout' => $timeout]);
        throw new TtsTimeoutException(sprintf('TTS generation timed out after %d seconds', $timeout));
      }
      $this->logger->error('Koko TTS failed with return code @code. Output: @output', [
        '@code' => $result['return_code'],
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('TTS generation failed with exit code %d: %s', $result['return_code'], implode(' ', array_slice($result['output'], -3))));
    }

    if (!file_exists($outputFile)) {
      $this->logger->error('Audio file was not created at: @path', ['@path' => $outputFile]);
      throw new TtsServiceUnavailableException(sprintf('TTS audio file was not created at: %s', $outputFile));
    }
  }

  /**
   * Concatenate multiple WAV files and transcode to OGG Opus.
   */
  protected function concatAndTranscode(array $wavFiles, string $oggOutput, string $directory, string $cacheKey): void {
    $listFile = $directory . '/' . $cacheKey . '_concat.txt';
    $lines = [];
    foreach ($wavFiles as $path) {
      $escaped = str_replace("'", "'\\''", $path);
      $lines[] = "file '" . $escaped . "'";
    }
    file_put_contents($listFile, implode("\n", $lines));

    $partialOutput = $oggOutput . '.' . bin2hex(random_bytes(8)) . '.part';
    $command = sprintf(
      '%s -y -f concat -safe 0 -i %s -c:a libopus -b:a 48k -f ogg %s 2>&1',
      escapeshellarg($this->findFfmpeg()),
      escapeshellarg($listFile),
      escapeshellarg($partialOutput)
    );

    $result = $this->execWithTimeout($command, 60);

    if ($result['return_code'] !== 0) {
      @unlink($partialOutput);
      $this->logger->error('ffmpeg concat+transcode failed: @output', [
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('ffmpeg concat and transcode failed: %s', implode(' ', array_slice($result['output'], -3))));
    }

    $this->publishOutput($partialOutput, $oggOutput);
  }

  /**
   * Atomically move a .part file to its final .ogg path.
   */
  protected function publishOutput(string $partialOutput, string $oggOutput): void {
    if (!file_exists($partialOutput)) {
      throw new \RuntimeException(sprintf('ffmpeg did not produce output file: %s', $partialOutput));
    }
    if (!@rename($partialOutput, $oggOutput)) {
      @unlink($partialOutput);
      throw new \RuntimeException(sprintf('Could not move audio file into place: %s', $oggOutput));
    }
  }

  /**
   * Transcode a WAV file to OGG Opus and remove the source WAV.
   */
  protected function transcodeToOpus(string $wavPath, string $oggPath): void {
    $partialOutput = $oggPath . '.' . bin2hex(random_bytes(8)) . '.part';
    $command = sprintf(
      '%s -y -i %s -c:a libopus -b:a 48k -f ogg %s 2>&1',
      escapeshellarg($this->findFfmpeg()),
      escapeshellarg($wavPath),
      escapeshellarg($partialOutput)
    );

    $result = $this->execWithTimeout($command, 60);
    @unlink($wavPath);

    if ($result['return_code'] !== 0) {
      @unlink($partialOutput);
      $this->logger->error('ffmpeg transcode failed: @output', [
        '@output' => implode("\n", $result['output']),
      ]);
      throw new \RuntimeException(sprintf('ffmpeg transcode to OGG Opus failed: %s', implode(' ', array_slice($result['output'], -3))));
    }

    $this->publishOutput($partialOutput, $oggPath);
  }

}
