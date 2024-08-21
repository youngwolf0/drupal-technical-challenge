<?php

namespace Drupal\assessment_content_migration\Controller;

use DOMDocument;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\datetime\Plugin\Field\FieldType\DateTimeItemInterface;
use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Batch processing class for CSV content import.
 */
class CSVBatchProcessor {

  /**
   * Processes a single operation in the batch.
   *
   * @param string $file_uri
   *   The URI of the CSV file to process.
   * @param array $context
   *   The batch context array.
   */
  public static function processCSV($file_uri, array &$context) {
    if (!isset($context['sandbox']['file'])) {
      $context['sandbox']['file'] = fopen($file_uri, 'r');
      $context['sandbox']['total'] = count(file($file_uri)) - 1;
      $context['sandbox']['current'] = 0;

      fgetcsv($context['sandbox']['file']);

      // Preload existing legacy IDs so they can be updated.
      $context['sandbox']['existing_nodes'] = self::getExistingLegacyIds();
      $context['results']['imported'] = 0;
      $context['results']['updated'] = 0;
    }

    // Process rows until the end of the file or until a batch limit is reached.
    $batch_limit = 50;
    $processed = 0;

    while ($processed < $batch_limit && $row = fgetcsv($context['sandbox']['file'])) {
      // Check if the row has the expected number of columns
      if (count($row) !== 6) {
        \Drupal::messenger()->addMessage(t('The CSV file has an incorrect number of columns.'), 'error');
        $context['finished'] = 1;
        break;
      }

      // Mapping CSV columns to variables.
      $legacy_id = $row[0];
      $title = $row[1];
      $date = $row[2];
      $type = $row[3];
      $category_name = $row[4];
      $content = $row[5];

      if ($date == '14/21/2024') {
        $jimmy = 0;
      }

      // Validate the date format
      try {
        // Attempt to create the date object.
        $drupal_date = DrupalDateTime::createFromFormat('m/d/Y', $date);

        // If the date object wasn't created or doesn't match the expected format.
        if (!$drupal_date || $drupal_date->format('m/d/Y') !== $date) {
          throw new \Exception('Invalid date format');
        }

        // Set the timezone and format the date for storage.
        $drupal_date->setTimezone(new \DateTimeZone(DateTimeItemInterface::STORAGE_TIMEZONE));
        $formatted_date = $drupal_date->format(DateTimeItemInterface::DATE_STORAGE_FORMAT);

      } catch (\Exception $e) {
        \Drupal::messenger()->addMessage(t('Invalid date format on row @row: @date. Either it doesn\'t meet the required format (m/d/Y or 02/21/2022) OR the date is not a valid date at all.', [
          '@row' => $context['sandbox']['current'],
          '@date' => $date,
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      // Validate the data types.
      if (!is_numeric($legacy_id)) {
        \Drupal::messenger()->addMessage(t('The CSV file contains an invalid ID on row @row. It should be an number with no decimals.', [
          '@row' => $context['sandbox']['current']
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      if (!is_string($title) || empty($title)) {
        \Drupal::messenger()->addMessage(t('The CSV file contains an invalid title on row @row. It should not be left blank.', [
          '@row' => $context['sandbox']['current']
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      if (!$date) {
        \Drupal::messenger()->addMessage(t('The CSV file contains an invalid date format on row @row. It should be in the format m/d/Y (08/21/2020)', [
          '@row' => $context['sandbox']['current']
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      if (!is_string($category_name) || empty($category_name)) {
        \Drupal::messenger()->addMessage(t('The CSV file contains an invalid category on row @row.  It should not be left blank', [
          '@row' => $context['sandbox']['current']
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      if (!is_string($content)) {
        \Drupal::messenger()->addMessage(t('The CSV file contains an invalid content value on row @row.  It should not be left blank.', [
          '@row' => $context['sandbox']['current']
        ]), 'error');
        $context['finished'] = 1;
        break;
      }

      // Sanitize content to remove JavaScript.
      $content = self::sanitizeContent($content);

      // Handle term reference for the category.
      $category_tid = self::getOrCreateTerm($category_name, 'category');

      // Check if the legacy ID already exists.
      if (isset($context['sandbox']['existing_nodes'][$legacy_id])) {
        // Load the existing node and update it.
        $node = Node::load($context['sandbox']['existing_nodes'][$legacy_id]);
        $node->setTitle($title);
        $node->set('field_date', $formatted_date);
        $node->set('field_category', ['target_id' => $category_tid]);
        $node->set('body', [
          'value' => $content,
          'format' => 'full_html',
        ]);
        $context['results']['updated']++;
      } else {
        // Create a new node.
        $node = Node::create([
          'type' => $type,
          'title' => $title,
          'field_legacy_id' => $legacy_id,
          'field_date' => $formatted_date,
          'field_category' => ['target_id' => $category_tid],
          'body' => [
            'value' => $content,
            'format' => 'full_html',
          ],
        ]);
        $context['results']['imported']++;
      }

      // Save the node.
      $node->setPublished();
      $node->save();

      // Increment the counters.
      $context['sandbox']['current']++;
      $processed++;
    }

    // If we've reached the end of the file, mark the batch as finished.
    if ($context['sandbox']['current'] >= $context['sandbox']['total']) {
      fclose($context['sandbox']['file']);
      $context['finished'] = 1;
    }

    // Set a progress message.
    $context['message'] = t('Processed @current out of @total', [
      '@current' => $context['sandbox']['current'],
      '@total' => $context['sandbox']['total'],
    ]);
  }

  /**
   * Finished callback for the batch.
   *
   * @param bool $success
   *   TRUE if the batch process completed successfully.
   * @param array $results
   *   The results of the batch operations.
   * @param array $operations
   *   The operations that were processed.
   */
  public static function finishedCallback($success, array $results, array $operations) {
    if ($success) {
      \Drupal::messenger()->addMessage(t('The CSV import completed successfully. Imported @imported nodes, updated @updated nodes.', [
        '@imported' => $results['imported'],
        '@updated' => $results['updated'],
      ]));
    } else {
      \Drupal::messenger()->addMessage(t('The CSV import encountered errors.'), 'error');
    }
  }

  /**
   * Sanitize content by removing JavaScript and potentially empty tags.
   *
   * @param string $html
   *   The content to sanitize.
   *
   * @return string
   *   The sanitized content.
   */
  protected static function sanitizeContent($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $script = $dom->getElementsByTagName('script');
    $remove = [];
    foreach ($script as $item) {
      $remove[] = $item;
    }

    foreach ($remove as $item) {
      $item->parentNode->removeChild($item);
    }

    $html = $dom->saveHTML();
    // Since the js is wrapped in p tags I will remove them now.
    $html = preg_replace('/<p>\s*<\/p>/', '', $html);

    return $html;
  }

  /**
   * Get or create a term in the given vocabulary.
   *
   * @param string $term_name
   *   The name of the term.
   * @param string $vocabulary
   *   The machine name of the vocabulary.
   *
   * @return int
   *   The term ID.
   */
  protected static function getOrCreateTerm($term_name, $vocabulary) {
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'name' => $term_name,
      'vid' => $vocabulary,
    ]);
    $term = reset($term);

    if (!$term) {
      $term = Term::create([
        'vid' => $vocabulary,
        'name' => $term_name,
      ]);
      $term->save();
    }

    return $term->id();
  }

  /**
   * Get all existing legacy IDs with their corresponding node IDs.
   *
   * @return array
   *   An associative array mapping legacy IDs to local node IDs.
   */
  protected static function getExistingLegacyIds() {
    $query = \Drupal::database()->select('node__field_legacy_id', 'f');
    $query->join('node_field_data', 'n', 'f.entity_id = n.nid');
    $query->fields('f', ['field_legacy_id_value']);
    $query->fields('n', ['nid']);
    $results = $query->execute();

    $existing_nodes = [];
    foreach ($results as $record) {
      $existing_nodes[$record->field_legacy_id_value] = $record->nid;
    }

    return $existing_nodes;
  }
}
