/**
 * @file
 * Provides preview functionality for AI TTS batch form.
 */

(function ($, Drupal) {
  'use strict';

  /**
   * Behavior for AI TTS batch form preview.
   */
  Drupal.behaviors.aiTtsBatchPreview = {
    attach: function (context, settings) {
      var $form = $('form.ai-tts-batch-form', context).once('batch-preview');

      if ($form.length === 0) {
        return;
      }

      // Update preview when options change.
      $form.find('input, select').on('change', function () {
        updatePreview();
      });

      /**
       * Update the preview section with estimates.
       */
      function updatePreview() {
        var bundles = $('input[name^="entity_bundles"]:checked').length;
        var batchSize = parseInt($('select[name="batch_size"]').val());
        var delay = parseInt($('select[name="inter_batch_delay"]').val());
        var limit = parseInt($('input[name="limit"]').val()) || 0;
        var forceRefresh = $('input[name="force_refresh"]').is(':checked');
        var enableLoadCheck = $('input[name="enable_load_check"]').is(':checked');
        var loadThreshold = parseFloat($('input[name="max_load_threshold"]').val()) || 0;
        var enableDateFilter = $('input[name="enable_date_filter"]').is(':checked');
        var updatedAfter = $('input[name="updated_after"]').val();

        if (bundles === 0) {
          $('#batch-preview').html('<p>' + Drupal.t('Select at least one content type to see estimate.') + '</p>');
          return;
        }

        // Rough estimate: 5-15 seconds per entity.
        var estimatedEntities = limit > 0 ? limit : bundles * 100; // Assume 100 per bundle
        var avgSecondsPerEntity = 10; // Average
        var totalTime = estimatedEntities * avgSecondsPerEntity;
        var batchCount = Math.ceil(estimatedEntities / batchSize);
        var delayTime = (batchCount - 1) * delay;
        var totalMinutes = Math.round((totalTime + delayTime) / 60);

        var html = '<dl class="batch-preview-stats">';
        html += '<dt>' + Drupal.t('Estimated entities:') + '</dt><dd>~' + estimatedEntities + '</dd>';

        if (enableDateFilter && updatedAfter) {
          html += '<dt>' + Drupal.t('Date filter:') + '</dt><dd>' + Drupal.t('Updated after @date', {'@date': updatedAfter}) + '</dd>';
        }

        html += '<dt>' + Drupal.t('Number of batches:') + '</dt><dd>' + batchCount + '</dd>';
        html += '<dt>' + Drupal.t('Batch size:') + '</dt><dd>' + batchSize + ' ' + Drupal.t('entities/batch') + '</dd>';
        html += '<dt>' + Drupal.t('Inter-batch delay:') + '</dt><dd>' + delay + ' ' + Drupal.t('seconds') + '</dd>';
        html += '<dt>' + Drupal.t('Est. processing time:') + '</dt><dd>~' + totalMinutes + ' ' + Drupal.t('minutes') + '</dd>';

        if (enableLoadCheck) {
          html += '<dt>' + Drupal.t('Load threshold:') + '</dt><dd>' + loadThreshold + '</dd>';
        }
        else {
          html += '<dt>' + Drupal.t('Load checking:') + '</dt><dd><strong>' + Drupal.t('DISABLED') + '</strong></dd>';
        }

        html += '</dl>';

        // Add warnings.
        if (totalMinutes > 60) {
          html += '<div class="messages messages--warning">';
          html += '<strong>' + Drupal.t('Large batch detected.') + '</strong> ';
          html += Drupal.t('Consider running during off-peak hours to avoid server load issues.');
          html += '</div>';
        }

        if (forceRefresh) {
          html += '<div class="messages messages--warning">';
          html += '<strong>' + Drupal.t('Force regeneration enabled.') + '</strong> ';
          html += Drupal.t('This will regenerate all audio files, even if they already exist.');
          html += '</div>';
        }

        if (!enableLoadCheck) {
          html += '<div class="messages messages--error">';
          html += '<strong>' + Drupal.t('Server load checking is disabled!') + '</strong> ';
          html += Drupal.t('This may cause server performance issues. Only disable if you know what you\'re doing.');
          html += '</div>';
        }

        $('#batch-preview').html(html);
      }

      // Initial update.
      updatePreview();
    }
  };

})(jQuery, Drupal);
