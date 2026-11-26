<?php
/**
 * Atoll Rising server directory (docs/multiplayer.md, step 5). Hands out server names under the game domain,
 * keeps their DNS records pointing at the server's own internet address, sets the TXT records Let's Encrypt
 * asks for (DNS-01), and lists running servers for the game's Multiplayer screen.
 *
 *   GET  ?a=list                        public list (CORS *)
 *   POST ?a=register   {want, official_key?, port, title}       → {id, name, fqdn, secret}
 *   POST ?a=heartbeat  {id, secret, port, title, players, games, version} → {ok, name, fqdn, ip, reachable}
 *   POST ?a=acme       {id, secret, op: set|clear, value?}       → {ok}
 *   POST ?a=block      {admin_key, name, blocked}                → {ok}
 *   POST ?a=setup      {admin_key}                               → {ok}   (creates the tables)
 *
 * The Hostinger API token lives only in config.php on the web host. A name always points at the address the
 * request came from, so nobody can point a name at someone else's machine.
 */
declare(strict_types=1);

const VERSION = 1;
const ONLINE_SECS = 180;              // listed while a heartbeat came within this time
const FORGET_SECS = 30 * 86400;       // names unused this long are freed
const RESERVED = ['www', 'api', 'mail', 'smtp', 'imap', 'pop', 'ftp', 'admin', 'administrator', 'root', 'play', 'game', 'games',
  'official', 'atoll', 'atollrising', 'support', 'help', 'status', 'login', 'account', 'download', 'downloads', 'server', 'servers',
  'directory', 'cdn', 'static', 'assets', 'dev', 'test', 'staff', 'moderator', 'mod', 'owner', 'news', 'shop', 'store', 'billing'];
const BAD = ['fuck', 'shit', 'cunt', 'nazi', 'hitler', 'nigg', 'fag', 'rape', 'porn', 'sex', 'dick', 'cock', 'pussy', 'whore', 'slut',
  'bitch', 'kkk', 'jihad', 'fisse', 'pik', 'kusse', 'luder', 'neger', 'bøsse', 'scam', 'phish', 'hack', 'cheat', 'free-'];
const THEME = ['reef', 'lagoon', 'tide', 'shoal', 'coral', 'surf', 'cove', 'atoll', 'wave', 'isle', 'current', 'kelp', 'drift', 'harbor'];

// the settings with the keys: above public_html, else in public_html/private (closed by its .htaccess)
$cfgFile = getenv('ATOLL_DIR_CONFIG') ?: (is_file(dirname(__DIR__, 2) . '/atoll-directory-config.php') ? dirname(__DIR__, 2) . '/atoll-directory-config.php' : dirname(__DIR__) . '/private/atoll-directory-config.php');
$cfg = require $cfgFile;
header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out(int $code, array $body): never { http_response_code($code); echo json_encode($body); exit; }
function db(): PDO {
  global $cfg; static $pdo = null;
  if (!$pdo) {
    $pdo = new PDO($cfg['db_dsn'], $cfg['db_user'], $cfg['db_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if (str_starts_with($cfg['db_dsn'], 'sqlite:')) { // local tests only (scripts/directory-check.mjs)
      $pdo->exec('CREATE TABLE IF NOT EXISTS servers (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT UNIQUE NOT NULL, secret_hash TEXT NOT NULL, ip TEXT NOT NULL, port INT NOT NULL DEFAULT 25564, title TEXT NOT NULL DEFAULT \'\', official INT NOT NULL DEFAULT 0, players INT NOT NULL DEFAULT 0, games INT NOT NULL DEFAULT 0, version TEXT NOT NULL DEFAULT \'\', reachable INT NOT NULL DEFAULT 0, checked_at INT NOT NULL DEFAULT 0, last_seen INT NOT NULL, created INT NOT NULL, blocked INT NOT NULL DEFAULT 0)');
      $pdo->exec('CREATE TABLE IF NOT EXISTS hits (k TEXT NOT NULL, t INT NOT NULL)');
    } elseif (!$pdo->query("SHOW TABLES LIKE 'servers'")->fetchColumn()) { // first use: create the tables
      foreach (explode(';', (string)file_get_contents(__DIR__ . '/schema.sql')) as $sql) { $sql = trim(preg_replace('/^--.*$/m', '', $sql) ?? ''); if ($sql !== '') $pdo->exec($sql); }
    }
  }
  return $pdo;
}
function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? ''; }
/** at most $n calls per $secs for this key */
function limit(string $key, int $n, int $secs): void {
  $now = time();
  db()->prepare('DELETE FROM hits WHERE t < ?')->execute([$now - 86400]);
  $q = db()->prepare('SELECT COUNT(*) FROM hits WHERE k = ? AND t > ?'); $q->execute([$key, $now - $secs]);
  if ((int)$q->fetchColumn() >= $n) out(429, ['error' => 'Too many requests, try again later']);
  db()->prepare('INSERT INTO hits (k, t) VALUES (?, ?)')->execute([$key, $now]);
}
function body(): array {
  $raw = file_get_contents('php://input', false, null, 0, 4096);
  $b = json_decode($raw ?: '', true);
  if (!is_array($b)) out(400, ['error' => 'Bad request']);
  return $b;
}
function title(mixed $s): string {
  $s = is_string($s) ? $s : '';
  $s = preg_replace('/[^\p{L}\p{N} \'\-_.!?&()]/u', '', $s) ?? '';
  $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
  foreach (BAD as $w) if (stripos($s, $w) !== false) return 'Atoll Rising server';
  return mb_substr($s, 0, 40) ?: 'Atoll Rising server';
}
function nameOk(string $n): ?string {
  if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{1,18})[a-z0-9]$/', $n)) return 'Use 3–20 letters, digits or hyphens (not at the start or end)';
  if (str_contains($n, '--')) return 'No double hyphens';
  if (in_array($n, RESERVED, true) || preg_match('/^official/', $n)) return 'That name is reserved';
  foreach (BAD as $w) if (str_contains($n, $w)) return 'Please choose another name';
  return null;
}
/** Hostinger DNS API: set (replace) or delete one record set of the game domain. */
function dns(string $method, array $payload): void {
  global $cfg;
  if ($cfg['hostinger_token'] === 'test') { file_put_contents(sys_get_temp_dir() . '/atoll-dns.log', "$method " . json_encode($payload) . "\n", FILE_APPEND); return; }
  $ch = curl_init('https://developers.hostinger.com/api/dns/v1/zones/' . rawurlencode($cfg['domain']));
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $cfg['hostinger_token'], 'Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload)]);
  $res = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code < 200 || $code >= 300) { error_log("atoll dns $method $code $res"); out(502, ['error' => 'DNS update failed, try again later']); }
}
function setRecord(string $name, string $type, string $content, int $ttl = 300): void {
  dns('PUT', ['overwrite' => true, 'zone' => [['name' => $name, 'type' => $type, 'ttl' => $ttl, 'records' => [['content' => $content]]]]]);
}
function delRecord(string $name, string $type): void { dns('DELETE', ['filters' => [['name' => $name, 'type' => $type]]]); }
function addrType(string $ip): string { return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'AAAA' : 'A'; }
function auth(array $b): array {
  $q = db()->prepare('SELECT * FROM servers WHERE id = ?'); $q->execute([(int)($b['id'] ?? 0)]);
  $s = $q->fetch(PDO::FETCH_ASSOC);
  if (!$s || !is_string($b['secret'] ?? null) || !hash_equals($s['secret_hash'], hash('sha256', $b['secret']))) out(403, ['error' => 'Unknown server']);
  if ($s['blocked']) out(403, ['error' => 'This server has been blocked from the list']);
  return $s;
}
function reachable(string $ip, int $port): int {
  $f = @fsockopen(str_contains($ip, ':') ? "[$ip]" : $ip, $port, $errno, $err, 3);
  if (!$f) return 0; fclose($f); return 1;
}
function port(mixed $p): int { $p = (int)$p; return ($p >= 1 && $p <= 65535) ? $p : 25564; }

$a = $_GET['a'] ?? '';
$now = time();
try {
  if ($a === 'list') {
    header('Access-Control-Allow-Origin: *');
    // free names nobody has used for a month (and their DNS records)
    $old = db()->prepare('SELECT name, ip FROM servers WHERE last_seen < ? LIMIT 5'); $old->execute([$now - FORGET_SECS]);
    foreach ($old->fetchAll(PDO::FETCH_ASSOC) as $o) { delRecord($o['name'], addrType($o['ip'])); db()->prepare('DELETE FROM servers WHERE name = ?')->execute([$o['name']]); }
    $q = db()->prepare('SELECT name, port, title, official, players, games, version, reachable FROM servers WHERE last_seen > ? AND blocked = 0 ORDER BY official DESC, players DESC, name');
    $q->execute([$now - ONLINE_SECS]);
    $list = array_map(fn($s) => ['name' => $s['name'], 'host' => $s['name'] . '.' . $cfg['domain'], 'port' => (int)$s['port'], 'title' => $s['title'],
      'official' => (bool)$s['official'], 'players' => (int)$s['players'], 'games' => (int)$s['games'], 'version' => $s['version'], 'reachable' => (bool)$s['reachable']],
      $q->fetchAll(PDO::FETCH_ASSOC));
    out(200, ['v' => VERSION, 'servers' => $list]);
  }
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'POST only']);
  $b = body();

  if ($a === 'register') {
    limit('regtry|' . ip(), 20, 86400);
    $want = strtolower(trim((string)($b['want'] ?? '')));
    $official = is_string($b['official_key'] ?? null) && $cfg['official_key'] !== '' && hash_equals($cfg['official_key'], $b['official_key']);
    if ($official) {
      if (!preg_match('/^official(0[1-9]|[1-9][0-9])$/', $want)) out(400, ['error' => 'Official servers are named official01 to official99']);
    } elseif ($want !== '') {
      if ($e = nameOk($want)) out(400, ['error' => $e]);
    } else {
      $want = '';
      for ($i = 0; $i < 20 && $want === ''; $i++) {
        $try = THEME[random_int(0, count(THEME) - 1)] . '-' . random_int(100, 9999);
        $q = db()->prepare('SELECT 1 FROM servers WHERE name = ?'); $q->execute([$try]);
        if (!$q->fetchColumn()) $want = $try;
      }
    }
    $q = db()->prepare('SELECT 1 FROM servers WHERE name = ?'); $q->execute([$want]);
    if ($q->fetchColumn()) out(409, ['error' => 'That name is taken']);
    limit('reg|' . ip(), 3, 86400); // new names per address and day
    $secret = bin2hex(random_bytes(32));
    $port = port($b['port'] ?? 25564);
    setRecord($want, addrType(ip()), ip());
    db()->prepare('INSERT INTO servers (name, secret_hash, ip, port, title, official, last_seen, created, reachable, checked_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$want, hash('sha256', $secret), ip(), $port, title($b['title'] ?? ''), $official ? 1 : 0, $now, $now, reachable(ip(), $port), $now]);
    out(200, ['id' => (int)db()->lastInsertId(), 'name' => $want, 'fqdn' => $want . '.' . $cfg['domain'], 'secret' => $secret]);
  }

  if ($a === 'heartbeat') {
    limit('hb|' . ip(), 30, 600);
    $s = auth($b);
    $port = port($b['port'] ?? $s['port']);
    if ($s['ip'] !== ip()) { // the host's address changed: move the name with it
      if (addrType($s['ip']) !== addrType(ip())) delRecord($s['name'], addrType($s['ip']));
      setRecord($s['name'], addrType(ip()), ip());
    }
    $check = $now - (int)$s['checked_at'] > 600 || $s['ip'] !== ip() || $port !== (int)$s['port'] || !empty($b['check']);
    $reach = $check ? reachable(ip(), $port) : (int)$s['reachable'];
    db()->prepare('UPDATE servers SET ip=?, port=?, title=?, players=?, games=?, version=?, last_seen=?, reachable=?, checked_at=? WHERE id=?')
      ->execute([ip(), $port, title($b['title'] ?? $s['title']), max(0, min(99, (int)($b['players'] ?? 0))), max(0, min(99, (int)($b['games'] ?? 0))),
        substr(preg_replace('/[^0-9a-z.\-]/i', '', (string)($b['version'] ?? '')), 0, 20), $now, $reach, $check ? $now : (int)$s['checked_at'], $s['id']]);
    out(200, ['ok' => true, 'name' => $s['name'], 'fqdn' => $s['name'] . '.' . $cfg['domain'], 'ip' => ip(), 'reachable' => (bool)$reach]);
  }

  if ($a === 'acme') {
    limit('acme|' . ip(), 20, 3600);
    $s = auth($b);
    $rec = '_acme-challenge.' . $s['name'];
    if (($b['op'] ?? '') === 'set') {
      $v = (string)($b['value'] ?? '');
      if (!preg_match('/^[A-Za-z0-9_-]{20,100}$/', $v)) out(400, ['error' => 'Bad value']);
      setRecord($rec, 'TXT', $v, 60);
    } else delRecord($rec, 'TXT');
    out(200, ['ok' => true]);
  }

  if ($a === 'setup') { // create the tables once (admin key)
    if (!is_string($b['admin_key'] ?? null) || $cfg['admin_key'] === '' || !hash_equals($cfg['admin_key'], $b['admin_key'])) out(403, ['error' => 'No']);
    foreach (array_filter(array_map('trim', explode(';', (string)file_get_contents(__DIR__ . '/schema.sql')))) as $sql) { $sql = trim(preg_replace('/^--.*$/m', '', $sql) ?? ''); if ($sql !== '') db()->exec($sql); }
    out(200, ['ok' => true]);
  }

  if ($a === 'block') {
    limit('admin|' . ip(), 10, 3600);
    if (!is_string($b['admin_key'] ?? null) || $cfg['admin_key'] === '' || !hash_equals($cfg['admin_key'], $b['admin_key'])) out(403, ['error' => 'No']);
    db()->prepare('UPDATE servers SET blocked = ? WHERE name = ?')->execute([empty($b['blocked']) ? 0 : 1, (string)($b['name'] ?? '')]);
    out(200, ['ok' => true]);
  }
  out(404, ['error' => 'Unknown action']);
} catch (Throwable $e) {
  error_log('atoll directory: ' . $e->getMessage());
  out(500, ['error' => 'Server error']);
}
