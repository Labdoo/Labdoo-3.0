<?php
/**
 * Migrate interface translations from Drupal 7 to Drupal 10.
 * Copy data from locales_source and locales_target (D7) to locales_source and locales_target (D10).
 * Adapted for: locales_source (lid, source, context, version), locales_target (lid, translation, language, customized).
 * Discover languages dynamically and handle lid conflicts.
 */

// Database configuration
$d7_db = [
  'host' => 'localhost',
  'database' => 'labdoo',
  'username' => 'root',
  'password' => 'root'
];

$d10_db = [
  'host' => 'localhost',
  'database' => 'labdoo3',
  'username' => 'root',
  'password' => 'root'
];

// Default value for the 'version' column in locales_source
$default_version = '10.0.0';

// Function to connect to a database
function connect_db($config) {
  $conn = new mysqli(
    $config['host'],
    $config['username'],
    $config['password'],
    $config['database']
  );

  if ($conn->connect_error) {
    die("Error de conexión a {$config['database']}: " . $conn->connect_error);
  }

  $conn->set_charset('utf8mb4');
  return $conn;
}

// Function to log messages
function log_message($message) {
  echo date('Y-m-d H:i:s') . " - $message\n";
}

// Connect to the databases
log_message("Conectando a la base de datos de Drupal 7...");
$d7_conn = connect_db($d7_db);

log_message("Conectando a la base de datos de Drupal 10...");
$d10_conn = connect_db($d10_db);

// Step 0: Extract available languages from Drupal 10
log_message("Extrayendo idiomas disponibles de Drupal 10...");
$valid_languages = [];
$query = "SELECT DISTINCT language FROM locales_target";
$result = $d10_conn->query($query);

if ($result) {
  while ($row = $result->fetch_assoc()) {
    $valid_languages[] = $row['language'];
  }
} else {
  log_message("Advertencia: No se pudieron extraer idiomas de locales_target: " . $d10_conn->error);
}

if (empty($valid_languages)) {
  log_message("Advertencia: No se encontraron idiomas en locales_target. Usando idiomas por defecto.");
  $valid_languages = ['es', 'en']; // Fallback
}

log_message("Idiomas disponibles: " . implode(', ', $valid_languages));

// Step 1: Extract locales_source from Drupal 7
log_message("Extrayendo locales_source de Drupal 7...");
$source_strings = [];
$query = "SELECT lid, source, context FROM locales_source";
$result = $d7_conn->query($query);

if (!$result) {
  die("Error al extraer locales_source: " . $d7_conn->error);
}

while ($row = $result->fetch_assoc()) {
  $source_strings[$row['lid']] = [
    'source' => $row['source'],
    'context' => $row['context'] ?? ''
  ];
}

log_message("Se encontraron " . count($source_strings) . " cadenas fuente.");

// Step 2: Extract locales_target from Drupal 7
log_message("Extrayendo locales_target de Drupal 7...");
$target_strings = [];
$query = "SELECT lid, language, translation FROM locales_target";
$result = $d7_conn->query($query);

if (!$result) {
  die("Error al extraer locales_target: " . $d7_conn->error);
}

while ($row = $result->fetch_assoc()) {
  if (!in_array($row['language'], $valid_languages)) {
    log_message("Idioma no soportado: {$row['language']} para lid {$row['lid']}");
    continue;
  }
  $target_strings[] = [
    'lid' => $row['lid'],
    'language' => $row['language'],
    'translation' => $row['translation']
  ];
}

log_message("Se encontraron " . count($target_strings) . " traducciones.");

// Step 3: Migrate locales_source to Drupal 10
log_message("Migrando locales_source a Drupal 10...");
$source_inserted = 0;
$source_skipped = 0;
$lid_mapping = []; // Map D7 lid to D10 lid

foreach ($source_strings as $d7_lid => $string) {
  $source = $d10_conn->real_escape_string($string['source']);
  $context = $d10_conn->real_escape_string($string['context']);
  $version = $d10_conn->real_escape_string($default_version);

  // Check whether the string already exists by source and context
  $check_query = "
        SELECT lid FROM locales_source
        WHERE source = '$source' AND context = '$context'
    ";
  $check_result = $d10_conn->query($check_query);

  if ($check_result && $check_result->num_rows > 0) {
    $row = $check_result->fetch_assoc();
    log_message("Cadena existente: '$source' ($context), usando lid {$row['lid']}");
    $lid_mapping[$d7_lid] = $row['lid'];
    $source_skipped++;
    continue;
  }

  // Check whether the lid is already in use
  $lid_check_query = "SELECT COUNT(*) as count FROM locales_source WHERE lid = $d7_lid";
  $lid_check_result = $d10_conn->query($lid_check_query);
  $lid_row = $lid_check_result->fetch_assoc();

  $new_lid = $d7_lid;
  if ($lid_row['count'] > 0) {
    // Generate a new lid
    $max_lid_query = "SELECT MAX(lid) as max_lid FROM locales_source";
    $max_lid_result = $d10_conn->query($max_lid_query);
    $max_lid_row = $max_lid_result->fetch_assoc();
    $new_lid = $max_lid_row['max_lid'] + 1;
    log_message("lid $d7_lid ya existe, usando nuevo lid $new_lid para '$source'");
  }

  // Insert the string
  $insert_query = "
        INSERT INTO locales_source (lid, source, context, version)
        VALUES ($new_lid, '$source', '$context', '$version')
    ";

  if ($d10_conn->query($insert_query)) {
    $lid_mapping[$d7_lid] = $new_lid;
    $source_inserted++;
  } else {
    log_message("Error al insertar '$source' ($context): " . $d10_conn->error);
  }
}

log_message("locales_source: $source_inserted insertadas, $source_skipped saltadas.");

// Step 4: Migrate locales_target to Drupal 10
log_message("Migrando locales_target a Drupal 10...");
$target_inserted = 0;
$target_skipped = 0;

foreach ($target_strings as $string) {
  $d7_lid = (int)$string['lid'];
  $language = $d10_conn->real_escape_string($string['language']);
  $translation = $d10_conn->real_escape_string($string['translation']);
  $customized = 1;

  // Use the mapped lid
  if (!isset($lid_mapping[$d7_lid])) {
    log_message("Saltando traducción para lid $d7_lid: no mapeado en locales_source.");
    $target_skipped++;
    continue;
  }
  $d10_lid = $lid_mapping[$d7_lid];

  // Check whether the translation already exists
  $check_query = "
        SELECT COUNT(*) as count
        FROM locales_target
        WHERE lid = $d10_lid AND language = '$language'
    ";
  $check_result = $d10_conn->query($check_query);
  $row = $check_result->fetch_assoc();

  if ($row['count'] > 0) {
    log_message("Saltando traducción existente para lid $d10_lid ($language)");
    $target_skipped++;
    continue;
  }

  // Insert the translation
  $insert_query = "
        INSERT INTO locales_target (lid, translation, language, customized)
        VALUES ($d10_lid, '$translation', '$language', $customized)
    ";

  if ($d10_conn->query($insert_query)) {
    $target_inserted++;
  } else {
    log_message("Error al insertar traducción para lid $d10_lid ($language): " . $d10_conn->error);
  }
}

log_message("locales_target: $target_inserted insertadas, $target_skipped saltadas.");

// Step 5: Close the connections
$d7_conn->close();
$d10_conn->close();

log_message("Migración finalizada.");
