<?php

namespace Drupal\Tests\local_tts\Unit;

use Drupal\local_tts\TtsService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Unit tests for TtsService.
 *
 * @coversDefaultClass \Drupal\local_tts\TtsService
 * @group local_tts
 */
class TtsServiceTest extends UnitTestCase {

  /**
   * The mocked config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $configFactory;

  /**
   * The mocked file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $fileSystem;

  /**
   * The mocked logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $loggerFactory;

  /**
   * The mocked logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $logger;

  /**
   * The mocked config object.
   *
   * @var \Drupal\Core\Config\ImmutableConfig|\PHPUnit\Framework\MockObject\MockObject
   */
  protected $config;

  /**
   * The TTS service under test.
   *
   * @var \Drupal\local_tts\TtsService
   */
  protected $ttsService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Mock config.
    $this->config = $this->createMock(ImmutableConfig::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->configFactory->method('get')
      ->with('local_tts.settings')
      ->willReturn($this->config);

    // Mock file system.
    $this->fileSystem = $this->createMock(FileSystemInterface::class);

    // Mock logger.
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->loggerFactory->method('get')
      ->with('local_tts')
      ->willReturn($this->logger);

    // Create service instance.
    $this->ttsService = new TtsService(
      $this->configFactory,
      $this->fileSystem,
      $this->loggerFactory
    );
  }

  /**
   * Tests getAvailableVoices returns expected array.
   *
   * @covers ::getAvailableVoices
   */
  public function testGetAvailableVoices() {
    $voices = $this->ttsService->getAvailableVoices();

    $this->assertIsArray($voices);
    $this->assertNotEmpty($voices);
    $this->assertArrayHasKey('af_sky', $voices);
    $this->assertArrayHasKey('am_adam', $voices);
    $this->assertArrayHasKey('bf_emma', $voices);
    $this->assertArrayHasKey('jf_alpha', $voices);
  }

  /**
   * Tests getAvailableVoices with language filter.
   *
   * @covers ::getAvailableVoices
   * @covers ::filterVoicesByLanguage
   */
  public function testGetAvailableVoicesWithLanguageFilter() {
    $en_voices = $this->ttsService->getAvailableVoices('en');
    $ja_voices = $this->ttsService->getAvailableVoices('ja');

    $this->assertNotEmpty($en_voices);
    $this->assertNotEmpty($ja_voices);

    // English voices should include American and British.
    $this->assertArrayHasKey('af_sky', $en_voices);
    $this->assertArrayHasKey('bf_emma', $en_voices);

    // Japanese voices should only include Japanese.
    $this->assertArrayHasKey('jf_alpha', $ja_voices);
    $this->assertArrayNotHasKey('af_sky', $ja_voices);
  }

  /**
   * Tests getSupportedLanguages returns expected languages.
   *
   * @covers ::getSupportedLanguages
   */
  public function testGetSupportedLanguages() {
    $languages = $this->ttsService->getSupportedLanguages();

    $this->assertIsArray($languages);
    $this->assertContains('en', $languages);
    $this->assertContains('ja', $languages);
    $this->assertContains('es', $languages);
    $this->assertContains('fr', $languages);
    $this->assertContains('zh', $languages);
  }

  /**
   * Tests voice validation throws exception for invalid voice.
   *
   * @covers ::generateSpeech
   */
  public function testInvalidVoiceThrowsException() {
    $this->config->method('get')->willReturnMap([
      ['max_text_length', 1000000],
      ['default_voice', 'af_sky'],
      ['default_speed', 1.0],
      ['cache_audio', TRUE],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid voice');

    $this->ttsService->generateSpeech('Test text', ['voice' => 'invalid_voice']);
  }

  /**
   * Tests speed validation throws exception for out of range speed.
   *
   * @covers ::generateSpeech
   * @dataProvider invalidSpeedProvider
   */
  public function testInvalidSpeedThrowsException($speed) {
    $this->config->method('get')->willReturnMap([
      ['max_text_length', 1000000],
      ['default_voice', 'af_sky'],
      ['default_speed', 1.0],
      ['cache_audio', TRUE],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('Invalid speed');

    $this->ttsService->generateSpeech('Test text', ['speed' => $speed]);
  }

  /**
   * Data provider for invalid speeds.
   */
  public function invalidSpeedProvider() {
    return [
      'too low' => [0.3],
      'too high' => [2.5],
      'negative' => [-1.0],
      'zero' => [0],
    ];
  }

  /**
   * Tests text length validation throws exception.
   *
   * @covers ::generateSpeech
   */
  public function testTextLengthValidation() {
    $this->config->method('get')->willReturnMap([
      ['max_text_length', 100],
      ['default_voice', 'af_sky'],
      ['default_speed', 1.0],
      ['cache_audio', TRUE],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('exceeds maximum');

    $long_text = str_repeat('a', 101);
    $this->ttsService->generateSpeech($long_text);
  }

  /**
   * Tests text length validation allows unlimited when set to 0.
   *
   * @covers ::generateSpeech
   */
  public function testUnlimitedTextLength() {
    $this->config->method('get')->willReturnMap([
      ['max_text_length', 0],
      ['default_voice', 'af_sky'],
      ['default_speed', 1.0],
      ['cache_audio', TRUE],
      ['audio_directory', 'public://local-tts'],
      ['koko_binary_path', '/fake/path'],
    ]);

    $this->fileSystem->method('realpath')->willReturn('/tmp/test');
    $this->fileSystem->method('prepareDirectory')->willReturn(TRUE);

    // Should not throw exception for long text when max_text_length is 0.
    $long_text = str_repeat('a', 100000);

    // We expect RuntimeException for missing binary, not
    // InvalidArgumentException for length.
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('binary');

    $this->ttsService->generateSpeech($long_text);
  }

  /**
   * Tests detectLanguageFromVoice correctly detects languages.
   *
   * @covers ::detectLanguageFromVoice
   * @dataProvider voiceLanguageProvider
   */
  public function testDetectLanguageFromVoice($voice, $expected_lang) {
    $reflection = new \ReflectionClass($this->ttsService);
    $method = $reflection->getMethod('detectLanguageFromVoice');
    $method->setAccessible(TRUE);

    $result = $method->invoke($this->ttsService, $voice);
    $this->assertEquals($expected_lang, $result);
  }

  /**
   * Data provider for voice language detection.
   */
  public function voiceLanguageProvider() {
    return [
      'American English' => ['af_sky', 'en'],
      'British English' => ['bf_emma', 'en-gb'],
      'Japanese' => ['jf_alpha', 'ja'],
      'Spanish' => ['ef_dora', 'es'],
      'French' => ['ff_siwis', 'fr'],
      'Chinese' => ['zf_xiaoni', 'zh'],
      'Hindi' => ['hf_alpha', 'hi'],
      'Italian' => ['if_sara', 'it'],
      'Portuguese' => ['pf_dora', 'pt'],
    ];
  }

  /**
   * Tests expandPath correctly expands tilde.
   *
   * @covers ::expandPath
   */
  public function testExpandPath() {
    $reflection = new \ReflectionClass($this->ttsService);
    $method = $reflection->getMethod('expandPath');
    $method->setAccessible(TRUE);

    // Test tilde expansion.
    $result = $method->invoke($this->ttsService, '~/.cache/test');
    $home = getenv('HOME');
    if ($home) {
      $this->assertEquals($home . '/.cache/test', $result);
    }

    // Test path without tilde.
    $result = $method->invoke($this->ttsService, '/absolute/path');
    $this->assertEquals('/absolute/path', $result);
  }

}
