<?php
/**
 * Script definitivo para corregir y aplicar traducciones de menús.
 */
use Drupal\Core\Database\Database;

$db = Database::getConnection();
$root = \Drupal::root();

// 1. Mapa maestro de correcciones para títulos en inglés (en)
$english_fixes = [
    5 => 'Explore',
    6 => 'Get started',
    15 => 'What is a dootronic?',
    19 => 'What is a dootrip?',
    22 => 'What is an edoovillage?',
    25 => 'What is a hub?',
    28 => 'Help about teams',
    31 => 'Help about the wiki',
    37 => 'Getting started',
    41 => 'Help about my account',
    43 => 'View',
    44 => 'View',
    45 => 'View',
    46 => 'View',
    47 => 'Index',
    49 => 'About Labdoo',
];

foreach ($english_fixes as $id => $title) {
    $db->update('menu_link_content_data')
        ->fields(['title' => $title, 'link__title' => $title])
        ->condition('id', $id)
        ->condition('langcode', 'en')
        ->execute();
    $db->update('menu_link_content_field_revision')
        ->fields(['title' => $title])
        ->condition('id', $id)
        ->condition('langcode', 'en')
        ->execute();
}

// 2. Cargar traducciones de los archivos .po
$languages = array_keys(\Drupal::languageManager()->getLanguages());
$translations = [];

foreach ($languages as $langcode) {
    if ($langcode === 'en') continue;
    // Intentar buscar en modules/custom/labdoo_translations/translations/
    $po_file = "$root/modules/custom/labdoo_translations/translations/labdoo_translations.$langcode.po";
    if (file_exists($po_file)) {
        $content = file_get_contents($po_file);
        preg_match_all('/msgid "([^"]+)"\s+msgstr "([^"]+)"/m', $content, $matches);
        for ($i = 0; $i < count($matches[1]); $i++) {
            $translations[$langcode][$matches[1][$i]] = $matches[2][$i];
        }
    }
}

// 3. Procesar cada elemento de menú original (en)
$query = $db->select('menu_link_content_data', 'm')
    ->fields('m')
    ->condition('langcode', 'en');
$originals = $query->execute()->fetchAll();

$count = 0;
foreach ($originals as $orig) {
    $id = $orig->id;
    $title_en = $orig->title;

    foreach ($languages as $langcode) {
        if ($langcode === 'en') continue;

        $translated_title = $translations[$langcode][$title_en] ?? $title_en;

        // Verificar si la traducción ya existe
        $exists = $db->select('menu_link_content_data', 'm')
            ->fields('m', ['id'])
            ->condition('id', $id)
            ->condition('langcode', $langcode)
            ->execute()->fetchField();

        if ($exists) {
            $db->update('menu_link_content_data')
                ->fields([
                    'title' => $translated_title,
                    'link__title' => $translated_title,
                    'link__uri' => $orig->link__uri,
                    'link__options' => $orig->link__options,
                    'external' => $orig->external,
                    'rediscover' => $orig->rediscover,
                    'weight' => $orig->weight,
                    'expanded' => $orig->expanded,
                    'parent' => $orig->parent,
                    'enabled' => $orig->enabled,
                    'menu_name' => $orig->menu_name,
                    'content_translation_status' => 1,
                    'default_langcode' => 0,
                ])
                ->condition('id', $id)
                ->condition('langcode', $langcode)
                ->execute();
        } else {
            $db->insert('menu_link_content_data')
                ->fields([
                    'id' => $id,
                    'revision_id' => $orig->revision_id,
                    'bundle' => 'menu_link_content',
                    'langcode' => $langcode,
                    'title' => $translated_title,
                    'description' => NULL,
                    'menu_name' => $orig->menu_name,
                    'link__uri' => $orig->link__uri,
                    'link__title' => $translated_title,
                    'link__options' => $orig->link__options,
                    'external' => $orig->external,
                    'rediscover' => $orig->rediscover,
                    'weight' => $orig->weight,
                    'expanded' => $orig->expanded,
                    'parent' => $orig->parent,
                    'enabled' => $orig->enabled,
                    'changed' => time(),
                    'default_langcode' => 0,
                    'content_translation_source' => 'en',
                    'content_translation_outdated' => 0,
                    'content_translation_uid' => 1,
                    'content_translation_status' => 1,
                    'content_translation_created' => time(),
                ])
                ->execute();
        }

        // Sincronizar tabla de revisiones
        $rev_exists = $db->select('menu_link_content_field_revision', 'm')
            ->fields('m', ['id'])
            ->condition('id', $id)
            ->condition('langcode', $langcode)
            ->execute()->fetchField();

        if ($rev_exists) {
            $db->update('menu_link_content_field_revision')
                ->fields([
                    'title' => $translated_title,
                    'link__uri' => $orig->link__uri,
                    'link__title' => $translated_title,
                    'link__options' => $orig->link__options,
                    'enabled' => $orig->enabled,
                    'default_langcode' => 0,
                    'content_translation_status' => 1,
                ])
                ->condition('id', $id)
                ->condition('langcode', $langcode)
                ->execute();
        } else {
            $db->insert('menu_link_content_field_revision')
                ->fields([
                    'id' => $id,
                    'revision_id' => $orig->revision_id,
                    'langcode' => $langcode,
                    'enabled' => $orig->enabled,
                    'title' => $translated_title,
                    'description' => NULL,
                    'link__uri' => $orig->link__uri,
                    'link__title' => $translated_title,
                    'link__options' => $orig->link__options,
                    'external' => $orig->external,
                    'changed' => time(),
                    'default_langcode' => 0,
                    'content_translation_source' => 'en',
                    'content_translation_outdated' => 0,
                    'content_translation_uid' => 1,
                    'content_translation_status' => 1,
                    'content_translation_created' => time(),
                ])
                ->execute();
        }
    }
    $count++;
}

echo "Procesados $count elementos de menú en todos los idiomas.\n";
