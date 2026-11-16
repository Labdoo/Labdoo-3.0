<?php
/**
 * Extract strings from Drupal 7 and generate *.po files for each detected language.
 *
 * Requirements:
 *   - PHP with the mysqli extension enabled.
 *   - Read access to the Drupal 7 database.
 *
 * Adjust the connection settings in $d7_db as needed.
 */

// Drupal 7 database configuration
$d7_db = [
  'host' => 'localhost',
  'database' => 'labdoo',
  'username' => 'root',
  'password' => 'root'
];

// Output path for .po files (the directory must exist and be writable)
$output_path = __DIR__ . '/po_outputs';

// Connect to the Drupal 7 database
function conectarDrupal7($config) {
  $conn = new mysqli(
    $config['host'],
    $config['username'],
    $config['password'],
    $config['database']
  );
  if ($conn->connect_error) {
    die("Error de conexión a Drupal 7: " . $conn->connect_error);
  }
  $conn->set_charset('utf8mb4');
  return $conn;
}

// Create the output directory if it does not exist
if (!is_dir($output_path)) {
  mkdir($output_path, 0777, true);
}

// Connect
$conn = conectarDrupal7($d7_db);

/**
 * 1. Extract source strings from locales_source
 */
$fuentes = [];
$sqlFuentes = "SELECT lid, source, context FROM locales_source";
$resultFuentes = $conn->query($sqlFuentes);
if (!$resultFuentes) {
  die("Error al consultar locales_source: " . $conn->error);
}

while ($row = $resultFuentes->fetch_assoc()) {
  $fuentes[$row['lid']] = [
    'source'  => $row['source'],
    'context' => $row['context'] ?? '',
  ];
}
$resultFuentes->free();

/**
 * 2. Extract translations from locales_target
 *    Store everything in an array grouped by language.
 */
$traduccionesPorIdioma = [];
$sqlTraducciones = "SELECT lid, language, translation FROM locales_target";
$resultTraducciones = $conn->query($sqlTraducciones);
if (!$resultTraducciones) {
  die("Error al consultar locales_target: " . $conn->error);
}

while ($row = $resultTraducciones->fetch_assoc()) {
  // Group by language
  $lang = $row['language'];
  if (!isset($traduccionesPorIdioma[$lang])) {
    $traduccionesPorIdioma[$lang] = [];
  }
  $traduccionesPorIdioma[$lang][$row['lid']] = $row['translation'];
}
$resultTraducciones->free();

/**
 * 3. Generate .po files for each language found
 */
foreach ($traduccionesPorIdioma as $idioma => $trads) {
  // File name for each language
  $poFilename = $output_path . "/drupal7_{$idioma}.po";

  // Open the file for writing
  $handle = fopen($poFilename, 'w');
  if (!$handle) {
    echo "No se pudo crear el archivo: $poFilename\n";
    continue;
  }

  // Add a basic header to the .po file
  fwrite($handle, "msgid \"\"\n");
  fwrite($handle, "msgstr \"\"\n");
  fwrite($handle, "\"Project-Id-Version: Drupal7\\n\"\n");
  fwrite($handle, "\"MIME-Version: 1.0\\n\"\n");
  fwrite($handle, "\"Content-Type: text/plain; charset=UTF-8\\n\"\n");
  fwrite($handle, "\"Content-Transfer-Encoding: 8bit\\n\"\n");
  fwrite($handle, "\"Language: $idioma\\n\"\n\n");

  // Write messages in PO format
  foreach ($fuentes as $lid => $info) {
    $source = $info['source'];
    $context = $info['context'];
    // Check whether a translation exists for this lid in the current language
    $trad = isset($trads[$lid]) ? $trads[$lid] : '';

    // If no translation exists, we could skip the entry or leave msgstr empty
    // Keep the entry even when empty to document all strings
    if (strlen($context)) {
      // Include msgctxt if context exists
      fwrite($handle, "msgctxt \"{$context}\"\n");
    }
    fwrite($handle, "msgid \"{$source}\"\n");
    fwrite($handle, "msgstr \"{$trad}\"\n\n");
  }

  fclose($handle);
  echo "Archivo .po generado para idioma [$idioma]: $poFilename\n";
}

// Close the connection
$conn->close();