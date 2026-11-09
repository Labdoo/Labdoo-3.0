<?php

namespace Drupal\labdoo_migrate\Services\Incremental;

use Drupal\Core\Database\Connection;

/** SELECT-only identity of generated galleries through reciprocal source owners. */
final class GeneratedIdentityResolver {
  private const FORWARD = ['edoovillage' => 'field_edoovillage_photo_album', 'hub' => 'field_hub_photo_album'];
  private const REVERSE = ['edoovillage' => 'field_photo_album_edoovillage', 'hub' => 'field_photo_album_hub'];

  public function __construct(private Connection $source, private Connection $destination) {}

  /** Bound every result set; never turn truncation into apparently unique proof. */
  private function rows($query, ?string $key = NULL): array {
    $rows = [];
    $count = 0;
    foreach ($query->range(0, 10001)->execute() as $row) {
      if (++$count > 10000) { throw new \RuntimeException('Gallery identity result set exceeds bounded capacity.'); }
      if ($key === NULL) { $rows[] = $row; }
      else {
        $id = $row->$key;
        if (isset($rows[$id])) { throw new \RuntimeException('Duplicate gallery identity key.'); }
        $rows[$id] = (array) $row;
      }
    }
    return $rows;
  }

  public function galleries(array $ids): array {
    if (count($ids) > 500) { throw new \InvalidArgumentException('Gallery identity batches are limited to500 IDs.'); }
    foreach ($ids as $id) { if ((!is_int($id) && !(is_string($id) && ctype_digit($id))) || (int) $id < 1) { throw new \InvalidArgumentException('Gallery IDs must be positive integers.'); } }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $out = array_fill_keys($ids, ['id' => NULL, 'conflict' => 'source_gallery_missing', 'proof' => []]);
    $galleries = $this->rows($this->source->select('node', 'n')->fields('n', ['nid', 'type', 'vid'])->condition('nid', $ids, 'IN'), 'nid');
    $reverse = $ownerIds = $badGallery = [];
    foreach (self::REVERSE as $type => $field) {
      $query = $this->source->select('field_data_' . $field, 'f')->fields('f', ['entity_id', 'revision_id', $field . '_target_id'])->condition('entity_type', 'node')->condition('bundle', 'node_gallery_gallery')->condition('deleted', 0)->condition('entity_id', $ids, 'IN');
      foreach ($this->rows($query) as $row) {
        if ((int) $row->revision_id !== (int) ($galleries[(int) $row->entity_id]['vid'] ?? -1)) { $badGallery[(int) $row->entity_id] = TRUE; }
        $owner = (int) $row->{$field . '_target_id'};
        if ($owner > 0) { $reverse[(int) $row->entity_id][$type][$owner] = $owner; $ownerIds[$owner] = $owner; }
      }
    }
    if (!$ownerIds) { foreach ($galleries as $id => $row) { $out[$id]['conflict'] = 'source_gallery_owner_missing'; } return $out; }
    $owners = $this->rows($this->source->select('node', 'n')->fields('n', ['nid', 'type', 'tnid', 'vid'])->condition('nid', array_values($ownerIds), 'IN'), 'nid');
    $rootIds = [];
    foreach ($owners as $id => $row) { $root = (int) $row['tnid'] ?: (int) $id; $rootIds[$root] = $root; }
    if (!$rootIds) { return $out; }
    $query = $this->source->select('node', 'n')->fields('n', ['nid', 'type', 'tnid', 'vid']);
    $query->condition($query->orConditionGroup()->condition('nid', array_values($rootIds), 'IN')->condition('tnid', array_values($rootIds), 'IN'))->range(0, 10001);
    $family = $this->rows($query, 'nid');
    if (count($family) > 10000) { throw new \RuntimeException('Gallery owner families exceed bounded identity capacity.'); }
    $canonical = static function (int $id) use ($family): ?int {
      $row = $family[$id] ?? NULL;
      if (!$row || !isset(self::FORWARD[$row['type']])) { return NULL; }
      $root = (int) $row['tnid'] ?: $id;
      $r = $family[$root] ?? NULL;
      return $r && $r['type'] === $row['type'] && in_array((int) $r['tnid'], [0, $root], TRUE) ? $root : NULL;
    };
    $forward = [];
    foreach (self::FORWARD as $type => $field) {
      if (!$family) { continue; }
      $query = $this->source->select('field_data_' . $field, 'f')->fields('f', ['entity_id', 'revision_id', $field . '_target_id'])->condition('entity_type', 'node')->condition('bundle', $type)->condition('deleted', 0)->condition('entity_id', array_keys($family), 'IN');
      foreach ($this->rows($query) as $row) {
        $owner = $canonical((int) $row->entity_id);
        $gallery = (int) $row->{$field . '_target_id'};
        if ((int) $row->revision_id !== (int) ($family[(int) $row->entity_id]['vid'] ?? -1)) { $badGallery[$gallery] = TRUE; }
        if ($owner && $gallery > 0 && ($family[(int) $row->entity_id]['type'] ?? NULL) === $type) { $forward[$owner][$gallery] = $gallery; }
      }
    }
    // Forward references outside the reverse-owner family are contradictory
    // evidence, not a license to attach another school's gallery.
    $globalForward = [];
    foreach (self::FORWARD as $type => $field) {
      $query = $this->source->select('field_data_' . $field, 'f')->fields('f', ['entity_id', 'revision_id', $field . '_target_id']);
      $query->leftJoin('node', 'n', 'n.nid=f.entity_id');
      $query->fields('n', ['type', 'vid']);
      $query->leftJoin('node', 'r', 'r.nid=CASE WHEN n.tnid=0 THEN n.nid ELSE n.tnid END');
      $query->addField('r', 'nid', 'root_id'); $query->addField('r', 'type', 'root_type'); $query->addField('r', 'tnid', 'root_tnid');
      $query->condition('f.entity_type', 'node')->condition('f.bundle', $type)->condition('f.deleted', 0)->condition('f.' . $field . '_target_id', $ids, 'IN')->range(0, 10001);
      $count = 0;
      foreach ($this->rows($query) as $row) {
        if (++$count > 10000) { throw new \RuntimeException('Gallery forward references exceed bounded capacity.'); }
        $root = (int) $row->root_id;
        if (!$root || $row->type !== $type || $row->root_type !== $type || !in_array((int) $row->root_tnid, [0, $root], TRUE) || (int) $row->revision_id !== (int) $row->vid) { $root = 0; }
        $globalForward[(int) $row->{$field . '_target_id'}][$root] = $root;
      }
    }
    $matches = $badOwner = [];
    if ($this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $query = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['source_id', 'destination_id', 'bundle'])->condition('entity_type', 'node')->condition('source_id', array_values($rootIds), 'IN');
      foreach ($this->rows($query) as $row) {
        if (($family[(int) $row->source_id]['type'] ?? NULL) !== $row->bundle) { $badOwner[(int) $row->source_id] = TRUE; continue; }
        $matches[(int) $row->source_id][(int) $row->destination_id] = (int) $row->destination_id;
      }
    }
    if ($this->destination->schema()->tableExists('node__field_d7_nid')) {
      $query = $this->destination->select('node__field_d7_nid', 'f')->fields('f', ['entity_id', 'field_d7_nid_value']);
      $query->innerJoin('node', 'n', 'n.nid=f.entity_id'); $query->fields('n', ['type'])->condition('deleted', 0)->condition('field_d7_nid_value', array_values($rootIds), 'IN');
      foreach ($this->rows($query) as $row) {
        if (($family[(int) $row->field_d7_nid_value]['type'] ?? NULL) !== $row->type) { $badOwner[(int) $row->field_d7_nid_value] = TRUE; continue; }
        $matches[(int) $row->field_d7_nid_value][(int) $row->entity_id] = (int) $row->entity_id;
      }
    }
    $destIds = [];
    foreach ($matches as $list) { $destIds += $list; }
    $destOwners = $destIds ? $this->rows($this->destination->select('node', 'n')->fields('n', ['nid', 'type'])->condition('nid', array_values($destIds), 'IN'), 'nid') : [];
    // A shared target owner must not silently collapse unrelated source owners.
    if ($destIds && $this->destination->schema()->tableExists('labdoo_migrate_tracking')) {
      $query = $this->destination->select('labdoo_migrate_tracking', 't')->fields('t', ['source_id', 'destination_id'])->condition('entity_type', 'node')->condition('bundle', array_keys(self::FORWARD), 'IN')->condition('destination_id', array_values($destIds), 'IN');
      foreach ($this->rows($query) as $row) {
        foreach ($matches as $owner => $list) { if (isset($list[(int) $row->destination_id]) && $canonical((int) $row->source_id) !== (int) $owner) { $badOwner[$owner] = TRUE; } }
      }
    }
    $candidates = $parentSets = [];
    if ($destIds) {
      $query = $this->destination->select('node__field_parent', 'p')->fields('p', ['entity_id', 'field_parent_target_id']);
      $query->innerJoin('node', 'n', "n.nid=p.entity_id AND n.type='gallery'");
      $query->condition('p.deleted', 0)->condition('p.bundle', 'gallery')->condition('p.field_parent_target_id', array_values($destIds), 'IN');
      foreach ($this->rows($query) as $row) { $candidates[(int) $row->field_parent_target_id][(int) $row->entity_id] = (int) $row->entity_id; }
      $candidateIds = []; foreach ($candidates as $list) { $candidateIds += $list; }
      if ($candidateIds) {
        foreach ($this->rows($this->destination->select('node__field_parent', 'p')->fields('p', ['entity_id', 'field_parent_target_id'])->condition('deleted', 0)->condition('entity_id', array_values($candidateIds), 'IN')) as $row) { $parentSets[(int) $row->entity_id][(int) $row->field_parent_target_id] = (int) $row->field_parent_target_id; }
      }
    }
    foreach ($ids as $id) {
      if (!isset($galleries[$id]) || $galleries[$id]['type'] !== 'node_gallery_gallery') { $out[$id]['conflict'] = 'source_gallery_type_missing_or_wrong'; continue; }
      if (isset($badGallery[$id])) { $out[$id]['conflict'] = 'source_gallery_field_revision_mismatch'; continue; }
      $roots = [];
      foreach ($reverse[$id] ?? [] as $type => $list) {
        foreach ($list as $rawOwner) {
          $owner = $canonical($rawOwner);
          if (!$owner || ($family[$rawOwner]['type'] ?? NULL) !== $type) { $roots[0] = 0; }
          else { $roots[$owner] = $owner; }
        }
      }
      if (count($roots) !== 1 || isset($roots[0])) { $out[$id]['conflict'] = 'source_gallery_owner_not_unique'; continue; }
      $owner = reset($roots);
      if (array_values($globalForward[$id] ?? []) !== [$owner]) { $out[$id]['conflict'] = 'source_gallery_forward_owner_conflict'; continue; }
      if (array_values($forward[$owner] ?? []) !== [$id]) { $out[$id]['conflict'] = 'source_gallery_owner_not_reciprocal'; continue; }
      $list = $matches[$owner] ?? [];
      if (isset($badOwner[$owner]) || count($list) !== 1) { $out[$id]['conflict'] = 'source_gallery_owner_identity_unproven'; continue; }
      $dest = reset($list);
      if (($destOwners[$dest]['type'] ?? NULL) !== $family[$owner]['type']) { $out[$id]['conflict'] = 'destination_owner_missing_or_wrong_bundle'; continue; }
      $list = $candidates[$dest] ?? [];
      if (count($list) !== 1) { $out[$id]['conflict'] = 'destination_gallery_not_unique'; continue; }
      $gallery = reset($list);
      if (array_values($parentSets[$gallery] ?? []) !== [$dest]) { $out[$id]['conflict'] = 'destination_gallery_multiple_parents'; continue; }
      $out[$id] = ['id' => $gallery, 'conflict' => NULL, 'proof' => ['kind' => 'reciprocal_source_gallery_owner', 'source_gallery' => $id, 'source_owner' => $owner, 'owner_bundle' => $family[$owner]['type'], 'destination_owner' => $dest, 'destination_gallery' => $gallery]];
    }
    return $out;
  }
}
