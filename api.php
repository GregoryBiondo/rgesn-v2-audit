<?php
/**
 * Copyright (C) 2026  Grégory Biondo
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published
 * by the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * RGESN V2 2024 Audit Tool — API Endpoint
 * Gère toutes les requêtes fetch() du front-end
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-cache, no-store, must-revalidate');

define('AUDITS_DIR', __DIR__ . '/data/audits/');
define('CRITERES_FILE', __DIR__ . '/data/criteria/criteria_settings.json');

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/xlsx_importer.php';

// ─── Helpers ─────────────────────────────────────────────────────────────────

function generate_uuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ─── Calcul du score RGESN ───────────────────────────────────────────────────

function calculate_score(array $criteria): float
{
    $weights = [
        'Prioritaire' => 1.5,
        'Recommandé'  => 1.25,
        'Modéré'      => 1.0,
    ];

    // Charger le référentiel pour utiliser les priorités à jour
    $ref = [];
    $raw = json_decode(file_get_contents(CRITERES_FILE), true) ?? [];
    foreach ($raw as $item) {
        $ref[$item['id']] = $item;
    }

    $numerator   = 0.0;
    $denominator = 0.0;

    foreach ($criteria as $c) {
        $status   = $c['status'] ?? 'non-testé';
        $priority = $ref[$c['id']]['priority'] ?? $c['priority'] ?? 'Modéré';
        $weight   = $weights[$priority] ?? 1.0;

        if ($status === 'conforme') {
            $numerator   += $weight;
            $denominator += $weight;
        } elseif ($status === 'non-conforme') {
            $denominator += $weight;
        }
        // non-applicable et non-testé : exclus du calcul
    }

    if ($denominator == 0.0) {
        return 0.0;
    }

    return round(($numerator / $denominator) * 100, 1);
}

// ─── Taux de complétion ───────────────────────────────────────────────────────

function calculate_completion(array $criteria): float
{
    $total = count($criteria);
    if ($total === 0) {
        return 0.0;
    }

    $tested = 0;
    foreach ($criteria as $c) {
        if (($c['status'] ?? 'non-testé') !== 'non-testé') {
            $tested++;
        }
    }

    return round(($tested / $total) * 100, 1);
}

// ─── Initialisation des critères depuis le référentiel ───────────────────────

function get_initial_criteria(): array
{
    if (!file_exists(CRITERES_FILE)) {
        return [];
    }

    $raw = file_get_contents(CRITERES_FILE);
    $ref = json_decode($raw, true);
    if (!is_array($ref)) {
        return [];
    }

    $criteria = [];
    foreach ($ref as $item) {
        $criteria[] = [
            'thematic_id' => (int) ($item['thematic_id'] ?? 0),
            'id'          => $item['id'] ?? '',
            'priority'    => $item['priority'] ?? 'Modéré',
            'difficulty'  => $item['difficulty'] ?? 'Moyen',
            'status'      => 'non-testé',
            'comment'     => '',
            'action_text' => '',
            'action_who'  => [],
            'action_when' => '',
            'action_easy' => false,
        ];
    }

    return $criteria;
}

// ─── Lecture sûre d'un audit ─────────────────────────────────────────────────

function read_audit(string $id): ?array
{
    if (!is_valid_uuid($id)) {
        return null;
    }

    $file = AUDITS_DIR . $id . '.json';
    if (!file_exists($file)) {
        return null;
    }

    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

// ─── Écriture atomique d'un audit ────────────────────────────────────────────

function write_audit(string $id, array $audit): bool
{
    $file = AUDITS_DIR . $id . '.json';
    $tmp  = $file . '.tmp';

    $json = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (file_put_contents($tmp, $json, LOCK_EX) === false) {
        return false;
    }

    return rename($tmp, $file);
}

// ─── Routeur ─────────────────────────────────────────────────────────────────

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Lecture du body JSON pour les requêtes POST
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw_body = file_get_contents('php://input');
    if (!empty($raw_body)) {
        $decoded = json_decode($raw_body, true);
        if (is_array($decoded)) {
            $input = $decoded;
            // action peut venir du body JSON
            if (empty($action) && isset($input['action'])) {
                $action = $input['action'];
            }
        }
    }
    // Fallback sur $_POST
    if (empty($input)) {
        $input = $_POST;
    }
}

if (!is_dir(AUDITS_DIR)) {
    mkdir(AUDITS_DIR, 0755, true);
}

switch ($action) {
    case 'create_audit':
        action_create_audit($input);
        break;
    case 'get_audit':
        action_get_audit();
        break;
    case 'update_audit':
        action_update_audit($input);
        break;
    case 'import_audit_xlsx':
        action_import_audit_xlsx($input);
        break;
    case 'list_audits':
        action_list_audits();
        break;
    case 'delete_audit':
        action_delete_audit($input);
        break;
    case 'duplicate_audit':
        action_duplicate_audit($input);
        break;
    default:
        json_response(['error' => 'Action inconnue ou manquante'], 400);
}

// ─── Actions ─────────────────────────────────────────────────────────────────

function action_create_audit(array $input): never
{
    $project_name = trim($input['project_name'] ?? '');
    $project_url  = trim($input['project_url'] ?? '');
    $auditor_name = trim($input['auditor_name'] ?? '');

    if ($project_name === '') {
        json_response(['error' => 'Le nom du projet est requis'], 400);
    }

    // Sanitize URL
    if ($project_url !== '' && !filter_var($project_url, FILTER_VALIDATE_URL)) {
        $project_url = '';
    }

    $id  = generate_uuid();
    $now = date('c');

    $audit = [
        'id'         => $id,
        'created_at' => $now,
        'updated_at' => $now,
        'status'     => 'en cours',
        'score'      => 0.0,
        'project'    => [
            'name' => $project_name,
            'url'  => $project_url,
        ],
        'auditor' => [
            'name' => $auditor_name,
        ],
        'criteria' => get_initial_criteria(),
    ];

    if (!write_audit($id, $audit)) {
        json_response(['error' => 'Impossible de créer l\'audit (erreur d\'écriture)'], 500);
    }

    json_response(['success' => true, 'id' => $id]);
}

function action_get_audit(): never
{
    $id = trim($_GET['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $audit = read_audit($id);
    if ($audit === null) {
        json_response(['error' => 'Audit introuvable'], 404);
    }

    json_response($audit);
}

function action_update_audit(array $input): never
{
    $id = trim($input['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $audit = read_audit($id);
    if ($audit === null) {
        json_response(['error' => 'Audit introuvable'], 404);
    }

    // Mise à jour des informations projet
    if (isset($input['project']) && is_array($input['project'])) {
        if (isset($input['project']['name'])) {
            $audit['project']['name'] = trim($input['project']['name']);
        }
        if (isset($input['project']['url'])) {
            $url = trim($input['project']['url']);
            $audit['project']['url'] = ($url === '' || filter_var($url, FILTER_VALIDATE_URL)) ? $url : $audit['project']['url'];
        }
    }

    // Mise à jour de l'auditeur
    if (isset($input['auditor']) && is_array($input['auditor'])) {
        if (isset($input['auditor']['name'])) {
            $audit['auditor']['name'] = trim($input['auditor']['name']);
        }
    }

    // Mise à jour du statut de l'audit
    if (isset($input['status'])) {
        $allowed = ['en cours', 'terminé'];
        if (in_array($input['status'], $allowed, true)) {
            $audit['status'] = $input['status'];
        }
    }

    // Mise à jour des critères
    if (isset($input['criteria']) && is_array($input['criteria'])) {
        $allowed_statuses = ['conforme', 'non-conforme', 'non-applicable', 'non-testé'];

        // Indexer les critères de l'audit pour un accès rapide
        $index = [];
        foreach ($audit['criteria'] as $k => $c) {
            $index[$c['id']] = $k;
        }

        foreach ($input['criteria'] as $update) {
            if (!is_array($update)) {
                continue;
            }
            $cid = $update['id'] ?? '';
            if ($cid === '' || !isset($index[$cid])) {
                continue;
            }

            $k = $index[$cid];

            if (isset($update['status']) && in_array($update['status'], $allowed_statuses, true)) {
                $audit['criteria'][$k]['status'] = $update['status'];
            }
            if (isset($update['comment'])) {
                $audit['criteria'][$k]['comment'] = mb_substr(trim($update['comment']), 0, 5000);
            }
            if (isset($update['action_text'])) {
                $audit['criteria'][$k]['action_text'] = mb_substr(trim($update['action_text']), 0, 2000);
            }
            if (isset($update['action_who']) && is_array($update['action_who'])) {
                $audit['criteria'][$k]['action_who'] = array_values(array_slice(
                    array_map(
                        fn($t) => mb_substr(trim((string) $t), 0, 100),
                        array_filter($update['action_who'], fn($t) => is_string($t) || is_numeric($t))
                    ),
                    0, 20
                ));
            }
            if (isset($update['action_when'])) {
                $when = trim($update['action_when']);
                $audit['criteria'][$k]['action_when'] = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $when) ? $when : '';
            }
            if (isset($update['action_easy'])) {
                $audit['criteria'][$k]['action_easy'] = (bool) $update['action_easy'];
            }
        }
    }

    $audit['updated_at'] = date('c');
    $audit['score']      = calculate_score($audit['criteria']);

    if (!write_audit($id, $audit)) {
        json_response(['error' => 'Impossible de sauvegarder l\'audit'], 500);
    }

    json_response([
        'success'    => true,
        'score'      => $audit['score'],
        'updated_at' => $audit['updated_at'],
    ]);
}

function action_list_audits(): never
{
    $audits = [];

    if (!is_dir(AUDITS_DIR)) {
        json_response($audits);
    }

    $files = glob(AUDITS_DIR . '*.json');
    if (!$files) {
        json_response($audits);
    }

    foreach ($files as $file) {
        $data = json_decode(file_get_contents($file), true);
        if (!is_array($data) || empty($data['id'])) {
            continue;
        }

        $completion = calculate_completion($data['criteria'] ?? []);

        // Compteurs par statut
        $counts = ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'non-testé' => 0];
        foreach ($data['criteria'] ?? [] as $c) {
            $s = $c['status'] ?? 'non-testé';
            if (isset($counts[$s])) {
                $counts[$s]++;
            }
        }

        $audits[] = [
            'id'         => $data['id'],
            'created_at' => $data['created_at'] ?? '',
            'updated_at' => $data['updated_at'] ?? '',
            'status'     => $data['status'] ?? 'en cours',
            'score'      => $data['score'] ?? 0.0,
            'completion' => $completion,
            'counts'     => $counts,
            'project'    => $data['project'] ?? ['name' => '', 'url' => ''],
            'auditor'    => $data['auditor'] ?? ['name' => ''],
        ];
    }

    // Tri par updated_at décroissant
    usort($audits, fn($a, $b) => strcmp($b['updated_at'], $a['updated_at']));

    json_response($audits);
}

function action_duplicate_audit(array $input): never
{
    $source_id    = trim($input['source_id'] ?? '');
    $project_name = trim($input['project_name'] ?? '');
    $project_url  = trim($input['project_url'] ?? '');
    $auditor_name = trim($input['auditor_name'] ?? '');

    if (!is_valid_uuid($source_id)) {
        json_response(['error' => 'UUID source invalide'], 400);
    }
    if ($project_name === '') {
        json_response(['error' => 'Le nom du projet est requis'], 400);
    }

    $source = read_audit($source_id);
    if ($source === null) {
        json_response(['error' => 'Audit source introuvable'], 404);
    }

    if ($project_url !== '' && !filter_var($project_url, FILTER_VALIDATE_URL)) {
        $project_url = '';
    }

    $id  = generate_uuid();
    $now = date('c');

    $audit = [
        'id'         => $id,
        'created_at' => $now,
        'updated_at' => $now,
        'status'     => 'en cours',
        'score'      => calculate_score($source['criteria']),
        'project'    => ['name' => $project_name, 'url'  => $project_url],
        'auditor'    => ['name' => $auditor_name],
        'criteria'   => $source['criteria'],
    ];

    if (!write_audit($id, $audit)) {
        json_response(['error' => 'Impossible de créer l\'audit (erreur d\'écriture)'], 500);
    }

    json_response(['success' => true, 'id' => $id]);
}

function action_delete_audit(array $input): never
{
    $id = trim($input['id'] ?? $_GET['id'] ?? '');

    if (!is_valid_uuid($id)) {
        json_response(['error' => 'UUID invalide'], 400);
    }

    $file = AUDITS_DIR . $id . '.json';
    if (!file_exists($file)) {
        json_response(['error' => 'Audit introuvable'], 404);
    }

    if (!unlink($file)) {
        json_response(['error' => 'Impossible de supprimer l\'audit'], 500);
    }

    json_response(['success' => true]);
}
