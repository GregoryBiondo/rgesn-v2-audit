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
 * RGESN V2 2024 Audit Tool — Édition d'un audit
 */

require_once __DIR__ . '/includes/functions.php';

// ── Chargement des données ────────────────────────────────────────────────────
$audit_id = trim($_GET['id'] ?? '');

if (!is_valid_uuid($audit_id)) {
    header('Location: index.php');
    exit;
}

$audit_file = __DIR__ . '/data/audits/' . $audit_id . '.json';
if (!file_exists($audit_file)) {
    header('Location: index.php');
    exit;
}

$audit = json_decode(file_get_contents($audit_file), true);
if (!is_array($audit)) {
    header('Location: index.php');
    exit;
}

// Charger le référentiel des critères
$ref_criteria = [];
$ref_file = __DIR__ . '/data/criteria/criteria_settings.json';
if (file_exists($ref_file)) {
    $ref_raw = json_decode(file_get_contents($ref_file), true);
    if (is_array($ref_raw)) {
        foreach ($ref_raw as $item) {
            $ref_criteria[$item['id']] = $item;
        }
    }
}

// Indexer les critères de l'audit
$audit_criteria = [];
foreach ($audit['criteria'] as $c) {
    $audit_criteria[$c['id']] = $c;
}

// ── Grouper les critères par thématique ──────────────────────────────────────
$by_thematic = [];
foreach ($audit['criteria'] as $c) {
    $tid = $c['thematic_id'] ?? 1;
    $by_thematic[$tid][] = $c;
}

// ── Calcul complétion globale + par thématique ────────────────────────────────
function calc_completion(array $criteria): float {
    $total = count($criteria);
    if ($total === 0) return 0.0;
    $tested = array_filter($criteria, fn($c) => ($c['status'] ?? 'non-testé') !== 'non-testé');
    return round(count($tested) / $total * 100, 1);
}

$global_completion = calc_completion($audit['criteria']);

$thematic_completion = [];
foreach ($by_thematic as $tid => $crits) {
    $thematic_completion[$tid] = calc_completion($crits);
}

// ── Compteurs statuts ─────────────────────────────────────────────────────────
$counts = ['conforme' => 0, 'non-conforme' => 0, 'non-applicable' => 0, 'non-testé' => 0];
foreach ($audit['criteria'] as $c) {
    $s = $c['status'] ?? 'non-testé';
    if (isset($counts[$s])) $counts[$s]++;
}

$score = $audit['score'] ?? 0.0;
$is_done = $audit['status'] === 'terminé';

// ── Helpers d'affichage ───────────────────────────────────────────────────────
function priority_badge(string $p): string {
    $map = [
        'Prioritaire' => 'badge-priority-high',
        'Recommandé'  => 'badge-priority-medium',
        'Modéré'      => 'badge-priority-low',
    ];
    $cls = $map[$p] ?? 'badge-priority-low';
    return "<span class=\"badge-indicator {$cls}\" title=\"Priorité : {$p}\">{$p}</span>";
}

function difficulty_badge(string $d): string {
    $map = [
        'Fort'   => 'badge-diff-high',
        'Moyen'  => 'badge-diff-medium',
        'Faible' => 'badge-diff-low',
    ];
    $cls = $map[$d] ?? 'badge-diff-medium';
    return "<span class=\"{$cls}\" title=\"Difficulté : {$d}\">{$d}</span>";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit — <?= esc($audit['project']['name']) ?> — RGESN V2</title>
    <?php include __DIR__ . '/includes/favicon.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="audit-page">

<!-- Header fixe -->
<header class="app-header shadow-sm">
    <div class="container-fluid px-4 d-flex align-items-center justify-content-between">
        <a href="index.php" class="header-brand d-flex align-items-center gap-2 text-decoration-none">
            <img src="assets/images/logo.svg" alt="" height="32">
            <span class="header-title">Audit RGESN</span>
            <span class="badge bg-indigo ms-1">V2 2024</span>
            <span class="visually-hidden">Retour à l'accueil</span>
        </a>
        <div class="d-flex align-items-center gap-2">
            <?php if ($is_done): ?>
                <a href="report.php?id=<?= esc($audit_id) ?>" class="btn btn-outline-indigo btn-sm" target="_blank" rel="noopener">
                    <i class="bi bi-file-earmark-bar-graph me-1"></i>Voir le rapport public
                </a>
            <?php else: ?>
                <button class="btn btn-outline-secondary btn-sm" disabled title="Disponible une fois l'audit terminé">
                    <i class="bi bi-file-earmark-bar-graph me-1"></i>Voir le rapport public
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>

<main>

<!-- Barre de sous-navigation -->
<div class="audit-subheader">
    <div class="container-fluid px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div>
            <a href="index.php" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left me-1"></i>Retour au tableau de bord des audits
            </a>
            <h1 class="h4 fw-bold mb-0 mt-1 d-flex align-items-center gap-2">
                Audit
                <span class="text-muted fw-normal ms-1" id="auditProjectName"><?= esc($audit['project']['name']) ?></span>
                <button class="btn btn-link text-muted p-0 lh-1 flex-shrink-0"
                        data-bs-toggle="modal" data-bs-target="#modalEditAudit"
                        title="Modifier les informations de l'audit" style="font-size:0.85rem;">
                    <i class="bi bi-pencil"></i>
                </button>
            </h1>
        </div>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <!-- Progression globale -->
            <div class="d-flex align-items-center gap-2">
                <span class="text-muted small">Progression de l'audit</span>
                <div class="progress" style="width:100px;height:8px;" role="progressbar"
                     aria-valuenow="<?= $global_completion ?>" aria-valuemin="0" aria-valuemax="100">
                    <div class="progress-bar bg-indigo" id="globalProgressBar"
                         style="width:<?= $global_completion ?>%"></div>
                </div>
                <span class="fw-semibold small" id="globalProgressPct"><?= $global_completion ?>%</span>
            </div>
            <!-- Indicateur de sauvegarde -->
            <div id="saveIndicator" class="save-indicator save-idle" role="status">
                <i class="bi bi-check-circle me-1"></i><span>Enregistré</span>
            </div>
        </div>
    </div>
</div>

<!-- Cartes de synthèse -->
<div class="audit-stats-bar">
    <div class="container-fluid px-4">
        <div class="row g-3">
            <div class="col-auto">
                <div class="stat-card stat-card-score">
                    <div class="stat-value" id="statScore"><?= $score ?>%</div>
                    <div class="stat-label">Taux global<br>de conformité</div>
                    <div class="stat-sub">(RGESN V2 2024)</div>
                </div>
            </div>
            <div class="col-auto">
                <div class="stat-card stat-card-nc stat-card-clickable" id="statCardNc" role="button" tabindex="0" aria-pressed="false" title="Filtrer les critères non conformes">
                    <div class="stat-value" id="statNonConforme"><?= $counts['non-conforme'] ?></div>
                    <div class="stat-label">Critères<br>non conformes</div>
                    <div class="stat-sub" id="statNcSub">(dont <?= array_sum(array_map(
                        fn($c) => $c['priority'] === 'Prioritaire' && $c['status'] === 'non-conforme' ? 1 : 0,
                        $audit['criteria']
                    )) ?> prioritaires)</div>
                </div>
            </div>
            <div class="col-auto">
                <div class="stat-card stat-card-ok stat-card-clickable" id="statCardOk" role="button" tabindex="0" aria-pressed="false" title="Filtrer les critères conformes">
                    <div class="stat-value" id="statConforme"><?= $counts['conforme'] ?></div>
                    <div class="stat-label">Critères<br>conformes</div>
                    <div class="stat-sub" id="statOkSub">(dont <?= array_sum(array_map(
                        fn($c) => $c['priority'] === 'Prioritaire' && $c['status'] === 'conforme' ? 1 : 0,
                        $audit['criteria']
                    )) ?> prioritaires)</div>
                </div>
            </div>
            <div class="col-auto">
                <div class="stat-card stat-card-na stat-card-clickable" id="statCardNa" role="button" tabindex="0" aria-pressed="false" title="Filtrer les critères non applicables">
                    <div class="stat-value" id="statNonApplicable"><?= $counts['non-applicable'] ?></div>
                    <div class="stat-label">Critères<br>non applicables</div>
                </div>
            </div>
            <div class="col-auto">
                <div class="stat-card stat-card-nt stat-card-clickable" id="statCardNt" role="button" tabindex="0" aria-pressed="false" title="Filtrer les critères non testés">
                    <div class="stat-value" id="statNonTeste"><?= $counts['non-testé'] ?></div>
                    <div class="stat-label">Critères<br>non testés</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Layout principal : sidebar + contenu -->
<div class="audit-layout container-fluid px-0">
    <div class="d-flex">

        <!-- Sidebar gauche : navigation thématiques -->
        <aside class="audit-sidebar">
            <div class="sidebar-inner">
                <h2 class="sidebar-title">Thématiques</h2>
                <nav class="sidebar-nav" id="thematicNav">
                    <?php foreach (THEMATICS as $tid => $tname):
                        $comp = $thematic_completion[$tid] ?? 0;
                        $count_in_thematic = count($by_thematic[$tid] ?? []);
                        $tested_in_thematic = count(array_filter($by_thematic[$tid] ?? [], fn($c) => ($c['status'] ?? 'non-testé') !== 'non-testé'));
                    ?>
                    <a href="#thematic-<?= $tid ?>" class="sidebar-link" data-thematic="<?= $tid ?>">
                        <span class="sidebar-link-num"><?= $tid ?></span>
                        <span class="sidebar-link-name"><?= esc($tname) ?></span>
                        <span class="sidebar-link-count ms-auto text-muted small"><?= $tested_in_thematic ?>/<?= $count_in_thematic ?></span>
                        <div class="sidebar-progress">
                            <div class="sidebar-progress-bar" id="sidebarProgress-<?= $tid ?>"
                                 style="width:<?= $comp ?>%"></div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </nav>

                <div class="sidebar-footer mt-3">
                    <?php if (!$is_done): ?>
                    <button class="btn btn-indigo w-100" id="btnFinishAudit">
                        <i class="bi bi-check-circle me-1"></i>Terminer l'audit
                    </button>
                    <?php else: ?>
                    <a href="report.php?id=<?= esc($audit_id) ?>" class="btn btn-outline-indigo w-100" target="_blank" rel="noopener">
                        <i class="bi bi-file-earmark-bar-graph me-1"></i>Voir le rapport
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </aside>

        <!-- Contenu principal -->
        <div class="audit-main" id="auditMain">

            <?php foreach (THEMATICS as $tid => $tname):
                $thematic_crits = $by_thematic[$tid] ?? [];
                if (empty($thematic_crits)) continue;
                $t_comp = $thematic_completion[$tid] ?? 0;
                $t_total = count($thematic_crits);
                $t_tested = count(array_filter($thematic_crits, fn($c) => ($c['status'] ?? 'non-testé') !== 'non-testé'));
            ?>

            <!-- ══ Thématique <?= $tid ?> ═══════════════════════════════════════════ -->
            <section id="thematic-<?= $tid ?>" class="thematic-section">
                <div class="thematic-header">
                    <div class="d-flex align-items-center gap-3 flex-wrap">
                        <span class="thematic-num"><?= $tid ?></span>
                        <h2 class="thematic-title mb-0"><?= esc($tname) ?></h2>
                        <div class="d-flex align-items-center gap-2 ms-auto">
                            <div class="progress" style="width:80px;height:6px;" role="progressbar"
                                 aria-valuenow="<?= $t_comp ?>" aria-valuemin="0" aria-valuemax="100">
                                <div class="progress-bar bg-indigo thematic-progress-bar" id="thematicProgressBar-<?= $tid ?>"
                                     style="width:<?= $t_comp ?>%"></div>
                            </div>
                            <span class="text-muted small" id="thematicProgressTxt-<?= $tid ?>"><?= $t_tested ?>/<?= $t_total ?></span>
                        </div>
                    </div>
                </div>

                <?php foreach ($thematic_crits as $i => $criterion):
                    $cid = $criterion['id'];
                    $ref = $ref_criteria[$cid] ?? [];
                    $status = $criterion['status'] ?? 'non-testé';
                    $comment = $criterion['comment'] ?? '';
                    $priority   = $ref['priority']   ?? $criterion['priority']   ?? 'Modéré';
                    $difficulty = $ref['difficulty'] ?? $criterion['difficulty'] ?? 'Moyen';
                    $title = $ref['title'] ?? "Critère {$cid}";
                    $target = $ref['target'] ?? '';
                    $jobs = $ref['jobs'] ?? [];
                    $objective = $ref['objective'] ?? '';
                    $implementation = $ref['implementation'] ?? '';
                    $control = $ref['control'] ?? '';

                    $card_class = match($status) {
                        'conforme'       => 'criterion-card-conforme',
                        'non-conforme'   => 'criterion-card-nonconforme',
                        'non-applicable' => 'criterion-card-na',
                        default          => '',
                    };
                    $collapse_id = 'criterion-' . str_replace('.', '-', $cid);
                    $comment_id  = 'comment-' . str_replace('.', '-', $cid);
                    $detail_id   = 'detail-'   . str_replace('.', '-', $cid);
                    $action_id   = 'action-'   . str_replace('.', '-', $cid);
                    $action_text = $criterion['action_text'] ?? '';
                    $action_who  = $criterion['action_who']  ?? [];
                    $action_when = $criterion['action_when'] ?? '';
                    $action_easy = $criterion['action_easy'] ?? false;
                    $has_action  = trim($action_text) !== '' || !empty($action_who) || $action_when !== '' || $action_easy;
                ?>

                <div class="criterion-card <?= $card_class ?>" id="card-<?= str_replace('.', '-', $cid) ?>"
                     data-criterion-id="<?= esc($cid) ?>" data-status="<?= esc($status) ?>">

                    <!-- En-tête du critère -->
                    <div class="criterion-header">
                        <div class="d-flex align-items-start gap-3 w-100">
                            <span class="criterion-id"><?= esc($cid) ?></span>
                            <h3 class="criterion-title flex-grow-1 mb-0"><?= esc($title) ?></h3>
                            <div class="d-flex align-items-center gap-1 flex-shrink-0">
                                <?= priority_badge($priority) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Boutons de statut -->
                    <div class="criterion-status-row">
                        <div class="status-buttons" role="group" aria-label="Statut du critère <?= esc($cid) ?>">
                            <input type="radio" class="btn-check" name="status-<?= str_replace('.', '-', $cid) ?>"
                                   id="status-conforme-<?= str_replace('.', '-', $cid) ?>"
                                   value="conforme" <?= $status === 'conforme' ? 'checked' : '' ?>
                                   autocomplete="off">
                            <label class="btn btn-status btn-status-conforme"
                                   for="status-conforme-<?= str_replace('.', '-', $cid) ?>">
                                <i class="bi bi-check-circle me-1"></i>Conforme
                            </label>

                            <input type="radio" class="btn-check" name="status-<?= str_replace('.', '-', $cid) ?>"
                                   id="status-nonconforme-<?= str_replace('.', '-', $cid) ?>"
                                   value="non-conforme" <?= $status === 'non-conforme' ? 'checked' : '' ?>
                                   autocomplete="off">
                            <label class="btn btn-status btn-status-nonconforme"
                                   for="status-nonconforme-<?= str_replace('.', '-', $cid) ?>">
                                <i class="bi bi-x-circle me-1"></i>Non conforme
                            </label>

                            <input type="radio" class="btn-check" name="status-<?= str_replace('.', '-', $cid) ?>"
                                   id="status-na-<?= str_replace('.', '-', $cid) ?>"
                                   value="non-applicable" <?= $status === 'non-applicable' ? 'checked' : '' ?>
                                   autocomplete="off">
                            <label class="btn btn-status btn-status-na"
                                   for="status-na-<?= str_replace('.', '-', $cid) ?>">
                                <i class="bi bi-dash-circle me-1"></i>Non applicable
                            </label>
                        </div>

                    </div>

                    <!-- Accordéon commentaire -->
                    <div class="criterion-accordions">
                        <div class="accordion-item border-0">
                            <h4 class="accordion-header">
                                <button class="criterion-accordion-btn <?= !empty($comment) ? 'has-content' : '' ?>"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $comment_id ?>"
                                        aria-expanded="<?= !empty($comment) ? 'true' : 'false' ?>"
                                        aria-controls="<?= $comment_id ?>">
                                    <i class="bi bi-chat-left-text me-2"></i>
                                    Commentaire
                                    <?php if (!empty($comment)): ?>
                                        <span class="badge bg-indigo ms-2" style="font-size:0.65rem;">Renseigné</span>
                                    <?php endif; ?>
                                </button>
                            </h4>
                            <div id="<?= $comment_id ?>" class="collapse <?= !empty($comment) ? 'show' : '' ?>">
                                <div class="p-3">
                                    <textarea class="form-control criterion-comment"
                                              data-criterion-id="<?= esc($cid) ?>"
                                              rows="4"
                                              aria-label="Commentaire pour le critère <?= esc($cid) ?> — <?= esc($title) ?>"
                                              placeholder="Vos observations, justifications, recommandations… (apparaîtront dans le rapport public)"><?= esc($comment) ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Accordéon actions -->
                        <div class="accordion-item border-0">
                            <h4 class="accordion-header">
                                <button class="criterion-accordion-btn <?= $has_action ? 'has-content' : '' ?>"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $action_id ?>"
                                        aria-expanded="<?= $has_action ? 'true' : 'false' ?>"
                                        aria-controls="<?= $action_id ?>"
                                        data-accordion-type="actions">
                                    <i class="bi bi-lightning me-2"></i>
                                    Actions
                                    <?php if ($has_action): ?>
                                        <span class="badge bg-indigo ms-2" style="font-size:0.65rem;">Renseigné</span>
                                    <?php endif; ?>
                                </button>
                            </h4>
                            <div id="<?= $action_id ?>" class="collapse <?= $has_action ? 'show' : '' ?>">
                                <div class="p-3 d-flex flex-column gap-3">

                                    <div>
                                        <label class="form-label small fw-semibold mb-1" for="action-text-<?= str_replace('.', '-', $cid) ?>">
                                            <i class="bi bi-list-task me-1 text-muted"></i>Actions à mener
                                        </label>
                                        <textarea class="form-control criterion-comment criterion-action-text"
                                                  data-criterion-id="<?= esc($cid) ?>"
                                                  id="action-text-<?= str_replace('.', '-', $cid) ?>"
                                                  rows="3"
                                                  placeholder="Décrivez les actions correctives ou préventives à mettre en place…"><?= esc($action_text) ?></textarea>
                                    </div>

                                    <div class="form-check">
                                        <input type="checkbox"
                                               class="form-check-input criterion-action-easy"
                                               data-criterion-id="<?= esc($cid) ?>"
                                               id="action-easy-<?= str_replace('.', '-', $cid) ?>"
                                               <?= $action_easy ? 'checked' : '' ?>>
                                        <label class="form-check-label small fw-semibold" for="action-easy-<?= str_replace('.', '-', $cid) ?>">
                                            <i class="bi bi-stars me-1 text-muted"></i>Facile à corriger
                                        </label>
                                    </div>

                                    <div>
                                        <label class="form-label small fw-semibold mb-1" for="action-who-<?= str_replace('.', '-', $cid) ?>">
                                            <i class="bi bi-person-fill me-1 text-muted"></i>Qui fait ?
                                        </label>
                                        <div class="action-who-wrapper" data-criterion-id="<?= esc($cid) ?>">
                                            <div class="action-tags-display d-flex flex-wrap gap-1 mb-1"></div>
                                            <div class="position-relative">
                                                <input type="text"
                                                       class="form-control form-control-sm criterion-action-who-input"
                                                       id="action-who-<?= str_replace('.', '-', $cid) ?>"
                                                       placeholder="Ajouter une personne ou un rôle… (Entrée pour valider)"
                                                       autocomplete="off">
                                                <div class="action-who-suggestions dropdown-menu w-100 py-1"></div>
                                            </div>
                                            <input type="hidden"
                                                   class="criterion-action-who"
                                                   data-criterion-id="<?= esc($cid) ?>"
                                                   value="<?= esc(json_encode($action_who, JSON_UNESCAPED_UNICODE)) ?>">
                                        </div>
                                    </div>

                                    <div>
                                        <label class="form-label small fw-semibold mb-1" for="action-when-<?= str_replace('.', '-', $cid) ?>">
                                            <i class="bi bi-calendar-month me-1 text-muted"></i>Pour quand ?
                                        </label>
                                        <input type="month"
                                               class="form-control form-control-sm criterion-action-when"
                                               data-criterion-id="<?= esc($cid) ?>"
                                               id="action-when-<?= str_replace('.', '-', $cid) ?>"
                                               value="<?= esc($action_when) ?>"
                                               style="max-width:180px;">
                                    </div>

                                </div>
                            </div>
                        </div>

                        <!-- Accordéon détails du critère -->
                        <?php if ($objective || $implementation || $control): ?>
                        <div class="accordion-item border-0">
                            <h4 class="accordion-header">
                                <button class="criterion-accordion-btn"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#<?= $detail_id ?>"
                                        aria-expanded="false"
                                        aria-controls="<?= $detail_id ?>">
                                    <i class="bi bi-book me-2"></i>
                                    Documentation du critère
                                </button>
                            </h4>
                            <div id="<?= $detail_id ?>" class="collapse">
                                <div class="criterion-detail-body">
                                    <?php if ($objective): ?>
                                    <div class="criterion-detail-section">
                                        <h5 class="criterion-detail-title">
                                            <i class="bi bi-bullseye me-2 text-indigo"></i>Objectif
                                        </h5>
                                        <div class="criterion-detail-text"><?= nl2p($objective) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($implementation): ?>
                                    <div class="criterion-detail-section">
                                        <h5 class="criterion-detail-title">
                                            <i class="bi bi-tools me-2 text-indigo"></i>Mise en œuvre
                                        </h5>
                                        <div class="criterion-detail-text"><?= nl2p($implementation) ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($control): ?>
                                    <div class="criterion-detail-section">
                                        <h5 class="criterion-detail-title">
                                            <i class="bi bi-clipboard2-check me-2 text-indigo"></i>Moyen de test ou de contrôle
                                        </h5>
                                        <div class="criterion-detail-text"><?= nl2p($control) ?></div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Pied du critère : Cible + Métiers + Difficulté -->
                    <div class="criterion-footer">
                        <?php if ($target): ?>
                        <div class="criterion-meta-item">
                            <span class="criterion-meta-label">Cible :</span>
                            <span class="criterion-meta-value"><?= esc($target) ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($jobs)): ?>
                        <div class="criterion-meta-item">
                            <span class="criterion-meta-label">Métiers concernés :</span>
                            <span class="criterion-meta-value">
                                <?php foreach ($jobs as $job): ?>
                                    <span class="badge badge-job"><?= esc($job) ?></span>
                                <?php endforeach; ?>
                            </span>
                        </div>
                        <?php endif; ?>
                        <div class="criterion-meta-item">
                            <span class="criterion-meta-label">Difficulté :</span>
                            <?= difficulty_badge($difficulty) ?>
                        </div>
                    </div>

                </div>
                <?php endforeach; ?>

            </section>
            <?php endforeach; ?>

            <!-- Bouton terminer l'audit -->
            <?php if (!$is_done): ?>
            <div class="text-center py-5">
                <p class="text-muted">Tous les critères ont été évalués ? Vous pouvez clôturer l'audit.</p>
                <button class="btn btn-lg btn-indigo" id="btnFinishAuditBottom">
                    <i class="bi bi-check-circle me-2"></i>Terminer l'audit et voir le rapport
                </button>
            </div>
            <?php else: ?>
            <div class="text-center py-5">
                <div class="alert alert-success d-inline-flex align-items-center gap-2">
                    <i class="bi bi-check-circle-fill"></i>
                    <span>Cet audit est <strong>terminé</strong>.</span>
                    <a href="report.php?id=<?= esc($audit_id) ?>" class="btn btn-success btn-sm ms-2" target="_blank" rel="noopener">Voir le rapport</a>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

</main>

<!-- Modal : Modifier les informations de l'audit -->
<div class="modal fade" id="modalEditAudit" tabindex="-1" aria-labelledby="modalEditAuditLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h2 class="modal-title h5 fw-bold" id="modalEditAuditLabel">
                    <i class="bi bi-pencil-square text-indigo me-2"></i>Modifier l'audit
                </h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fermer"></button>
            </div>
            <div class="modal-body pt-3">
                <form id="formEditAudit" novalidate>
                    <div class="mb-3">
                        <label for="editProjectName" class="form-label fw-medium">Nom du projet <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editProjectName"
                               value="<?= esc($audit['project']['name']) ?>" required>
                        <div class="invalid-feedback">Le nom du projet est requis.</div>
                    </div>
                    <div class="mb-3">
                        <label for="editProjectUrl" class="form-label fw-medium">URL du service audité <span class="text-danger">*</span></label>
                        <input type="url" class="form-control" id="editProjectUrl"
                               value="<?= esc($audit['project']['url'] ?? '') ?>"
                               placeholder="https://www.exemple.fr" required>
                        <div class="invalid-feedback">L'URL du service est requise.</div>
                    </div>
                    <div class="mb-3">
                        <label for="editAuditorName" class="form-label fw-medium">Nom de l'auditeur·ice <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="editAuditorName"
                               value="<?= esc($audit['auditor']['name'] ?? '') ?>" required>
                        <div class="invalid-feedback">Le nom de l'auditeur·ice est requis.</div>
                    </div>
                    <div id="editFormError" class="alert alert-danger d-none" role="alert"></div>
                </form>

                <hr class="my-3">

                <div class="mb-2">
                    <label for="importXlsxFile" class="form-label fw-medium">
                        <i class="bi bi-file-earmark-spreadsheet me-1"></i>Importer depuis un fichier tableur (XLSX/ODS)
                    </label>
                    <input type="file" class="form-control" id="importXlsxFile"
                           accept=".xlsx,.ods,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.oasis.opendocument.spreadsheet">
                    <div class="form-text">Remplace les informations du projet, de l'auditeur·ice et les critères par les données du tableau (Excel ou LibreOffice).</div>
                    <button type="button" class="btn btn-outline-secondary mt-2" id="btnImportXlsx">
                        <span class="spinner-border spinner-border-sm me-1 d-none" id="importXlsxSpinner"></span>
                        Importer le fichier
                    </button>
                    <div id="importXlsxStatus" class="small mt-2"></div>
                </div>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="button" class="btn btn-indigo" id="btnSaveEditAudit">
                    <span class="spinner-border spinner-border-sm me-1 d-none" id="editSpinner"></span>
                    Enregistrer
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Bouton retour en haut -->
<button id="backToTop" class="back-to-top" aria-label="Retour en haut de la page" title="Retour en haut">
    <i class="bi bi-chevron-up"></i>
</button>

<!-- Données pour JavaScript -->
<script>
const AUDIT_ID = <?= json_encode($audit_id) ?>;
const AUDIT_STATUS = <?= json_encode($audit['status']) ?>;
const TOTAL_CRITERIA = <?= count($audit['criteria']) ?>;
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script src="assets/js/audit.js"></script>
<script>
(function () {
    'use strict';

    document.getElementById('btnSaveEditAudit').addEventListener('click', async () => {
        const nameInput  = document.getElementById('editProjectName');
        const urlInput   = document.getElementById('editProjectUrl');
        const errorDiv   = document.getElementById('editFormError');
        const spinner    = document.getElementById('editSpinner');
        const form       = document.getElementById('formEditAudit');

        form.classList.add('was-validated');
        errorDiv.classList.add('d-none');

        const auditorInput = document.getElementById('editAuditorName');

        nameInput.setCustomValidity(!nameInput.value.trim() ? 'required' : '');
        urlInput.setCustomValidity(!urlInput.value.trim() ? 'required' : '');
        auditorInput.setCustomValidity(!auditorInput.value.trim() ? 'required' : '');

        if (!nameInput.value.trim() || !urlInput.value.trim() || !auditorInput.value.trim()) {
            return;
        }

        spinner.classList.remove('d-none');

        try {
            await apiRequest('POST', 'api.php?action=update_audit', {
                id:      AUDIT_ID,
                project: { name: nameInput.value.trim(), url: urlInput.value.trim() },
                auditor: { name: document.getElementById('editAuditorName').value.trim() },
            });

            // Mettre à jour le titre affiché sans recharger la page
            document.getElementById('auditProjectName').textContent = nameInput.value.trim();
            document.title = `Audit — ${nameInput.value.trim()} — RGESN V2`;

            bootstrap.Modal.getInstance(document.getElementById('modalEditAudit')).hide();
        } catch (err) {
            errorDiv.textContent = err.message || 'Une erreur est survenue.';
            errorDiv.classList.remove('d-none');
        } finally {
            spinner.classList.add('d-none');
        }
    });

    // ── Import XLSX ─────────────────────────────────────────────────────────
    document.getElementById('btnImportXlsx').addEventListener('click', async () => {
        const fileInput = document.getElementById('importXlsxFile');
        const statusDiv = document.getElementById('importXlsxStatus');
        const spinner   = document.getElementById('importXlsxSpinner');
        const btn       = document.getElementById('btnImportXlsx');

        statusDiv.textContent = '';
        statusDiv.className = 'small mt-2';

        if (!fileInput.files || !fileInput.files.length) {
            statusDiv.classList.add('text-danger');
            statusDiv.textContent = 'Veuillez sélectionner un fichier XLSX ou ODS.';
            return;
        }

        const formData = new FormData();
        formData.append('id', AUDIT_ID);
        formData.append('file', fileInput.files[0]);
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            formData.append('csrf_token', csrfMeta.content);
        }

        btn.disabled = true;
        spinner.classList.remove('d-none');
        statusDiv.classList.add('text-muted');
        statusDiv.textContent = 'Importation en cours…';

        try {
            const response = await fetch('api.php?action=import_audit_xlsx', {
                method: 'POST',
                headers: csrfMeta ? { 'X-CSRF-Token': csrfMeta.content } : {},
                body: formData,
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Échec de l\'importation');
            }
            statusDiv.className = 'small mt-2 text-success';
            statusDiv.textContent = 'Importation réussie. Rechargement de la page…';
            window.setTimeout(() => window.location.reload(), 800);
        } catch (err) {
            statusDiv.className = 'small mt-2 text-danger';
            statusDiv.textContent = err.message || 'Une erreur est survenue.';
        } finally {
            btn.disabled = false;
            spinner.classList.add('d-none');
        }
    });

    document.getElementById('modalEditAudit').addEventListener('hidden.bs.modal', () => {
        document.getElementById('formEditAudit').classList.remove('was-validated');
        document.getElementById('editFormError').classList.add('d-none');
    });
})();
</script>

</body>
</html>
