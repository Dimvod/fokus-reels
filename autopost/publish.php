<?php
/**
 * Автопубликация Reels в Instagram (@fotosalon_fokus) по расписанию.
 *
 * Запускается из CLI планировщиком заданий Руцентра:
 *     php /home/h105661262/link.fokusnet.ru/autopost/publish.php
 * Проверка без публикации:
 *     php publish.php --dry
 *
 * Что делает за один запуск (не больше одного ролика):
 *   1. берёт из queue.json первый ролик со статусом pending, у которого наступило время publish_at;
 *   2. отдаёт Instagram ссылку на видео (video_base_url/<файл>), ждёт обработки и публикует Reels;
 *   3. отмечает результат в queue.json и (по желанию) пишет в группу MAX;
 *   4. раз в ~30 дней продлевает токен Instagram.
 * Токен и настройки лежат в config.php (рядом, вне папки сайта) и в чат/код не попадают.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

date_default_timezone_set('Asia/Tomsk');
define('AP_DIR', __DIR__);
define('AP_DATA', AP_DIR . '/data');
$DRY = in_array('--dry', $argv, true);

function ap_log($msg)
{
    if (!is_dir(AP_DATA)) {
        @mkdir(AP_DATA, 0750, true);
    }
    $f = AP_DATA . '/log.txt';
    if (is_file($f) && filesize($f) > 200000) {
        @rename($f, $f . '.old');
    }
    @file_put_contents($f, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    echo $msg . "\n";
}

function ap_http($method, $url, $headers = array(), $body = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => $headers,
    ));
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $json = is_string($res) ? json_decode($res, true) : null;
    return array($code, $json, (string) $res, $err);
}

function ap_cfg()
{
    $file = AP_DIR . '/config.php';
    $c = is_file($file) ? (array) require $file : array();
    foreach (array('ig_token' => 'IG_TOKEN', 'ig_user_id' => 'IG_USER_ID', 'ig_api' => 'IG_API', 'video_base_url' => 'VIDEO_BASE_URL') as $k => $env) {
        $v = getenv($env);
        if ($v !== false && $v !== '') {
            $c[$k] = $v;
        }
    }
    return array_merge(array(
        'ig_token' => '',
        'ig_user_id' => 'me',
        'ig_api' => 'https://graph.instagram.com/v21.0',
        'video_base_url' => 'https://link.fokusnet.ru/reels',
        'notify_max' => false,
        'max_late_days' => 7,
        // true только для 60-дневных токенов Instagram (graph.instagram.com); для токенов без срока — false
        'token_auto_refresh' => false,
    ), $c);
}

function ap_json_read($file, $default)
{
    if (!is_file($file)) {
        return $default;
    }
    $d = json_decode((string) file_get_contents($file), true);
    return is_array($d) ? $d : $default;
}

function ap_json_write($file, $data)
{
    $tmp = $file . '.tmp';
    file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    rename($tmp, $file);
}

function ap_notify_max($text)
{
    // Те же настройки бота и группы, что у обработчика формы Панорамы.
    $p = dirname(dirname(AP_DIR)) . '/panorama.fokusnet.ru/max-config.php';
    if (!is_file($p)) {
        return;
    }
    require_once $p;
    if (!defined('MAX_BOT_TOKEN') || !defined('MAX_CHAT_ID')) {
        return;
    }
    $base = defined('MAX_API_BASE') ? rtrim(MAX_API_BASE, '/') : 'https://platform-api2.max.ru';
    ap_http(
        'POST',
        $base . '/messages?chat_id=' . rawurlencode((string) MAX_CHAT_ID),
        array('Authorization: ' . MAX_BOT_TOKEN, 'Content-Type: application/json'),
        json_encode(array('text' => $text), JSON_UNESCAPED_UNICODE)
    );
}

// ---------- защита от параллельных запусков ----------
if (!is_dir(AP_DATA)) {
    @mkdir(AP_DATA, 0750, true);
}
$lock = fopen(AP_DATA . '/lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    ap_log('другой запуск ещё работает, выходим');
    exit(0);
}

if (!function_exists('curl_init')) {
    ap_log('ОШИБКА: на хостинге нет расширения curl');
    exit(1);
}

$cfg = ap_cfg();
$tokenFile = AP_DATA . '/token.json';
$tok = ap_json_read($tokenFile, array());
$token = isset($tok['token']) && $tok['token'] !== '' ? $tok['token'] : $cfg['ig_token'];
if ($token === '') {
    ap_log('ОШИБКА: токен Instagram не задан (config.php → ig_token)');
    exit(1);
}
if (!isset($tok['token'])) {
    $tok = array('token' => $token, 'refreshed_at' => time());
    ap_json_write($tokenFile, $tok);
}

// ---------- продление токена раз в ~30 дней ----------
if (!$DRY && !empty($cfg['token_auto_refresh']) && time() - (int) $tok['refreshed_at'] > 30 * 86400) {
    list($code, $j) = ap_http('GET', 'https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token&access_token=' . rawurlencode($token));
    if ($code === 200 && is_array($j) && !empty($j['access_token'])) {
        $token = $j['access_token'];
        $tok = array('token' => $token, 'refreshed_at' => time());
        ap_json_write($tokenFile, $tok);
        ap_log('токен продлён');
    } else {
        ap_log('не удалось продлить токен, http=' . $code);
        ap_notify_max('⚠️ Автопубликация: не удалось продлить токен Instagram. Нужно выпустить новый токен.');
    }
}

// ---------- проверка токена и ссылки на видео (php publish.php --check) ----------
if (in_array('--check', $argv, true)) {
    list($c1, $me, $raw1) = ap_http('GET', $cfg['ig_api'] . '/' . rawurlencode($cfg['ig_user_id']) . '?fields=username', array('Authorization: Bearer ' . $token));
    $safe = mb_substr(preg_replace('/"access_token"\\s*:\\s*"[^"]+"/', '"access_token":"***"', (string) $raw1), 0, 300, 'UTF-8');
    ap_log('[check] токен: http=' . $c1 . (isset($me['username']) ? ', аккаунт @' . $me['username'] : ', ответ: ' . $safe));
    $q0 = ap_json_read(AP_DIR . '/queue.json', array());
    if ($q0) {
        list($c2) = ap_http('HEAD', rtrim($cfg['video_base_url'], '/') . '/' . rawurlencode($q0[0]['file']));
        ap_log('[check] первый ролик в очереди: ' . $q0[0]['file'] . ', видео доступно: http=' . $c2);
    }
    exit(0);
}
// ---------- очередь ----------
$queueFile = AP_DIR . '/queue.json';
$queue = ap_json_read($queueFile, array());
if (!$queue) {
    ap_log('очередь пуста или не найдена: ' . $queueFile);
    exit(0);
}

$now = time();
$pick = -1;
foreach ($queue as $i => $q) {
    $st = isset($q['status']) ? $q['status'] : 'pending';
    if ($st !== 'pending' && $st !== 'processing') {
        continue;
    }
    $at = strtotime($q['publish_at']);
    if ($at === false || $at > $now) {
        continue;
    }
    if ($now - $at > $cfg['max_late_days'] * 86400) {
        $queue[$i]['status'] = 'skipped';
        $queue[$i]['error'] = 'просрочено больше чем на ' . $cfg['max_late_days'] . ' дн.';
        ap_log('пропущен (просрочен): ' . $q['file']);
        continue;
    }
    $pick = $i;
    break;
}
ap_json_write($queueFile, $queue);

if ($pick < 0) {
    ap_log('нет роликов к публикации');
    exit(0);
}

$item = $queue[$pick];
$title = isset($item['title']) ? $item['title'] : $item['file'];
$videoUrl = rtrim($cfg['video_base_url'], '/') . '/' . rawurlencode($item['file']);
ap_log('к публикации: #' . $item['id'] . ' ' . $item['file'] . ' (' . $item['publish_at'] . ')');

if ($DRY) {
    ap_log('[dry] видео: ' . $videoUrl . ', подпись: ' . mb_substr($item['caption'], 0, 60, 'UTF-8') . '…');
    list($code) = ap_http('HEAD', $videoUrl);
    ap_log('[dry] видео доступно по ссылке: http=' . $code);
    list($code2, $me) = ap_http('GET', $cfg['ig_api'] . '/' . rawurlencode($cfg['ig_user_id']) . '?fields=username', array('Authorization: Bearer ' . $token));
    ap_log('[dry] токен: http=' . $code2 . (isset($me['username']) ? ', аккаунт @' . $me['username'] : ''));
    exit(0);
}

$igId = $cfg['ig_user_id'];
$auth = array('Authorization: Bearer ' . $token);

// 1. создаём контейнер (или продолжаем прерванный)
if (!empty($item['creation_id'])) {
    $creation = $item['creation_id'];
} else {
    list($code, $j, $raw, $err) = ap_http(
        'POST',
        $cfg['ig_api'] . '/' . rawurlencode($igId) . '/media',
        $auth,
        http_build_query(array(
            'media_type' => 'REELS',
            'video_url' => $videoUrl,
            'caption' => $item['caption'],
            'share_to_feed' => 'true',
        ))
    );
    if ($code !== 200 || !is_array($j) || empty($j['id'])) {
        $queue[$pick]['attempts'] = (isset($item['attempts']) ? $item['attempts'] : 0) + 1;
        $queue[$pick]['error'] = 'create: http=' . $code . ' ' . mb_substr($raw, 0, 300, 'UTF-8');
        if ($queue[$pick]['attempts'] >= 3) {
            $queue[$pick]['status'] = 'error';
            ap_notify_max('❌ Автопубликация: не удалось создать Reels «' . $title . '» (3 попытки). ' . $queue[$pick]['error']);
        }
        ap_json_write($queueFile, $queue);
        ap_log('ОШИБКА создания: ' . $queue[$pick]['error']);
        exit(1);
    }
    $creation = $j['id'];
    $queue[$pick]['creation_id'] = $creation;
    $queue[$pick]['status'] = 'processing';
    ap_json_write($queueFile, $queue);
    ap_log('контейнер создан: ' . $creation);
}

// 2. ждём, пока Instagram обработает видео (до ~5 минут за запуск)
$ready = false;
for ($n = 0; $n < 30; $n++) {
    list($code, $j) = ap_http('GET', $cfg['ig_api'] . '/' . rawurlencode($creation) . '?fields=status_code,status', $auth);
    $status = is_array($j) && isset($j['status_code']) ? $j['status_code'] : '';
    if ($status === 'FINISHED') {
        $ready = true;
        break;
    }
    if ($status === 'ERROR' || $status === 'EXPIRED') {
        $queue[$pick]['status'] = 'error';
        $queue[$pick]['error'] = 'Instagram: ' . $status . ' ' . (isset($j['status']) ? $j['status'] : '');
        unset($queue[$pick]['creation_id']);
        ap_json_write($queueFile, $queue);
        ap_log('ОШИБКА обработки видео: ' . $queue[$pick]['error']);
        ap_notify_max('❌ Автопубликация: Instagram не принял Reels «' . $title . '». ' . $queue[$pick]['error']);
        exit(1);
    }
    sleep(10);
}
if (!$ready) {
    ap_log('видео ещё обрабатывается, продолжим при следующем запуске');
    exit(0);
}

// 3. публикуем
list($code, $j, $raw) = ap_http(
    'POST',
    $cfg['ig_api'] . '/' . rawurlencode($igId) . '/media_publish',
    $auth,
    http_build_query(array('creation_id' => $creation))
);
if ($code !== 200 || !is_array($j) || empty($j['id'])) {
    $queue[$pick]['error'] = 'publish: http=' . $code . ' ' . mb_substr($raw, 0, 300, 'UTF-8');
    $queue[$pick]['attempts'] = (isset($item['attempts']) ? $item['attempts'] : 0) + 1;
    if ($queue[$pick]['attempts'] >= 3) {
        $queue[$pick]['status'] = 'error';
        ap_notify_max('❌ Автопубликация: не удалось опубликовать Reels «' . $title . '». ' . $queue[$pick]['error']);
    }
    ap_json_write($queueFile, $queue);
    ap_log('ОШИБКА публикации: ' . $queue[$pick]['error']);
    exit(1);
}

$queue[$pick]['status'] = 'published';
$queue[$pick]['media_id'] = $j['id'];
$queue[$pick]['published_at'] = date('c');
unset($queue[$pick]['error'], $queue[$pick]['creation_id']);
ap_json_write($queueFile, $queue);
ap_log('ОПУБЛИКОВАНО: ' . $item['file'] . ' media_id=' . $j['id']);

// 4. дублируем тот же ролик в сторис (не критично: ошибка сторис не влияет на Reels)
$storyNote = '';
if (!isset($cfg['duplicate_to_story']) || $cfg['duplicate_to_story']) {
    $sid = '';
    list($sc, $sj, $sraw) = ap_http('POST', $cfg['ig_api'] . '/' . rawurlencode($igId) . '/media', $auth,
        http_build_query(array('media_type' => 'STORIES', 'video_url' => $videoUrl)));
    if ($sc === 200 && is_array($sj) && !empty($sj['id'])) {
        $sCreation = $sj['id'];
        $sReady = false;
        for ($n = 0; $n < 18; $n++) {
            list($c3, $j3) = ap_http('GET', $cfg['ig_api'] . '/' . rawurlencode($sCreation) . '?fields=status_code,status', $auth);
            $st3 = is_array($j3) && isset($j3['status_code']) ? $j3['status_code'] : '';
            if ($st3 === 'FINISHED') { $sReady = true; break; }
            if ($st3 === 'ERROR' || $st3 === 'EXPIRED') { break; }
            sleep(10);
        }
        if ($sReady) {
            list($c4, $j4, $raw4) = ap_http('POST', $cfg['ig_api'] . '/' . rawurlencode($igId) . '/media_publish', $auth,
                http_build_query(array('creation_id' => $sCreation)));
            if ($c4 === 200 && is_array($j4) && !empty($j4['id'])) {
                $sid = $j4['id'];
            } else {
                $queue[$pick]['story_error'] = 'publish: http=' . $c4 . ' ' . mb_substr($raw4, 0, 200, 'UTF-8');
            }
        } else {
            $queue[$pick]['story_error'] = 'сторис не обработана Instagram: ' . (isset($j3['status']) ? $j3['status'] : 'таймаут');
        }
    } else {
        $queue[$pick]['story_error'] = 'create: http=' . $sc . ' ' . mb_substr($sraw, 0, 200, 'UTF-8');
    }
    if ($sid !== '') {
        $queue[$pick]['story_id'] = $sid;
        unset($queue[$pick]['story_error']);
        $storyNote = ' + сторис';
        ap_log('СТОРИС опубликована: story_id=' . $sid);
    } else {
        $storyNote = ' (сторис не вышла: ' . $queue[$pick]['story_error'] . ')';
        ap_log('сторис: ' . $queue[$pick]['story_error']);
    }
    ap_json_write($queueFile, $queue);
}
if (!empty($cfg['notify_max'])) {
    ap_notify_max('✅ Instagram: опубликован Reels «' . $title . '»' . $storyNote . ' (' . date('d.m.Y H:i') . ' Томск)');
}
exit(0);
