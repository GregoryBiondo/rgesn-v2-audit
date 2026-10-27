<?php
/**
 * Import d'un fichier XLSX/ODS RGESN V2 2024 dans un audit JSON.
 */

function xlsx_unescape(string $s): string
{
    return html_entity_decode($s, ENT_QUOTES | (defined('ENT_XML1') ? ENT_XML1 : 0), 'UTF-8');
}

function xlsx_unzip_to_dir(string $path, string $dest): void
{
    $data = @file_get_contents($path);
    if ($data === false) {
        throw new RuntimeException('Fichier illisible.');
    }
    $eocdSig = "\x50\x4b\x05\x06";
    $pos = strrpos($data, $eocdSig);
    if ($pos === false) {
        throw new RuntimeException('Archive ZIP invalide (EOCD introuvable).');
    }
    $cdSize   = unpack('V', substr($data, $pos + 12, 4))[1];
    $cdOffset = unpack('V', substr($data, $pos + 16, 4))[1];

    $ptr = $cdOffset;
    $end = $cdOffset + $cdSize;
    while ($ptr < $end) {
        if (substr($data, $ptr, 4) !== "PK\x01\x02") {
            break;
        }
        $compMethod   = unpack('v', substr($data, $ptr + 10, 2))[1];
        $compSize     = unpack('V', substr($data, $ptr + 20, 4))[1];
        $fnLen        = unpack('v', substr($data, $ptr + 28, 2))[1];
        $extraLen     = unpack('v', substr($data, $ptr + 30, 2))[1];
        $commentLen   = unpack('v', substr($data, $ptr + 32, 2))[1];
        $localOffset  = unpack('V', substr($data, $ptr + 42, 4))[1];
        $filename     = substr($data, $ptr + 46, $fnLen);

        $lp = $localOffset;
        if (substr($data, $lp, 4) !== "PK\x03\x04") {
            throw new RuntimeException('En-tête local ZIP invalide.');
        }
        $localFnLen    = unpack('v', substr($data, $lp + 26, 2))[1];
        $localExtraLen = unpack('v', substr($data, $lp + 28, 2))[1];
        $dataOffset    = $lp + 30 + $localFnLen + $localExtraLen;
        $compData      = substr($data, $dataOffset, $compSize);

        if ($compMethod === 0) {
            $uncomp = $compData;
        } elseif ($compMethod === 8) {
            $uncomp = @gzinflate($compData);
            if ($uncomp === false) {
                throw new RuntimeException('Décompression ZIP (deflate) échouée pour ' . $filename);
            }
        } else {
            throw new RuntimeException('Méthode de compression ZIP non supportée (' . $compMethod . ')');
        }

        $target = $dest . '/' . $filename;
        if (substr($filename, -1) === '/') {
            @mkdir($target, 0777, true);
        } else {
            @mkdir(dirname($target), 0777, true);
            if (file_put_contents($target, $uncomp) === false) {
                throw new RuntimeException('Écriture impossible : ' . $target);
            }
        }

        $ptr += 46 + $fnLen + $extraLen + $commentLen;
    }
}

function xlsx_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $file) {
        if ($file->isDir()) {
            @rmdir($file->getRealPath());
        } else {
            @unlink($file->getRealPath());
        }
    }
    @rmdir($dir);
}

function xlsx_read_shared_strings(string $base): array
{
    $xml = @file_get_contents($base . '/xl/sharedStrings.xml');
    if ($xml === false) {
        return [];
    }
    $strings = [];
    preg_match_all('/<si\b[^>]*>(.*?)<\/si>/s', $xml, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $m[1], $tm);
        $text = '';
        foreach ($tm[1] as $t) {
            $text .= xlsx_unescape($t);
        }
        $strings[] = $text;
    }
    return $strings;
}

function xlsx_parse_sheet(string $xml, array $shared): array
{
    $rows = [];
    preg_match_all('/<row\b([^>]*?)(?:\/>|>(.*?)<\/row>)/s', $xml, $all, PREG_SET_ORDER);
    foreach ($all as $m) {
        $attrs  = $m[1];
        $inner  = isset($m[2]) ? $m[2] : '';
        if (!preg_match('/\br="(\d+)"/', $attrs, $rr)) {
            $rr = [1, (string) (count($rows) + 1)];
        }
        $rowNum = (int) $rr[1];

        $cells = [];
        preg_match_all('/<c\b([^>]*?)(?:\/>|>(.*?)<\/c>)/s', $inner, $cellMatches, PREG_SET_ORDER);
        foreach ($cellMatches as $cm) {
            $cattrs = $cm[1];
            $cinner = isset($cm[2]) ? $cm[2] : '';
            if (!preg_match('/\br="([A-Z]+)(\d+)"/', $cattrs, $cr)) {
                continue;
            }
            $col = $cr[1];

            $t = '';
            if (preg_match('/\bt="([^"]+)"/', $cattrs, $ct)) {
                $t = $ct[1];
            }

            $raw = '';
            if (preg_match('/<v>(.*?)<\/v>/s', $cinner, $vm)) {
                $raw = xlsx_unescape($vm[1]);
            } elseif (preg_match('/<is\b[^>]*>(.*?)<\/is>/s', $cinner, $ism)) {
                preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $ism[1], $ttm);
                $raw = implode('', array_map('xlsx_unescape', $ttm[1]));
            } elseif (preg_match('/<t\b[^>]*>(.*?)<\/t>/s', $cinner, $tm)) {
                $raw = xlsx_unescape($tm[1]);
            }

            if ($t === 's') {
                $val = $shared[(int) trim($raw)] ?? '';
            } else {
                $val = $raw;
            }
            $cells[$col] = $val;
        }
        if (!empty($cells)) {
            $rows[$rowNum] = $cells;
        }
    }
    return $rows;
}

function xlsx_read_workbook(string $path): array
{
    $extractDir = sys_get_temp_dir() . '/rgesn_xlsx_' . uniqid('', true);
    @mkdir($extractDir, 0777, true);
    try {
        xlsx_unzip_to_dir($path, $extractDir);
    } catch (Throwable $e) {
        xlsx_rrmdir($extractDir);
        throw $e;
    }

    $base = $extractDir;
    $relsXml = @file_get_contents($base . '/xl/_rels/workbook.xml.rels');
    $relMap  = [];
    if ($relsXml !== false) {
        preg_match_all('/<Relationship\b[^>]*>/s', $relsXml, $rels);
        foreach ($rels[0] as $tag) {
            $id = null;
            $target = null;
            if (preg_match('/\bId="([^"]+)"/', $tag, $m))     { $id = $m[1]; }
            if (preg_match('/\bTarget="([^"]+)"/', $tag, $m)) { $target = $m[1]; }
            if ($id !== null && $target !== null) {
                $relMap[$id] = $target;
            }
        }
    }

    $wbXml = @file_get_contents($base . '/xl/workbook.xml');
    if ($wbXml === false) {
        xlsx_rrmdir($extractDir);
        throw new RuntimeException('workbook.xml illisible.');
    }

    $shared = xlsx_read_shared_strings($base);
    $sheets = [];
    preg_match_all('/<sheet\b[^>]*>/s', $wbXml, $sheetTags);
    foreach ($sheetTags[0] as $tag) {
        $name = null;
        $rid  = null;
        if (preg_match('/\bname="([^"]+)"/', $tag, $m)) { $name = xlsx_unescape($m[1]); }
        if (preg_match('/\br:id="([^"]+)"/', $tag, $m)) { $rid = $m[1]; }
        if ($name === null || $rid === null || !isset($relMap[$rid])) {
            continue;
        }
        $target = ltrim($relMap[$rid], '/');
        if (strpos($target, 'xl/') === 0) {
            $target = substr($target, 3);
        }
        $content = @file_get_contents($base . '/xl/' . $target);
        if ($content === false) {
            continue;
        }
        $sheets[$name] = xlsx_parse_sheet($content, $shared);
    }

    xlsx_rrmdir($extractDir);
    return $sheets;
}

function ods_read_workbook(string $path): array
{
    $extractDir = sys_get_temp_dir() . '/rgesn_ods_' . uniqid('', true);
    @mkdir($extractDir, 0777, true);
    try {
        xlsx_unzip_to_dir($path, $extractDir);
    } catch (Throwable $e) {
        xlsx_rrmdir($extractDir);
        throw $e;
    }

    $content = @file_get_contents($extractDir . '/content.xml');
    xlsx_rrmdir($extractDir);
    if ($content === false) {
        throw new RuntimeException('content.xml illisible.');
    }

    $sheets = [];
    preg_match_all('/<table:table\b([^>]*)>(.*?)<\/table:table>/s', $content, $tableMatches, PREG_SET_ORDER);
    foreach ($tableMatches as $tm) {
        $attrs = $tm[1];
        $inner = $tm[2];
        $name = 'Feuille';
        if (preg_match('/table:name="([^"]*)"/', $attrs, $nm)) {
            $name = xlsx_unescape($nm[1]);
        }

        $rows = [];
        $rowNum = 1;
        preg_match_all('/<table:table-row\b([^>]*?)(?:\/>|>(.*?)<\/table:table-row>)/s', $inner, $rowMatches, PREG_SET_ORDER);
        foreach ($rowMatches as $rm) {
            $rowAttrs = $rm[1];
            $rowInner = isset($rm[2]) ? $rm[2] : '';
            $repeatRows = 1;
            if (preg_match('/table:number-rows-repeated="(\d+)"/', $rowAttrs, $rr)) {
                $repeatRows = (int) $rr[1];
            }
            if ($repeatRows < 1) { $repeatRows = 1; }
            if ($repeatRows > 2000) { $repeatRows = 2000; }

            // Le contenu d'une ligne répétée est toujours identique : les
            // cellules ne sont donc calculées qu'une seule fois.
            //
            // Les cellules vides ne sont pas matérialisées (seule leur position
            // compte). Sans cela, LibreOffice qui écrit des milliers de
            // cellules « répétées à vide » en fin de feuille faisait fabriquer
            // plusieurs millions de cellules vides : l'import ODS prenait
            // ~20 s et 50 Mo de mémoire.
            $cells = [];
            $colIndex = 0;
            preg_match_all('/<table:table-cell\b([^>]*?)(?:\/>|>(.*?)<\/table:table-cell>)/s', $rowInner, $cellMatches, PREG_SET_ORDER);
            foreach ($cellMatches as $cm) {
                $cattrs = $cm[1];
                $cinner = isset($cm[2]) ? $cm[2] : '';
                $repeatCols = 1;
                if (preg_match('/table:number-columns-repeated="(\d+)"/', $cattrs, $cr)) {
                    $repeatCols = (int) $cr[1];
                }
                if ($repeatCols < 1) { $repeatCols = 1; }

                $val = ods_cell_value($cattrs, $cinner);
                if (trim((string) $val) !== '') {
                    $cells[ods_col_letter($colIndex)] = $val;
                }
                $colIndex += $repeatCols;
            }

            // Une ligne entièrement vide n'apporte rien : inutile de la
            // répéter (et de gonfler le tableau de résultats).
            if (empty($cells)) {
                continue;
            }

            for ($r = 0; $r < $repeatRows; $r++) {
                $rows[$rowNum] = $cells;
                $rowNum++;
            }
        }
        $sheets[$name] = $rows;
    }

    return $sheets;
}

function ods_cell_value(string $attrs, string $inner): string
{
    $innerP = preg_replace('/<\/text:p>/i', "\n", $inner);
    $text = preg_replace('/<[^>]+>/', '', $innerP);
    $text = xlsx_unescape(trim($text));

    $type = '';
    if (preg_match('/office:value-type="([^"]*)"/', $attrs, $m)) {
        $type = $m[1];
    }

    if ($type === 'string') {
        if (preg_match('/office:string-value="([^"]*)"/', $attrs, $m)) {
            $sv = xlsx_unescape($m[1]);
            if ($sv !== '') { return $sv; }
        }
        return $text;
    }
    if (in_array($type, ['float', 'currency', 'percentage', 'date', 'time', 'boolean'], true)) {
        if ($type === 'boolean' && preg_match('/office:boolean-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if ($type === 'date' && preg_match('/office:date-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if ($type === 'time' && preg_match('/office:time-value="([^"]*)"/', $attrs, $m)) {
            return $m[1];
        }
        if (preg_match('/office:value="([^"]*)"/', $attrs, $m)) {
            return xlsx_unescape($m[1]);
        }
        return $text;
    }

    if (preg_match('/office:value="([^"]*)"/', $attrs, $m)) {
        return xlsx_unescape($m[1]);
    }
    return $text;
}

function ods_col_letter(int $index): string
{
    return rgesn_col_letter($index + 1);
}

/**
 * Lettre de colonne correspondant à un index 1-based (1 => A, 26 => Z, 27 => AA).
 */
function rgesn_col_letter(int $index): string
{
    $letters = '';
    while ($index > 0) {
        $index--;
        $letters = chr(65 + ($index % 26)) . $letters;
        $index = intdiv($index, 26);
    }
    return $letters;
}

/**
 * Colonne située $delta colonnes à droite (ou à gauche) de $col.
 */
function rgesn_col_shift(string $col, int $delta): string
{
    return rgesn_col_letter(rgesn_col_index($col) + $delta);
}

/**
 * Valeur d'une cellule, normalisée (espaces coupés), ou chaîne vide.
 */
function rgesn_cell(array $cells, string $col): string
{
    return trim((string) ($cells[$col] ?? ''));
}

/**
 * Lettres de colonne d'une ligne, triées de gauche à droite.
 */
function rgesn_row_cols_sorted(array $cells): array
{
    $keys = array_keys($cells);
    usort($keys, fn($a, $b) => rgesn_col_index($a) <=> rgesn_col_index($b));
    return $keys;
}

/**
 * Normalise un intitulé de colonne pour pouvoir le comparer sans tenir
 * compte de la casse, des accents, de la ponctuation ni des sauts de ligne.
 */
function rgesn_normalize_label(string $s): string
{
    $s = strtolower(trim($s));
    $s = strtr($s, [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'î' => 'i', 'ï' => 'i', 'í' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ó' => 'o',
        'û' => 'u', 'ù' => 'u', 'ü' => 'u', 'ú' => 'u',
        'ç' => 'c', 'œ' => 'oe', 'æ' => 'ae',
        '’' => ' ', '‘' => ' ', "\u{00A0}" => ' ',
        '«' => ' ', '»' => ' ', '-' => ' ',
    ]);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/**
 * Une ligne d'en-tête (notes de début de feuille ou intitulés de colonnes)
 * n'est pas une ligne de données : elle ne doit jamais être rattachée à un
 * critère. Sans cette détection, les intitulés de colonnes ("Actions à
 * mener", "Qui fait ?", "Pour quand ?") étaient importés comme des données.
 */
function rgesn_row_is_header(array $cells): bool
{
    // Intitulés de colonnes : la cellule doit commencer par l'intitulé.
    static $prefixes = [
        'libelle du critere',
        'actions a mener',
        'qui fait',
        'pour quand',
        'niveau de priorite',
        'niveau de difficulte',
        'date de l evaluation',
        'evolutions potentielles',
        'synthese du moyen de test',
    ];

    // Phrases de la ligne de notes en tête de feuille : elles peuvent être
    // precedées d'un texte libre (« Le contenu de ces colonnes… »).
    static $contains = [
        'contenu de ces colonnes',
        'colonnes a usage interne',
        'colonnes pour aider',
    ];

    foreach ($cells as $value) {
        $norm = rgesn_normalize_label((string) $value);
        if ($norm === '') {
            continue;
        }
        foreach ($prefixes as $marker) {
            if (strpos($norm, $marker) === 0) {
                return true;
            }
        }
        foreach ($contains as $marker) {
            if (strpos($norm, $marker) !== false) {
                return true;
            }
        }
    }
    return false;
}

function rgesn_row_cells_sorted(array $cells): array
{
    $out = [];
    foreach (rgesn_row_cols_sorted($cells) as $k) {
        $out[] = $cells[$k];
    }
    return $out;
}

function rgesn_col_index(string $col): int
{
    $n = 0;
    for ($i = 0; $i < strlen($col); $i++) {
        $n = $n * 26 + (ord($col[$i]) - 64);
    }
    return $n;
}

function rgesn_apply_import(array $audit, array $sheets): array
{
    $statusMap = [
        'Validé'          => 'conforme',
        'Non validé'      => 'non-conforme',
        'Non applicable'  => 'non-applicable',
        'À évaluer'       => 'non-testé',
    ];

    // ── Projet / auditeur ────────────────────────────────────────────────────
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') === false) {
            continue;
        }
        foreach ($rows as $cells) {
            $cells = rgesn_row_cells_sorted($cells);
            foreach ($cells as $i => $labelCell) {
                $label = trim((string) ($labelCell ?? ''));
                if ($label === 'Nom du service évalué' || $label === 'Nom du responsable de cette évaluation') {
                    $value = '';
                    for ($j = $i + 1; $j < count($cells); $j++) {
                        $v = trim((string) ($cells[$j] ?? ''));
                        if ($v !== '') { $value = $v; break; }
                    }
                    if ($value === '') { continue; }

                    if ($label === 'Nom du service évalué') {
                        if (preg_match('/^(.*?):\s*(https?:\/\/\S+)\s*$/u', $value, $m)) {
                            $audit['project']['name'] = trim($m[1]);
                            if (filter_var(trim($m[2]), FILTER_VALIDATE_URL)) {
                                $audit['project']['url'] = trim($m[2]);
                            }
                        } else {
                            $audit['project']['name'] = $value;
                        }
                    } else {
                        $audit['auditor']['name'] = $value;
                    }
                }
            }
        }
    }

    $index = [];
    foreach ($audit['criteria'] as $k => $c) {
        if (isset($c['id'])) {
            $index[$c['id']] = $k;
        }
    }

    // ── Critères ─────────────────────────────────────────────────────────────
    foreach ($sheets as $name => $rows) {
        if (stripos($name, 'score') !== false) {
            continue;
        }

        // Le critère « courant » est propre à chaque feuille.
        //
        // Sans cette remise à zéro, les deux lignes d'en-tête de la feuille
        // suivante (notes de début de feuille + intitulés de colonnes) étaient
        // rattachées au dernier critère de la feuille précédente : le champ
        // « Actions à mener » du critère 1.10, vide dans le fichier, recevait
        // alors l'intitulé de colonne « Actions à mener ».
        $lastId    = null;
        $lastIdCol = null;

        foreach ($rows as $cells) {
            // Notes de début de feuille / ligne d'intitulés de colonnes :
            // ce ne sont pas des données.
            if (rgesn_row_is_header($cells)) {
                continue;
            }

            $cols = rgesn_row_cols_sorted($cells);

            // Colonne de l'identifiant du critère : colonne B en XLSX
            // (colonne A réservée au libellé de la thématique), colonne A en
            // ODS. On la cherche par son contenu, puis on se réfère aux
            // autres colonnes par position relative : le décalage est ainsi
            // le même pour les deux formats.
            $idCol = null;
            foreach ($cols as $col) {
                if (preg_match('/^\d+\.\d+$/', trim((string) ($cells[$col] ?? '')))) {
                    $idCol = $col;
                    break;
                }
            }

            if ($idCol !== null) {
                $id = trim((string) $cells[$idCol]);
                $lastId    = $id;
                $lastIdCol = $idCol;
                if (!isset($index[$id])) {
                    $lastId    = null;
                    $lastIdCol = null;
                    continue;
                }
                $k = $index[$id];

                $pri = rgesn_cell($cells, rgesn_col_shift($idCol, 2));
                if (in_array($pri, ['Prioritaire', 'Recommandé', 'Modéré'], true)) {
                    $audit['criteria'][$k]['priority'] = $pri;
                }

                $statusRaw = rgesn_cell($cells, rgesn_col_shift($idCol, 3));
                if ($statusRaw !== '') {
                    if (isset($statusMap[$statusRaw])) {
                        $audit['criteria'][$k]['status'] = $statusMap[$statusRaw];
                    } elseif (in_array($statusRaw, ['conforme', 'non-conforme', 'non-applicable', 'non-testé'], true)) {
                        $audit['criteria'][$k]['status'] = $statusRaw;
                    }
                }

                $diff = rgesn_cell($cells, rgesn_col_shift($idCol, 6));
                if (in_array($diff, ['Fort', 'Moyen', 'Faible'], true)) {
                    $audit['criteria'][$k]['difficulty'] = $diff;
                }

                $action = rgesn_cell($cells, rgesn_col_shift($idCol, 7));
                if ($action !== '') {
                    $audit['criteria'][$k]['action_text'] = $action;
                }

                $whoRaw = rgesn_cell($cells, rgesn_col_shift($idCol, 8));
                if ($whoRaw !== '') {
                    $parts = preg_split('/[\n,+;]+/', $whoRaw);
                    $tags = [];
                    foreach ($parts as $p) {
                        $t = trim($p);
                        $t = rtrim($t, " \t.");
                        if ($t !== '') {
                            $tags[] = $t;
                        }
                    }
                    $tags = array_values(array_unique($tags));
                    if (!empty($tags)) {
                        $audit['criteria'][$k]['action_who'] = $tags;
                    }
                }

                $whenRaw = rgesn_cell($cells, rgesn_col_shift($idCol, 9));
                if ($whenRaw !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $whenRaw, $m)) {
                        $audit['criteria'][$k]['action_when'] = sprintf('%04d-%02d', $m[3], $m[2]);
                    } elseif (preg_match('/^\d{4}-\d{2}$/', $whenRaw)) {
                        $audit['criteria'][$k]['action_when'] = $whenRaw;
                    }
                }

                $evalDate = rgesn_cell($cells, rgesn_col_shift($idCol, 4));
                if ($evalDate !== '') {
                    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $evalDate, $m)) {
                        $audit['criteria'][$k]['evaluation_date'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
                    }
                }

            } else {
                // Ligne complémentaire : l'utilisateur a développé sa réponse
                // dans la colonne « Libellé du critère », c.-à-d. la colonne
                // immédiatement à droite de celle de l'identifiant.
                //
                // On ne prend PAS la première cellule « longue » de la ligne :
                // les colonnes de droite de la ligne d'en-tête (« Actions à
                // mener », « Qui fait ? », « Pour quand ? ») et les colonnes
                // d'exemple du modèle ne sont pas des réponses.
                if ($lastId === null || $lastIdCol === null || !isset($index[$lastId])) {
                    continue;
                }
                $k = $index[$lastId];

                $candidate = rgesn_cell($cells, rgesn_col_shift($lastIdCol, 1));
                if ($candidate === '') {
                    continue;
                }

                $label = (string) ($audit['criteria'][$k]['label'] ?? '');
                if ($candidate !== $label) {
                    $existing = trim((string) ($audit['criteria'][$k]['comment'] ?? ''));
                    if ($existing === '') {
                        $audit['criteria'][$k]['comment'] = $candidate;
                    } elseif (strpos($existing, $candidate) === false) {
                        $audit['criteria'][$k]['comment'] = $existing . "\n" . $candidate;
                    }
                }
            }
        }
    }

    $audit['updated_at'] = date('c');
    if (function_exists('calculate_score')) {
        $audit['score'] = calculate_score($audit['criteria']);
    } else {
        $audit['score'] = $audit['score'] ?? 0.0;
    }

    return $audit;
}

function rgesn_import_xlsx(array $audit, string $path): array
{
    $sheets = xlsx_read_workbook($path);
    return rgesn_apply_import($audit, $sheets);
}

function rgesn_import_ods(array $audit, string $path): array
{
    $sheets = ods_read_workbook($path);
    return rgesn_apply_import($audit, $sheets);
}

/**
 * Indique si l'archive ZIP contient l'entrée $entry.
 */
function rgesn_zip_entry_exists(string $path, string $entry): bool
{
    $data = @file_get_contents($path);
    if ($data === false) {
        return false;
    }
    $pos = strrpos($data, "\x50\x4b\x05\x06");
    if ($pos === false) {
        return false;
    }
    $cdSize   = unpack('V', substr($data, $pos + 12, 4))[1];
    $cdOffset = unpack('V', substr($data, $pos + 16, 4))[1];

    $ptr = $cdOffset;
    $end = $cdOffset + $cdSize;
    while ($ptr < $end) {
        if (substr($data, $ptr, 4) !== "PK\x01\x02") {
            break;
        }
        $fnLen      = unpack('v', substr($data, $ptr + 28, 2))[1];
        $extraLen   = unpack('v', substr($data, $ptr + 30, 2))[1];
        $commentLen = unpack('v', substr($data, $ptr + 32, 2))[1];
        if (substr($data, $ptr + 46, $fnLen) === $entry) {
            return true;
        }
        $ptr += 46 + $fnLen + $extraLen + $commentLen;
    }
    return false;
}

/**
 * Format réel du tableur, déduit de son contenu et non de son nom.
 *
 * Indispensable : le fichier téléversé est recopié dans un fichier
 * temporaire dont le nom n'a pas d'extension, si bien que le seul test sur
 * l'extension renvoyait systématiquement les fichiers ODS vers le lecteur
 * XLSX (erreur « workbook.xml illisible »).
 */
function rgesn_detect_spreadsheet_format(string $path): string
{
    if (rgesn_zip_entry_exists($path, 'xl/workbook.xml')) {
        return 'xlsx';
    }
    if (rgesn_zip_entry_exists($path, 'content.xml')) {
        return 'ods';
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext === 'ods' ? 'ods' : 'xlsx';
}

function rgesn_import_spreadsheet(array $audit, string $path): array
{
    if (rgesn_detect_spreadsheet_format($path) === 'ods') {
        return rgesn_import_ods($audit, $path);
    }
    return rgesn_import_xlsx($audit, $path);
}

function action_import_audit_xlsx(array $input, array $current_user): never
{
    $id = trim($input['id'] ?? ($_POST['id'] ?? ''));
    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $tmpPath = null;
    $cleanup  = false;

    if (defined('RGESN_API_TESTING') && isset($input['file_path'])) {
        $tmpPath = (string) $input['file_path'];
        if (!is_file($tmpPath)) {
            json_response(['error' => 'Fichier introuvable'], 400);
        }
    } else {
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json_response(['error' => 'Fichier manquant'], 400);
        }
        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_response(['error' => 'Erreur lors de l\'upload'], 400);
        }
        if (($f['size'] ?? 0) > 10 * 1024 * 1024) {
            json_response(['error' => 'Fichier trop volumineux (max. 10 Mo)'], 400);
        }
        $name = (string) ($f['name'] ?? '');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'ods'], true)) {
            json_response(['error' => 'Le fichier doit être au format XLSX ou ODS'], 400);
        }
        // Le fichier temporaire conserve l'extension d'origine : le format
        // du tableur peut ainsi être identifié même si le nom du fichier
        // téléversé est trompeur.
        $tmpBase = tempnam(sys_get_temp_dir(), 'rgesn_xlsx_');
        if ($tmpBase === false) {
            json_response(['error' => 'Enregistrement temporaire impossible'], 500);
        }
        $tmpPath = $tmpBase . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $tmpPath)) {
            @unlink($tmpBase);
            @unlink($tmpPath);
            json_response(['error' => 'Enregistrement temporaire impossible'], 500);
        }
        @unlink($tmpBase);
        $cleanup = true;
    }

    try {
        $result = atomic_audit_update($id, function (array $audit) use ($current_user, $tmpPath) {
            $permission = get_user_permission($audit, $current_user);
            if (!permission_can_edit($permission)) {
                return [null, ['success' => false, 'error' => 'Vous n\'avez pas les droits pour modifier cet audit', '_status' => 403]];
            }
            $lock = lock_status($audit);
            if ($lock['locked'] && $lock['by_id'] !== $current_user['id']) {
                return [null, [
                    'success' => false,
                    'error'   => "Cet audit est en cours de modification par {$lock['by_name']}.",
                    '_status' => 409,
                ]];
            }

            try {
                $newAudit = rgesn_import_spreadsheet($audit, $tmpPath);
            } catch (Throwable $e) {
                return [null, ['success' => false, 'error' => 'Impossible de lire le fichier : ' . $e->getMessage(), '_status' => 400]];
            }

            return [$newAudit, [
                'success'    => true,
                'score'      => $newAudit['score'],
                'updated_at' => $newAudit['updated_at'],
            ]];
        });
    } finally {
        if ($cleanup && $tmpPath && is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    $status = $result['_status']
        ?? ($result['success'] ?? false ? 200 : (($result['error'] ?? '') === 'Audit introuvable.' ? 404 : 500));
    unset($result['_status']);
    json_response($result, $status);
}
