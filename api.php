<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
mb_internal_encoding('UTF-8');

$cfg = require __DIR__ . '/config.php';

const MESSAGES = [
    'safe'    => 'آمن علي الحمل',
    'unsafe'  => 'غير آمن علي الحمل',
    'consult' => 'استشر الطبيب',
    'unknown' => 'لم أجد بيانات كافية — استشر الطبيب',
];

/* عبارات تدل على التعارض مع الحمل (تُفحص أولاً) */
const RX_UNSAFE = [
    '/contraindicated (?:for use |for women )?(?:in|during|for)[^.]{0,40}pregnan/u',
    '/pregnan[a-z]*[^.]{0,40}(?:is |are )?(?:contraindicated|must be avoided)/u',
    '/pregnancy (?:risk )?category[^a-z0-9]{0,6}[dx]\b/u',
    '/(?:can|may|will|could) cause fetal harm/u',
    '/teratogenic/u',
    '/birth defects/u',
    '/(?:should|must) not be (?:used|taken|given)[^.]{0,30}pregnan/u',
    '/(?:avoid|do not use|not recommended)[^.]{0,30}(?:in|during|if|when)[^.]{0,15}pregnan/u',
    '/positive evidence of human fetal risk/u',
    '/embryo-?fetal toxicity/u',
    '/(?:is |are )?not (?:considered )?safe[^.]{0,30}pregnan/u',
    '/harm (?:to )?(?:an |the )?unborn/u',
    '/fetal toxicity/u',
    /* صياغات Lexicomp/UpToDate */
    '/avoid[^.]{0,30}(?:maternal |drug |medication )?use[^.]{0,60}(?:pregnan|gestation|trimester|conception)/u',
    '/(?:is |are |should be |must be )?not recommended[^.]{0,50}(?:in|during|for use in|throughout)[^.]{0,20}(?:pregnan|gestation|trimester)/u',
    '/(?:third|second|first) trimester[^.]{0,50}not recommended/u',
    '/pregnancy risk factor[^a-z0-9]{0,10}[dx]\b/u',
    '/discontinue[^.]{0,50}(?:before|during|in)[^.]{0,20}(?:pregnan|gestation|trimester|conception)/u',
];
/* عبارات تدل على عدم التعارض */
const RX_SAFE = [
    '/pregnancy (?:risk )?category[^a-z0-9]{0,6}(?:a|b[123]?)\b/u',
    '/(?:considered |generally )?safe (?:to use |to take |for use )?(?:in|during|for)[^.]{0,20}pregnan/u',
    '/(?:failed to demonstrate|have not shown|has not shown)[^.]{0,50}(?:risk|harm|fetal)/u',
    '/low risk (?:of|to)[^.]{0,25}(?:fetus|pregnan|baby)/u',
    '/unlikely to (?:harm|cause)[^.]{0,30}(?:baby|fetus|pregnan)/u',
    /* صياغات Lexicomp/UpToDate */
    '/(?:can|may|could) be (?:continued|used|administered|given)[^.]{0,50}(?:in|during)[^.]{0,20}(?:pregnan|gestation|trimester)/u',
    '/no (?:evidence|data|information)[^.]{0,50}(?:of |suggesting |indicating )?(?:risk|harm|adverse)[^.]{0,30}(?:pregnan|fetus|fetal|gestation)/u',
];
const RX_HARD = '/category[^a-z0-9]{0,6}x\b|contraindicated/u';

function out(array $d, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function fail(string $msg, int $code = 400): void { out(['ok' => false, 'error' => $msg], $code); }

/* ---------- قاعدة البيانات ---------- */
function db(array $c): PDO
{
    $pdo = new PDO(
        "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}",
        $c['user'], $c['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $pdo->exec("CREATE TABLE IF NOT EXISTS drug_checks (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      drug_key VARCHAR(190) NOT NULL, drug_name VARCHAR(190) NOT NULL,
      verdict ENUM('safe','unsafe','consult','unknown') NOT NULL,
      safe_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      unsafe_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      neutral_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
      sources_json MEDIUMTEXT NOT NULL,
      asked_count INT UNSIGNED NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_drug_key (drug_key), KEY idx_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return $pdo;
}

function shape(array $row, bool $cached): array
{
    $data = json_decode($row['sources_json'], true) ?: [];
    return [
        'ok' => true,
        'drug' => $row['drug_name'],
        'verdict' => $row['verdict'],
        'message' => MESSAGES[$row['verdict']] ?? '',
        'safe' => (int)$row['safe_count'],
        'unsafe' => (int)$row['unsafe_count'],
        'neutral' => (int)$row['neutral_count'],
        'strict_override' => (bool)($data['strict'] ?? false),
        'sources' => $data['sources'] ?? [],
        'cached' => $cached,
        'asked_count' => (int)($row['asked_count'] ?? 1),
        'checked_at' => date('c', strtotime($row['updated_at'])),
    ];
}

function cache_get(PDO $pdo, string $key, array $cfg): ?array
{
    $st = $pdo->prepare('SELECT * FROM drug_checks WHERE drug_key = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    if (!$row) return null;
    if ($cfg['cache_days'] > 0 && strtotime($row['updated_at']) < time() - $cfg['cache_days'] * 86400) return null;
    $pdo->prepare('UPDATE drug_checks SET asked_count = asked_count + 1, updated_at = updated_at WHERE id = ?')->execute([$row['id']]);
    $row['asked_count']++;
    return shape($row, true);
}

/* ---------- HTTP متوازي ---------- */
function http_multi(array $urls, array $cfg): array
{
    $mh = curl_multi_init();
    $hs = [];
    foreach ($urls as $k => $u) {
        $ch = curl_init($u);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $cfg['http_timeout'], CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => $cfg['user_agent'], CURLOPT_ENCODING => '',
            CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $hs[$k] = $ch;
    }
    do {
        $st = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $st === CURLM_OK);
    $res = [];
    foreach ($hs as $k => $ch) {
        $res[$k] = ['code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'body' => (string)curl_multi_getcontent($ch), 'err' => curl_error($ch)];
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);
    return $res;
}

/* طلب GET واحد بترويسات مخصصة (للمصادر اللي محتاجة Cookie/UA معين) */
function http_get(string $url, array $cfg, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4,
        CURLOPT_TIMEOUT => $cfg['http_timeout'], CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => $cfg['user_agent'], CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $r = [
        'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'body' => (string)$body,
        'err'  => curl_error($ch),
        'url'  => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL) ?: $url,
    ];
    return $r;
}



/* ---------- تحليل النصوص ---------- */
function negation(string $t, int $off): int
{   // 1 = نفي قوي (no evidence of…) | -1 = عدم يقين (not known whether…) | 0 = لا نفي
    $b = substr($t, max(0, $off - 60), min(60, $off));
    if (preg_match('/(not (?:known|established|clear)|unknown|uncertain|unclear|whether|\bif\b)[^.]{0,40}$/', $b)) return -1;
    if (preg_match('/\b(no|not|never|without|non|nor|failed|fail)\b[^.]{0,40}$/', $b)) return 1;
    return 0;
}

function snippet(string $t, int $off, int $len): string
{
    return '…' . trim(mb_strcut($t, max(0, $off - 90), $len + 180)) . '…';
}

function blank(string $rx, string $t): string
{
    return preg_replace_callback($rx, fn($m) => str_repeat(' ', strlen($m[0])), $t) ?? $t;
}

function classify(string $html): array
{
    $html = preg_replace('#<(script|style|nav|footer|header)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    $orig = mb_strtolower(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $orig = preg_replace('/\s+/u', ' ', $orig) ?? $orig;
    $t = $orig; $u = 0; $s = 0; $hard = false; $ev = [];

    foreach (RX_UNSAFE as $rx) {
        if (!preg_match_all($rx, $t, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as [$hit, $off]) {
            $neg = negation($t, $off);
            if ($neg === -1) continue;
            if ($neg === 1) { $s++; if (count($ev) < 3) $ev[] = snippet($orig, $off, strlen($hit)); continue; }
            $u++;
            if (preg_match(RX_HARD, $hit)) $hard = true;
            if (count($ev) < 3) $ev[] = snippet($orig, $off, strlen($hit));
        }
        $t = blank($rx, $t);
    }
    foreach (RX_SAFE as $rx) {
        if (!preg_match_all($rx, $t, $m, PREG_OFFSET_CAPTURE)) continue;
        foreach ($m[0] as [$hit, $off]) {
            $s++;
            if (count($ev) < 3) $ev[] = snippet($orig, $off, strlen($hit));
        }
        $t = blank($rx, $t);
    }
    return ['u' => $u, 's' => $s, 'hard' => $hard, 'ev' => $ev];
}

function source(string $name, string $url, array $c): array
{
    $r = $c['u'] > $c['s'] ? 'unsafe' : ($c['s'] > $c['u'] ? 'safe' : 'neutral');
    return ['name' => $name, 'url' => $url, 'result' => $r, 'hard' => $c['hard'], 'evidence' => $c['ev']];
}
function source_error(string $name, string $url, string $why): array
{
    return ['name' => $name, 'url' => $url, 'result' => 'error', 'hard' => false, 'evidence' => [$why]];
}

/* ---------- جمع المصادر ---------- */
function analyze(string $search, array $cfg): array
{
    $q = '"' . $search . '"';
    $urls = [
        'fda'   => 'https://api.fda.gov/drug/label.json?search=' . urlencode("(openfda.generic_name:$q OR openfda.brand_name:$q)") . '&limit=' . (int)$cfg['max_fda_labels'],
    ];
    $res = http_multi($urls, $cfg);
    $out = [];

    // 1) نشرات FDA (كل نشرة = صوت)
    $f = $res['fda'];
    if ($f['code'] === 200) {
        $j = json_decode($f['body'], true);
        $fields = ['pregnancy', 'teratogenic_effects', 'pregnancy_or_breast_feeding', 'use_in_specific_populations', 'contraindications', 'boxed_warning'];
        foreach (($j['results'] ?? []) as $lab) {
            $txt = '';
            foreach ($fields as $k) if (!empty($lab[$k])) $txt .= ' ' . implode(' ', (array)$lab[$k]);
            if (trim($txt) === '') continue;
            $brand = $lab['openfda']['brand_name'][0] ?? ($lab['openfda']['generic_name'][0] ?? $search);
            $mfr = $lab['openfda']['manufacturer_name'][0] ?? '';
            $url = isset($lab['set_id']) ? 'https://dailymed.nlm.nih.gov/dailymed/lookup.cfm?setid=' . $lab['set_id'] : 'https://open.fda.gov';
            $out[] = source('FDA — ' . $brand . ($mfr ? " ($mfr)" : ''), $url, classify($txt));
        }
    } elseif ($f['code'] !== 404) {
        $out[] = source_error('FDA', 'https://open.fda.gov', 'تعذر الوصول للمصدر');
    }

    // 2) Lexicomp Online — بحث ثم نشرة الدواء (خطوتان متسلسلتان)
    $lx = lexi_source($search, $cfg);
    if ($lx !== null) $out[] = $lx;

    // 3) RxList — نشرة دواء بصيغة FDA label
    $dx = drugs_source($search, $cfg);
    if ($dx !== null) $out[] = $dx;

    return $out;
}

/* RxList: https://www.rxlist.com/{drug}-drug.htm أو /search/rxl/{term} عند عدم وجود slug مباشر */
function drugs_source(string $search, array $cfg): ?array
{
    $enabled = $cfg['drugs']['enabled'] ?? true;
    if (!$enabled) return null;
    $base = 'https://www.rxlist.com';
    $hdr = [
        'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ];
    $slug = strtolower(trim((string)preg_replace('/[^a-zA-Z0-9]+/', '-', $search), '-'));
    if ($slug === '') return null;

    $html = null; $monourl = null;
    foreach (["$base/$slug-drug.htm", "$base/$slug/generic-drug.htm"] as $u) {
        $r = http_get($u, $cfg, $hdr);
        if ($r['code'] === 200 && strlen($r['body']) > 50000) { $html = $r['body']; $monourl = $r['url']; break; }
    }
    if ($html === null) {
        $r = http_get("$base/search/rxl/" . urlencode($search), $cfg, $hdr);
        if ($r['code'] !== 200) return source_error('RxList', $base, 'تعذر الوصول للمصدر (HTTP ' . $r['code'] . ')');
        preg_match_all('#https://www\.rxlist\.com/([a-z0-9-]+/generic-drug\.htm|[a-z0-9-]+-drug\.htm)#i', $r['body'], $mm);
        $urls = array_values(array_unique($mm[0] ?? []));
        if (!$urls) return source_error('RxList', $base . '/search/rxl/' . urlencode($search), 'لا توجد نشرة مطابقة');
        usort($urls, fn($a, $b) => (strpos($a, 'generic-drug') === false) <=> (strpos($b, 'generic-drug') === false));
        $r2 = http_get($urls[0], $cfg, $hdr);
        if ($r2['code'] !== 200) return source_error('RxList', $urls[0], 'تعذر جلب النشرة');
        $html = $r2['body']; $monourl = $r2['url'];
    }

    $title = '';
    if (preg_match('#<title>([^<]+)#i', $html, $tm)) {
        $title = trim(html_entity_decode($tm[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = trim((string)preg_replace('/\s*[|\-–:]\s*(?:RxList|Side Effects|Uses|Dosage).*$/i', '', $title));
    }
    return source('RxList' . ($title ? ' — ' . $title : ''), $monourl ?? $base, classify($html));
}

/* Lexicomp Online: /lco/action/search/v2/name ثم /lco/action/doc/v2/monograph/{db}/{id} */
function lexi_source(string $search, array $cfg): ?array
{
    $lc = $cfg['lexi'] ?? [];
    if (empty($lc['enabled'])) return null;
    $base = rtrim((string)($lc['base'] ?? 'https://online.lexi.com'), '/');
    $pageUrl = $base . '/lco/action/search?q=' . urlencode($search) . '&t=name&acs=false';
    $cookie = trim((string)($lc['cookie'] ?? ''));
    if ($cookie === '') return source_error('Lexicomp', $pageUrl, 'لم يتم ضبط Cookie في config.php');

    $hdr = [
        'Cookie: ' . $cookie,
        'Accept: application/json, text/plain, */*',
        'User-Agent: ' . ($lc['ua'] ?? $cfg['user_agent']),
        'Accept-Language: en-US,en;q=0.9',
        'Referer: ' . $base . '/lco/action/home',
    ];

    $r = http_get($base . '/lco/action/search/v2/name?q=' . urlencode($search) . '&acs=false&lang=en&sibn=false', $cfg, $hdr);
    if ($r['code'] !== 200) return source_error('Lexicomp', $pageUrl, 'تعذر الوصول للمصدر');
    $j = json_decode($r['body'], true);
    if (!is_array($j)) return source_error('Lexicomp', $pageUrl, 'انتهت جلسة Lexicomp — حدّث Cookie في config.php');

    // اختيار أفضل نشرة: docid صالح + ليست "route-specific"
    // الأولوية: dataset النشرات الكاملة multinat_f، ثم تفضيل (Systemic)، ثم أول نتيجة
    $hits = [];
    foreach (($j['searchHits']['hitSets'] ?? []) as $set)
        foreach (($set['hits'] ?? []) as $hit) $hits[] = $hit;
    $cands = [];
    foreach ($hits as $hit) {
        $u = (string)($hit['url'] ?? '');
        $title = trim(html_entity_decode(strip_tags((string)($hit['title'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!preg_match('#/docid/([a-z_0-9]+)/([0-9]+)#i', $u, $m)) continue;
        if (stripos($title, 'refer to') !== false) continue;
        $cands[] = ['db' => $m[1], 'id' => $m[2], 'title' => $title];
    }
    $pick = null;
    foreach ($cands as $c) {
        if ($c['db'] !== 'multinat_f') continue;
        if ($pick === null || stripos($c['title'], '(systemic)') !== false) $pick = $c;
        if (stripos($c['title'], '(systemic)') !== false) break;
    }
    $pick = $pick ?? ($cands[0] ?? null);
    if ($pick === null) return source_error('Lexicomp', $pageUrl, 'لا توجد نشرة دواء مطابقة');
    $db = $pick['db']; $docId = $pick['id']; $title = $pick['title'];
    $docUrl = $base . '/lco/action/doc/retrieve/docid/' . $db . '/' . $docId;

    $r2 = http_get($base . '/lco/action/doc/v2/monograph/' . $db . '/' . $docId, $cfg, $hdr);
    if ($r2['code'] !== 200) return source_error('Lexicomp — ' . $title, $docUrl, 'تعذر جلب النشرة');
    $j2 = json_decode($r2['body'], true);
    $fields = $j2['document']['fields'] ?? [];
    if (!is_array($fields)) return source_error('Lexicomp — ' . $title, $docUrl, 'انتهت جلسة Lexicomp — حدّث Cookie في config.php');

    // أقسام تتعلق بالحمل/موانع الاستخدام داخل النشرة
    $wanted = ['pri', 'coi', 'rep_considerations'];
    $txt = '';
    $walk = function (array $fs) use (&$walk, &$txt, $wanted): void {
        foreach ($fs as $f) {
            if (in_array($f['tag'] ?? '', $wanted, true) && !empty($f['content'])) $txt .= ' ' . $f['content'];
            foreach (['nestedFields', 'children'] as $k)
                if (!empty($f[$k]) && is_array($f[$k])) $walk($f[$k]);
        }
    };
    $walk($fields);
    if (trim(html_entity_decode(strip_tags($txt), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
        return source_error('Lexicomp — ' . $title, $docUrl, 'لا توجد معلومات حمل في النشرة');
    }
    return source('Lexicomp — ' . $title, $docUrl, classify($txt));
}

function decide(array $sources, bool $strict): array
{
    $s = $u = $n = 0; $hard = false;
    foreach ($sources as $x) {
        if ($x['result'] === 'safe') $s++;
        elseif ($x['result'] === 'unsafe') $u++;
        elseif ($x['result'] === 'neutral') $n++;
        if (!empty($x['hard']) && $x['result'] !== 'error') $hard = true;
    }
    $override = false;
    if ($s + $u === 0) $v = $n > 0 ? 'consult' : 'unknown';
    elseif ($s === $u) $v = 'consult';
    elseif ($s > $u) {
        $v = 'safe';
        if ($strict && $hard) { $v = 'consult'; $override = true; }
    } else $v = 'unsafe';
    return [$v, $s, $u, $n, $override];
}

/* ====================== الراوتر ====================== */
try {
    $pdo = db($cfg['db']);
} catch (Throwable $e) {
    fail('تعذر الاتصال بقاعدة البيانات. راجع إعدادات config.php', 500);
}

$action = $_GET['action'] ?? 'check';

if ($action === 'history') {
    $rows = $pdo->query('SELECT drug_name, verdict, asked_count, updated_at FROM drug_checks ORDER BY updated_at DESC LIMIT 40')->fetchAll();
    out(['ok' => true, 'items' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$body = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];

$raw = trim(preg_replace('/\s+/u', ' ', (string)($body['drug'] ?? '')));
if (mb_strlen($raw) < 2 || mb_strlen($raw) > 80 || !preg_match('/^[\p{L}\p{N}\s\-\.\+\/\(\)]+$/u', $raw)) {
    fail('اكتب اسم دواء صحيح (حرفان على الأقل، بدون رموز غريبة).', 422);
}
$refresh = !empty($body['refresh']);

$key = mb_strtolower($raw);
if (!$refresh && ($hit = cache_get($pdo, $key, $cfg))) out($hit);

$name = $raw;
$search = trim((string)preg_replace('/\s*\(.*?\)\s*/u', ' ', $name));
$sources = analyze($search, $cfg);

$okCount = count(array_filter($sources, fn($x) => $x['result'] !== 'error'));
if ($okCount === 0 && count($sources) > 0) fail('تعذر الوصول لمصادر البحث الآن. تأكد من اتصال الخادم بالإنترنت وحاول لاحقاً.', 502);

[$verdict, $s, $u, $n, $override] = decide($sources, (bool)$cfg['strict_mode']);
$display = mb_convert_case($name, MB_CASE_TITLE);

$row = [
    'drug_name' => $display, 'verdict' => $verdict, 'safe_count' => $s, 'unsafe_count' => $u, 'neutral_count' => $n,
    'sources_json' => json_encode(['sources' => $sources, 'strict' => $override], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
    'asked_count' => 1, 'updated_at' => date('Y-m-d H:i:s'),
];

// "unknown" لا يُحفظ حتى تتاح فرصة لنتيجة أفضل لاحقاً
if ($verdict !== 'unknown') {
    $pdo->prepare('INSERT INTO drug_checks (drug_key, drug_name, verdict, safe_count, unsafe_count, neutral_count, sources_json)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE drug_name = VALUES(drug_name), verdict = VALUES(verdict), safe_count = VALUES(safe_count),
          unsafe_count = VALUES(unsafe_count), neutral_count = VALUES(neutral_count), sources_json = VALUES(sources_json),
          asked_count = asked_count + 1, updated_at = NOW()')
        ->execute([$key, $display, $verdict, $s, $u, $n, $row['sources_json']]);
}
out(shape($row, false));
