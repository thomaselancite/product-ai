<?php
if (!defined('ANTOINE')) { http_response_code(404); exit; }

/*
 * Suivi d'avancement des traitements longs de l'administration (analyse.php, cartographie.php).
 *
 * Un traitement s'exécute dans une requête POST (?action=run) qui écrit chaque étape dans data/<nom>.progress.php
 * (écriture atomique) ; la page lit cet état toutes les secondes (?action=progress). Rien ne dépend d'un flux
 * continu : une page rechargée en cours de traitement retrouve son état. Un verrou data/<nom>.lock.php évite
 * deux traitements simultanés. Les fichiers data/*.php ne sont ni versionnés ni déployés.
 */

function progress_json($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo antoine_json($data);
    exit;
}

/** Vrai si un traitement est en cours (verrou tenu par la requête qui l'exécute). */
function progress_running($name) {
    $lock = fopen(antoine_data_path($name . '.lock'), 'c');
    if (!$lock) return false;
    if (flock($lock, LOCK_EX | LOCK_NB)) { flock($lock, LOCK_UN); fclose($lock); return false; }
    fclose($lock);
    return true;
}

/** Écriture atomique : le lecteur ne voit jamais un fichier à moitié écrit. */
function progress_write($name, $d) {
    $f = antoine_data_path($name . '.progress'); $tmp = antoine_data_path($name . '.progress.tmp');
    $raw = "<?php exit; ?>\n" . antoine_json($d);
    if (@file_put_contents($tmp, $raw) === false || !@rename($tmp, $f)) @file_put_contents($f, $raw, LOCK_EX);
}

/** Avancement : étapes, état de chacune, pourcentage estimé. $defs = [[id, libellé], …]. */
class RunProgress {
    public $d;
    private $name;
    private $last = 0.0;
    function __construct($name, $defs, $token) {
        $this->name = $name;
        $steps = [];
        foreach ($defs as $s) $steps[] = ['id' => $s[0], 'label' => $s[1], 'state' => 'pending', 'detail' => '', 't0' => null, 't1' => null];
        $now = microtime(true);
        $this->d = ['run_id' => bin2hex(random_bytes(6)), 'token' => $token, 'status' => 'running', 'started' => $now, 'updated' => $now,
                    'percent' => 0, 'steps' => $steps, 'message' => '', 'error' => ''];
        $this->flush(true);
    }
    private function idx($id) { foreach ($this->d['steps'] as $i => $s) if ($s['id'] === $id) return $i; return -1; }
    function start($id, $detail = '') {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['state'] = 'running'; $this->d['steps'][$i]['detail'] = $detail; $this->d['steps'][$i]['t0'] = microtime(true);
        $this->flush(true);
    }
    function done($id, $detail, $pct) {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['state'] = 'done'; $this->d['steps'][$i]['detail'] = $detail; $this->d['steps'][$i]['t1'] = microtime(true);
        $this->d['percent'] = $pct;
        $this->flush(true);
    }
    function fail($id, $msg) {
        $i = $this->idx($id);
        if ($i >= 0) { $this->d['steps'][$i]['state'] = 'error'; $this->d['steps'][$i]['detail'] = $msg; $this->d['steps'][$i]['t1'] = microtime(true); }
        $this->d['status'] = 'error'; $this->d['error'] = $msg;
        $this->flush(true);
    }
    function finish($msg) { $this->d['status'] = 'done'; $this->d['percent'] = 100; $this->d['message'] = $msg; $this->flush(true); }
    /** Mise à jour fréquente (réception de la réponse) : écrite au plus une fois par seconde. */
    function tick($id, $detail, $pct) {
        $i = $this->idx($id); if ($i < 0) return;
        $this->d['steps'][$i]['detail'] = $detail; $this->d['percent'] = round($pct, 1);
        $this->flush(false);
    }
    function flush($force) {
        $now = microtime(true);
        if (!$force && $now - $this->last < 1.0) return;
        $this->last = $now; $this->d['updated'] = $now;
        progress_write($this->name, $this->d);
    }
}

/**
 * Marque « interrompu » un traitement resté « en cours » alors que plus aucun processus ne le tient.
 * Certains hébergements ne partagent pas le verrou entre requêtes : on exige aussi qu'aucune étape n'ait avancé depuis 150 s.
 */
function progress_mark_interrupted($name, $p) {
    $lock = fopen(antoine_data_path($name . '.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $p; // un traitement tourne vraiment
    if (microtime(true) - (float) ($p['updated'] ?? 0) < 150) { flock($lock, LOCK_UN); fclose($lock); return $p; }
    foreach ($p['steps'] as $i => $s) if ($s['state'] === 'running') { $p['steps'][$i]['state'] = 'error'; $p['steps'][$i]['t1'] = microtime(true); }
    $p['status'] = 'error';
    $p['error'] = "Traitement interrompu avant la fin (erreur du serveur ou délai de l'hébergeur dépassé). La consommation éventuelle de Claude est comptée dans admin.php.";
    progress_write($name, $p);
    flock($lock, LOCK_UN); fclose($lock);
    return $p;
}

/** Si PHP s'arrête en plein traitement (erreur fatale, délai, mémoire), la cause est inscrite dans le panneau. */
function progress_guard(RunProgress $P, $label) {
    register_shutdown_function(function () use ($P, $label) {
        if (($P->d['status'] ?? '') !== 'running') return;
        $e = error_get_last(); $step = '';
        foreach ($P->d['steps'] as $st) if ($st['state'] === 'running') $step = $st['id'];
        $why = $e ? ($e['message'] . ' (' . basename($e['file']) . ':' . $e['line'] . ')') : 'arrêt sans erreur PHP';
        $why .= sprintf(' — après %d s, mémoire max %d Mo, max_execution_time %s s', microtime(true) - $P->d['started'], memory_get_peak_usage(true) / 1048576, ini_get('max_execution_time'));
        error_log("ElanCité Product AI ($label) arrêt brutal : " . $why);
        $P->fail($step, "Le script s'est arrêté pendant le traitement : " . $why);
    });
}

/**
 * Début d'une requête de traitement (?action=run) : jeton CSRF, délai, verrou. Répond 403 ou 409 et s'arrête sinon.
 * Renvoie [RunProgress, verrou] ; le verrou est à libérer avec progress_end_run().
 */
function progress_start_run($name, $defs, $csrf, $label) {
    if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) progress_json(['ok' => false, 'error' => 'Requête refusée (jeton de sécurité invalide). Rechargez la page.'], 403);
    @set_time_limit(300);
    ignore_user_abort(true); // le traitement va au bout (et sa consommation est comptée) même si la page est fermée
    session_write_close();   // libère la session : la page peut interroger l'avancement pendant le traitement
    $lock = fopen(antoine_data_path($name . '.lock'), 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) progress_json(['ok' => false, 'busy' => true, 'error' => 'Un traitement est déjà en cours.'], 409);
    $token = substr(preg_replace('/[^a-z0-9]/i', '', (string) ($_POST['token'] ?? '')), 0, 32);
    $P = new RunProgress($name, $defs, $token);
    progress_guard($P, $label);
    return [$P, $lock];
}
function progress_end_run($lock) { flock($lock, LOCK_UN); fclose($lock); }

/** Réponse de ?action=progress. */
function progress_poll($name) {
    session_write_close();
    $p = antoine_read($name . '.progress', null);
    if (is_array($p) && ($p['status'] ?? '') === 'running' && !progress_running($name)) $p = progress_mark_interrupted($name, $p);
    progress_json(['progress' => is_array($p) ? $p : null, 'server_time' => microtime(true)]);
}

/** Style du panneau d'avancement. */
function progress_css() {
    return <<<'CSS'
#live{display:none}
.bar{height:12px;background:#E3E8EC;border-radius:6px;overflow:hidden;margin:6px 0 4px}
.bar>div{height:100%;width:0;background:#2E7D4F;transition:width .6s ease}
.bar.err>div{background:#B3261E}
.steps{list-style:none;margin:14px 0 0;padding:0}
.steps li{display:flex;gap:10px;padding:7px 0;border-top:1px solid #EEF1F3;font-size:14px}
.steps li:first-child{border-top:0}
.ico{flex:0 0 22px;text-align:center;font-weight:700}
.ico.pending{color:#9AA5AD}.ico.done{color:#2E7D4F}.ico.error{color:#B3261E}
.spin{display:inline-block;width:13px;height:13px;border:2px solid #D3D9DE;border-top-color:#2E3439;border-radius:50%;animation:sp .8s linear infinite;vertical-align:-2px}
@keyframes sp{to{transform:rotate(360deg)}}
.steps .lbl{font-weight:600}
.steps .det{color:#5E6A72;font-size:13px;margin-top:1px}
.steps .dur{margin-left:auto;color:#5E6A72;font-size:13px;white-space:nowrap}
li.pending .lbl{color:#9AA5AD;font-weight:400}
CSS;
}

/** Bloc HTML du panneau d'avancement ($running = titre initial). */
function progress_panel_html($runningTitle) {
    return '<div id="live" aria-live="polite">'
        . '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:6px"><strong id="live-title">' . htmlspecialchars($runningTitle, ENT_QUOTES, 'UTF-8') . '</strong><span class="hint" id="live-time"></span></div>'
        . '<div class="bar" id="live-bar"><div id="live-fill"></div></div>'
        . '<div class="hint" id="live-pct"></div>'
        . '<ul class="steps" id="live-steps"></ul>'
        . '<div id="live-err" class="msg err" style="display:none;margin:12px 0 0"></div>'
        . '</div>';
}

/**
 * Script du panneau : lancement (POST), suivi toutes les secondes, reprise après rechargement.
 * Le formulaire a l'id « run-form » (data-csrf, data-running), un bouton « run-btn » et une case « refresh ».
 * $t : endpoint, running, done, fail, btn_running, btn_again, pct_hint, busy.
 */
function progress_js(array $t) {
    $cfg = json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
    return <<<JS
(function () {
  var T = $cfg;
  var form = document.getElementById('run-form'), btn = document.getElementById('run-btn');
  var live = document.getElementById('live'), fill = document.getElementById('live-fill'), bar = document.getElementById('live-bar');
  var pct = document.getElementById('live-pct'), list = document.getElementById('live-steps'), title = document.getElementById('live-title');
  var timeEl = document.getElementById('live-time'), errBox = document.getElementById('live-err');
  var csrf = form.getAttribute('data-csrf'), token = null, timer = null, offset = 0, started = 0, finished = false;

  function esc(s) { var d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
  function fmt(sec) { sec = Math.max(0, Math.round(sec)); return sec < 60 ? sec + ' s' : Math.floor(sec / 60) + ' min ' + ('0' + (sec % 60)).slice(-2) + ' s'; }
  function nowServer() { return (Date.now() + offset) / 1000; }

  function render(p) {
    live.style.display = 'block';
    started = p.started;
    fill.style.width = Math.max(2, p.percent) + '%';
    bar.className = 'bar' + (p.status === 'error' ? ' err' : '');
    pct.textContent = Math.round(p.percent) + ' % ' + T.pct_hint;
    title.textContent = p.status === 'done' ? T.done : (p.status === 'error' ? T.fail : T.running);
    var html = '';
    p.steps.forEach(function (s) {
      var ico = s.state === 'done' ? '✓' : s.state === 'error' ? '✗' : s.state === 'running' ? '<span class="spin"></span>' : '○';
      var dur = '';
      if (s.t0 && s.t1) dur = fmt(s.t1 - s.t0);
      else if (s.t0) dur = fmt(nowServer() - s.t0);
      html += '<li class="' + s.state + '"><span class="ico ' + s.state + '">' + ico + '</span><div><div class="lbl">' + esc(s.label) + '</div>'
            + (s.detail ? '<div class="det">' + esc(s.detail) + '</div>' : '') + '</div><span class="dur">' + dur + '</span></li>';
    });
    list.innerHTML = html;
    if (p.status === 'error') { errBox.style.display = 'block'; errBox.textContent = p.error || 'Erreur.'; } else { errBox.style.display = 'none'; }
  }

  function tickClock() { if (started && !finished) timeEl.textContent = 'Temps écoulé : ' + fmt(nowServer() - started); }

  function poll() {
    return fetch(T.endpoint + '?action=progress', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) throw new Error('session'); return r.json(); })
      .then(function (j) {
        offset = j.server_time * 1000 - Date.now();
        var p = j.progress;
        if (!p || (token && p.token !== token)) return;       // pas encore le nôtre : on attend
        render(p);
        if (p.status !== 'running') {
          finished = true; clearInterval(timer);
          timeEl.textContent = 'Durée : ' + fmt(p.updated - p.started);
          if (p.status === 'done') setTimeout(function () { location.reload(); }, 1500);
          else { btn.disabled = false; btn.textContent = T.btn_again; form.style.display = ''; }
        }
      })
      .catch(function (e) {
        if (e && e.message === 'session') { errBox.style.display = 'block'; errBox.textContent = 'Session expirée : reconnectez-vous via admin.php, puis relancez la page.'; live.style.display = 'block'; clearInterval(timer); }
      });
  }

  function watch() {
    finished = false;
    clearInterval(timer);
    timer = setInterval(function () { poll(); tickClock(); }, 1000);
    poll();
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    btn.disabled = true; btn.textContent = T.btn_running;
    errBox.style.display = 'none';
    token = Math.random().toString(36).slice(2) + Date.now().toString(36);
    var body = new URLSearchParams();
    body.set('csrf', csrf); body.set('token', token);
    if (form.elements['refresh'].checked) body.set('refresh', '1');
    live.style.display = 'block';
    title.textContent = 'Démarrage…';
    watch();
    // La requête dure aussi longtemps que le traitement ; l'avancement vient du suivi ci-dessus.
    fetch(T.endpoint + '?action=run', { method: 'POST', credentials: 'same-origin', body: body })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.busy) { token = null; title.textContent = T.busy; return; }
        if (j && j.ok === false && j.error) { errBox.style.display = 'block'; errBox.textContent = j.error; }
        poll();
      })
      .catch(function () { /* coupure de la réponse (délai du serveur) : le suivi dit ce qu'il en est */ });
  });

  if (form.getAttribute('data-running') === '1') {   // page ouverte pendant un traitement : on reprend le suivi
    btn.disabled = true; btn.textContent = T.btn_running;
    watch();
  }
})();
JS;
}
