<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\labdoo_migrate\Traits\TextFormatMapperTrait;

/** Pure story projection; saves only changed paragraphs and never deletes history. */
final class StorySectionAdapter {
  use TextFormatMapperTrait;

  public function desired(array $snapshot, int $nid): array {
    if (!array_key_exists('story_sections', $snapshot)) {
      throw new \RuntimeException('Reader does not cover story field collections.');
    }
    $out = [];
    foreach ($snapshot['story_sections'][$nid] ?? [] as $section) {
      $heading = $section['heading'] ?? [];
      $text = $section['text'] ?? [];
      $picture = $section['picture'] ?? [];
      $items = [];
      if (!empty($picture['field_story_picture_fid'])) {
        $file = $snapshot['files'][(int) $picture['field_story_picture_fid']] ?? NULL;
        if (empty($file['uri'])) { throw new \RuntimeException('Missing story picture metadata.'); }
        $items[] = (new ManagedFileIdentity(\Drupal::database()))->project($file) + ['alt' => $picture['field_story_picture_alt'] ?? '', 'title' => $picture['field_story_picture_title'] ?? ''];
      }
      $out[] = [
        'field_story_heading' => !empty($heading['field_story_heading_value']) ? [['value' => $heading['field_story_heading_value']]] : [],
        'field_story_text' => isset($text['field_story_text_value']) && $text['field_story_text_value'] !== '' ? [['value' => $text['field_story_text_value'], 'format' => $this->mapFormat($text['field_story_text_format'] ?? NULL)]] : [],
        'field_story_picture' => $items,
      ];
    }
    return $out;
  }

  public function actual(FieldableEntityInterface $paragraph): array {
    if ($paragraph->bundle() !== 'story_section') { throw new \RuntimeException('Unexpected story paragraph bundle.'); }
    $result = [];
    foreach (['field_story_heading' => ['value'], 'field_story_text' => ['value', 'format'], 'field_story_picture' => ['target_id', 'alt', 'title']] as $name => $properties) {
      if (!$paragraph->hasField($name)) { throw new \RuntimeException('Missing story paragraph field.'); }
      $result[$name] = [];
      foreach ($paragraph->get($name) as $item) {
        if ($item->isEmpty()) { continue; }
        $values = [];
        foreach ($properties as $property) { $values[$property] = (string) ($item->getValue()[$property] ?? ''); }
        $result[$name][] = $values;
      }
    }
    return $result;
  }

  private function canonical(array $value): string {
    array_walk_recursive($value, static function (&$v): void { $v = (string) $v; });
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
  }

  public function equal(FieldableEntityInterface $node, array $desired): bool {
    if (!$node->hasField('field_story_section')) { return FALSE; }
    $actual = [];
    foreach ($node->get('field_story_section') as $item) {
      $actual[] = $this->actual(ReadOnlyEntityLoader::paragraph($item));
    }
    return $this->canonical($actual) === $this->canonical($desired);
  }

  /** Source-wins only; unchanged sections retain both paragraph ID and revision. */
  public function apply(FieldableEntityInterface $node, array $desired): void {
    $storage = \Drupal::entityTypeManager()->getStorage('paragraph');
    $old = []; $used = [];
    foreach ($node->get('field_story_section') as $item) {
      $old[] = ReadOnlyEntityLoader::paragraph($item);
    }
    $references = []; $matched = [];
    // Reserve all unchanged sections before reusing slots for changed sections.
    foreach ($desired as $delta => $fields) {
      foreach ($old as $index => $candidate) {
        if (!isset($used[$index]) && $this->canonical($this->actual($candidate)) === $this->canonical($fields)) {
          $matched[$delta] = $candidate; $used[$index] = TRUE; break;
        }
      }
    }
    foreach ($desired as $delta => $fields) {
      $paragraph = $matched[$delta] ?? NULL;
      if (!$paragraph) {
        if (isset($old[$delta]) && !isset($used[$delta])) {
          $paragraph = clone $old[$delta]; $used[$delta] = TRUE;
          $paragraph->setNewRevision(TRUE);
        }
        else { $paragraph = $storage->create(['type' => 'story_section', 'langcode' => $node->language()->getId()]); }
        foreach ($fields as $name => $items) {
          if ($name === 'field_story_picture') {
            foreach ($items as &$item) { $item = (new ManagedFileIdentity(\Drupal::database()))->materialize($item); }
            unset($item);
          }
          $paragraph->set($name, $items);
        }
        $violations = $paragraph->validate();
        if ($violations->count()) { throw new \RuntimeException('Story paragraph fails validation.'); }
        $paragraph->save();
      }
      $references[] = ['target_id' => $paragraph->id(), 'target_revision_id' => $paragraph->getRevisionId()];
    }
    $node->set('field_story_section', $references);
    // Removed paragraphs remain available to historical node revisions.
  }
}
