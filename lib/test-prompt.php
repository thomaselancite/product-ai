<?php
/*
 * ElanCité Product AI — test en ligne de commande : affiche les consignes construites pour une tâche,
 * sans appeler Claude et sans rien écrire. Utilisable seulement en ligne de commande (dossier lib/ fermé au web).
 *
 * Exemples :
 *   php lib/test-prompt.php --tache=ask --question="Quel est le délai de réparation d'un EVOCITY ?"
 *   php lib/test-prompt.php --tache=ask --questions=ma-section-questions.md
 *   php lib/test-prompt.php --tache=brief --marche=Allemagne --idee="Article sur EVOCITY pour les mairies"
 *
 * Options :
 *   --tache=ask|brief|review   tâche (ask = Questions ; par défaut ask)
 *   --marche=…                 marché pour brief et review (libellé de l'interface, ex. Allemagne) ;
 *                              sans objet pour ask : Claude déduit le marché de la question
 *   --question=… / --idee=… / --doc=FICHIER   contenu de la demande
 *   --questions=FICHIER        remplace la section Questions de Consignes Claude (texte copié de Notion)
 *   --notion                   relit Notion (config.php nécessaire) au lieu de la base en cache
 *   --base                     affiche aussi la base de connaissance transmise
 *
 * Consignes utilisées : celles de la base en cache (data/kb.php, même expirée), sinon Notion si config.php existe,
 * sinon les consignes par défaut de lib/consignes/.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('ANTOINE', 1);
require __DIR__ . '/core.php';
require __DIR__ . '/notion.php';
require __DIR__ . '/prompts.php';

$o = getopt('', ['tache:', 'marche:', 'question:', 'idee:', 'doc:', 'questions:', 'notion', 'base']);
$task = $o['tache'] ?? 'ask';
$haveConfig = is_file(__DIR__ . '/../config.php');

try {
    $kb = null; $source = '';
    if (!isset($o['notion'])) {
        $cached = antoine_read('kb', null);
        if ($cached && isset($cached['kb'])) { $kb = $cached['kb']; $source = 'base en cache (data/kb.php), lue le ' . ($kb['loaded_at'] ?? '?'); }
    }
    if (!$kb && $haveConfig) { $kb = antoine_ia_load_kb(isset($o['notion'])); $source = 'base relue dans Notion'; }
    if (!$kb) { $kb = ['pages' => [], 'brand' => null, 'transverses' => [], 'consignes' => null]; $source = 'aucune base (ni cache ni config.php) : consignes par défaut de lib/consignes/'; }

    $consignes = is_array($kb['consignes'] ?? null) && !isset($kb['consignes']['erreur']) ? $kb['consignes'] : [];
    if (isset($o['questions'])) {
        $t = @file_get_contents($o['questions']);
        if ($t === false) throw new AntoineError('antoine_input', 'Fichier illisible : ' . $o['questions']);
        $consignes['questions'] = trim(antoine_ia_unescape($t)); // même désespacement que la page Notion
        $source .= ' ; section Questions remplacée par ' . $o['questions'];
    }
    $kbText = antoine_ia_kb_text($kb, true);

    $p = ['task' => $task,
          'turns' => [['role' => 'user', 'content' => $o['question'] ?? 'Question de test']],
          'ctx' => ['market' => $o['marche'] ?? '', 'idea' => $o['idee'] ?? ''],
          'doc' => isset($o['doc']) ? (string) @file_get_contents($o['doc']) : ''];
    $built = antoine_ia_build_request($task, $p, $kbText, $consignes);

    echo "Source : $source\n";
    echo 'Base transmise : ' . mb_strlen($kbText['text']) . ' caractères, ' . $kbText['used_count'] . " pages produits\n\n";
    echo "===== INSTRUCTIONS =====\n" . $built['instructions'] . "\n\n";
    foreach ($built['messages'] as $m) echo '===== MESSAGE (' . $m['role'] . ") =====\n" . $m['content'] . "\n\n";
    if (isset($o['base'])) echo "===== BASE DE CONNAISSANCE =====\n" . $kbText['text'] . "\n";
} catch (AntoineError $e) {
    fwrite(STDERR, 'Erreur : ' . $e->getMessage() . "\n");
    exit(1);
}
