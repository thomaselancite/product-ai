<?php
/* ElanCité Product AI — analyse de la base de connaissance (réservée à l'administrateur connecté via admin.php). */
define('ANTOINE', 1);
require __DIR__ . '/lib/core.php';
require __DIR__ . '/lib/notion.php';
require __DIR__ . '/lib/claude.php';
require __DIR__ . '/lib/prompts.php';

session_start();
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
if (empty($_SESSION['antoine_admin'])) { header('Location: admin.php'); exit; }

$c = antoine_config();
$msg = ''; $ok = true;

function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/** Empreinte du texte de la base tel que transmis à Claude (pages « À valider » comprises). */
function analyse_kb_hash($kb) { return sha1(antoine_ia_kb_text($kb, true)['text']); }

if (isset($_POST['run']) && hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    @set_time_limit(300);
    ignore_user_abort(true); // l'analyse va au bout (et sa consommation est comptée) même si la page est fermée
    $lock = fopen(antoine_data_path('analyse.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        $msg = 'Une analyse est déjà en cours. Patientez, puis rechargez la page.'; $ok = false;
    } else {
        try {
            if (!$c['anthropic_key'] || strpos($c['anthropic_key'], 'REMPLACER') !== false) throw new AntoineError('antoine_config', "La clé d'API Claude n'est pas configurée.", 500);
            $month = gmdate('Y-m');
            $usage = antoine_read('usage', []);
            if ((float) $c['monthly_budget'] > 0 && (float) ($usage[$month]['total'] ?? 0) >= (float) $c['monthly_budget']) {
                throw new AntoineError('antoine_budget', "Le budget mensuel de l'outil est atteint.", 429);
            }
            $kb = antoine_ia_load_kb(!empty($_POST['refresh']));
            $kbText = antoine_ia_kb_text($kb, true);
            if (!$kbText['used_count']) throw new AntoineError('antoine_no_pages', "Aucune page produit n'est disponible dans la base.", 400);
            $built = antoine_ia_build_analysis($kb['consignes'] ?? null);

            $res = antoine_ia_claude_call($kbText['text'], $built['instructions'], $built['messages'], $c);
            // La consommation est comptée même si la réponse n'est pas lisible
            antoine_update('usage', [], function ($u) use ($month, $res) {
                if (!isset($u[$month])) $u[$month] = ['total' => 0, 'agencies' => []];
                $u[$month]['total'] = round($u[$month]['total'] + $res['cost'], 6);
                $a = $u[$month]['agencies']['Admin (analyse)'] ?? ['calls' => 0, 'cost' => 0];
                $u[$month]['agencies']['Admin (analyse)'] = ['calls' => $a['calls'] + 1, 'cost' => round($a['cost'] + $res['cost'], 6)];
                krsort($u);
                return array_slice($u, 0, 12, true);
            });
            $data = antoine_ia_parse_json($res['text']);
            if ($data === null) throw new AntoineError('antoine_invalid_json', "La réponse de Claude n'a pas pu être lue (consommation comptée). Relancez l'analyse.", 502);

            [$report, $total, $warn] = antoine_ia_analysis_check($data, $kb);
            antoine_write('analyse', [
                'generated_at' => gmdate('c'), 'kb_loaded_at' => $kb['loaded_at'] ?? '', 'kb_hash' => sha1($kbText['text']),
                'model' => $c['model'], 'cost' => round($res['cost'], 4), 'pages' => $kbText['used_count'],
                'remarques' => $total, 'avertissements' => $warn, 'report' => $report,
            ]);
            $msg = "Analyse terminée : $total remarque(s), dont $warn à vérifier avec soin. Coût estimé : " . number_format($res['cost'], 2, ',', ' ') . ' $.';
        } catch (AntoineError $e) { $msg = $e->getMessage(); $ok = false; }
        flock($lock, LOCK_UN); fclose($lock);
    }
}

$saved = antoine_read('analyse', null);
$stale = false;
if ($saved) {
    $cache = antoine_read('kb', null);
    if ($cache && isset($cache['kb'])) $stale = analyse_kb_hash($cache['kb']) !== ($saved['kb_hash'] ?? '');
}

function warn_ref($v) { return $v === false ? ' <span class="warn" title="Page ou section introuvable dans la base">⚠ référence introuvable</span>' : ''; }
function ref_line($r) { return $r !== '' && $r !== null ? '<div class="ref">' . h($r) . '</div>' : ''; }
?><!DOCTYPE html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>ElanCité Product AI — analyse de la base</title>
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" href="favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<style>
body{font-family:"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#EEF1F3;color:#22272B;margin:0}
header{background:#2E3439;color:#fff;padding:16px 24px;border-bottom:6px solid #C8102E}
header h1{margin:0;font-size:20px}
header a{color:#fff;font-size:14px}
main{max-width:860px;margin:0 auto;padding:24px}
section{background:#fff;border:1px solid #D3D9DE;border-radius:6px;padding:18px 20px;margin-bottom:18px}
h2{font-size:17px;margin:0 0 12px}
.btn{border:0;border-radius:6px;padding:9px 16px;font-weight:700;background:#2E3439;color:#fff;cursor:pointer;font:inherit}
.msg{padding:10px 14px;border-left:4px solid #2E7D4F;background:#fff;margin-bottom:18px}
.msg.err{border-left-color:#B3261E}
.hint{color:#5E6A72;font-size:13px}
.item{border-top:1px solid #D3D9DE;padding:10px 0}
.item:first-of-type{border-top:0}
.ref{color:#5E6A72;font-size:13px;margin-top:2px}
.why{font-size:14px;margin-top:2px}
.warn{color:#B3261E;font-size:12px;font-weight:700}
.okv{color:#2E7D4F;font-size:12px;font-weight:700}
blockquote{margin:6px 0 0;padding:4px 10px;border-left:3px solid #D3D9DE;font-size:14px}
.tag{display:inline-block;font-size:12px;font-weight:700;border-radius:4px;padding:1px 7px;background:#EEF1F3;margin-right:6px}
.tag.forte{background:#F8D7DA}.tag.moyenne{background:#FFE9C7}
</style></head><body>
<header><h1>ElanCité Product AI — analyse de la base</h1> <a href="admin.php">← Administration</a></header>
<main>
<?php if ($msg): ?><div class="msg<?php echo $ok ? '' : ' err'; ?>"><?php echo h($msg); ?></div><?php endif; ?>

<section><h2>Analyse de la base</h2>
  <p>Claude examine la base entière (pages « À valider » comprises, pages « Obsolète » exclues) et propose des questions pour affiner la connaissance produit, des incohérences, des idées de compléments et les points encore ouverts. Ce sont des <strong>pistes à vérifier</strong> : rien n'est écrit dans Notion, et les corrections se traitent ensuite dans le projet Claude.</p>
  <form method="post" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Analyse en cours… (une à plusieurs minutes)';">
    <input type="hidden" name="csrf" value="<?php echo h($_SESSION['csrf'] ?? ''); ?>">
    <label><input type="checkbox" name="refresh" value="1" checked> Relire la base Notion avant l'analyse</label><br><br>
    <button class="btn" name="run" value="1">Lancer l'analyse</button>
    <span class="hint">Un appel à Claude, compté dans la consommation (« Admin (analyse) »).</span>
  </form>
</section>

<?php if (!$saved): ?>
  <section><p>Aucune analyse n'a encore été lancée.</p></section>
<?php else: $r = $saved['report']; ?>
  <section><h2>Dernier rapport</h2>
    <p class="hint">Généré le <?php echo h(date('d/m/Y à H:i', strtotime($saved['generated_at']))); ?> (UTC), sur <?php echo (int) $saved['pages']; ?> pages produit, modèle <?php echo h($saved['model']); ?>, coût estimé <?php echo number_format((float) $saved['cost'], 2, ',', ' '); ?> $. <?php echo (int) $saved['remarques']; ?> remarque(s), dont <?php echo (int) $saved['avertissements']; ?> à vérifier avec soin (⚠).</p>
    <?php if ($stale): ?><p class="warn">La base a été modifiée depuis ce rapport : relancez l'analyse pour tenir compte des changements.</p><?php endif; ?>
    <?php if ($r['synthese'] !== ''): ?><p><?php echo nl2br(h($r['synthese'])); ?></p><?php endif; ?>
    <p class="hint">⚠ signale une référence (page ou section) ou un extrait que le serveur n'a pas retrouvé dans la base : la remarque est peut-être inexacte. Les extraits sont comparés à la mise en forme près.</p>
  </section>

  <section><h2>Incohérences (<?php echo count($r['incoherences']); ?>)</h2>
  <?php if (!$r['incoherences']): ?><p class="hint">Aucune incohérence signalée.</p><?php endif; ?>
  <?php foreach ($r['incoherences'] as $i): $g = in_array($i['gravite'] ?? '', ['forte', 'moyenne', 'faible'], true) ? $i['gravite'] : 'faible'; ?>
    <div class="item"><span class="tag <?php echo h($g); ?>"><?php echo h($g); ?></span><?php echo h($i['description'] ?? ''); ?>
      <?php echo !empty($i['_verifiee']) ? ' <span class="okv">✓ extraits retrouvés</span>' : ' <span class="warn">⚠ à vérifier : au moins un extrait ou une référence est introuvable</span>'; ?>
      <?php foreach ($i['elements'] as $e): ?>
        <blockquote>« <?php echo h($e['extrait'] ?? ''); ?> »<?php echo ($e['_extrait_ok'] ?? true) === false ? ' <span class="warn">⚠ extrait introuvable</span>' : ''; ?>
          <?php echo ref_line($e['reference'] ?? ''); echo warn_ref($e['_ref_ok'] ?? null); ?></blockquote>
      <?php endforeach; ?></div>
  <?php endforeach; ?></section>

  <section><h2>Questions pour affiner (<?php echo count($r['questions']); ?>)</h2>
  <?php if (!$r['questions']): ?><p class="hint">Aucune question.</p><?php endif; ?>
  <?php foreach ($r['questions'] as $q): ?>
    <div class="item"><strong><?php echo h($q['question'] ?? ''); ?></strong>
      <div class="why"><?php echo h($q['pourquoi'] ?? ''); ?></div><?php echo ref_line($q['reference'] ?? ''); echo warn_ref($q['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>

  <section><h2>Idées de compléments (<?php echo count($r['complements']); ?>)</h2>
  <?php if (!$r['complements']): ?><p class="hint">Aucune idée.</p><?php endif; ?>
  <?php foreach ($r['complements'] as $q): ?>
    <div class="item"><strong><?php echo h($q['idee'] ?? ''); ?></strong>
      <div class="why"><?php echo h($q['pourquoi'] ?? ''); ?></div><?php echo ref_line($q['reference'] ?? ''); echo warn_ref($q['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>

  <section><h2>Points ouverts (<?php echo count($r['points_ouverts']); ?>)</h2>
  <?php if (!$r['points_ouverts']): ?><p class="hint">Aucun point ouvert.</p><?php endif; ?>
  <?php $labels = ['a_completer' => 'À compléter', 'point_a_lever' => "Point d'attention à lever", 'page_a_valider' => 'Page à valider'];
  foreach ($r['points_ouverts'] as $p): ?>
    <div class="item"><span class="tag"><?php echo h($labels[$p['type'] ?? ''] ?? 'Autre'); ?></span><?php echo h($p['element'] ?? ''); ?>
      <?php echo ref_line($p['reference'] ?? ''); echo warn_ref($p['_ref_ok'] ?? null); ?></div>
  <?php endforeach; ?></section>
<?php endif; ?>
</main></body></html>
