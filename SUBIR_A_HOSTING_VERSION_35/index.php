<?php

/*
 * Endpoints PWA dinámicos para alojamientos que interceptan archivos estáticos.
 * Se sirven desde index.php para evitar que una protección del hosting convierta
 * manifest.json o sw.js en una página HTML de verificación.
 */
if (isset($_GET['radar_manifest'])) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Content-Type-Options: nosniff');
    $manifest = [
        'id' => './',
        'name' => 'El Radar - Transporte',
        'short_name' => 'El Radar',
        'description' => 'Aplicación de transporte, solicitudes de viaje y notificaciones.',
        'start_url' => './',
        'scope' => './',
        'display' => 'standalone',
        'display_override' => ['standalone', 'minimal-ui'],
        'orientation' => 'any',
        'background_color' => '#f4f6fb',
        'theme_color' => '#172554',
        'icons' => [
            ['src' => './index.php?radar_icon=192', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => './index.php?radar_icon=512', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable']
        ]
    ];
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['radar_icon'])) {
    $size = (string)$_GET['radar_icon'];
    $iconFile = __DIR__ . ($size === '192' ? '/icon-192.png' : ($size === '512' ? '/icon-512.png' : '/missing-icon.png'));
    if (!is_file($iconFile)) { http_response_code(404); exit; }
    header('Content-Type: image/png');
    header('Cache-Control: public, max-age=86400');
    header('X-Content-Type-Options: nosniff');
    readfile($iconFile);
    exit;
}
if (isset($_GET['radar_sw'])) {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Service-Worker-Allowed: /');
    header('X-Content-Type-Options: nosniff');
    echo <<<'RADAR_SW'
/* El Radar - Service Worker dinámico para InfinityFree */
const CACHE_NAME = 'el-radar-shell-v5';
self.addEventListener('install', event => { self.skipWaiting(); });
self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keys = await caches.keys();
    await Promise.all(keys.filter(key => key.startsWith('el-radar-') && key !== CACHE_NAME).map(key => caches.delete(key)));
    await self.clients.claim();
  })());
});
// Guarda en el dispositivo la última página completa que se haya cargado con éxito.
// Así la interfaz puede abrirse sin Internet; las operaciones del servidor necesitarán conexión.
self.addEventListener('fetch', event => {
  const request = event.request;
  if (request.method !== 'GET' || request.mode !== 'navigate') return;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin) return;
  if (url.searchParams.has('action') || url.searchParams.has('radar_sw') || url.searchParams.has('radar_manifest') || url.searchParams.has('radar_icon')) return;
  event.respondWith((async () => {
    const cache = await caches.open(CACHE_NAME);
    try {
      const response = await fetch(request);
      const type = response.headers.get('content-type') || '';
      if (response.ok && type.includes('text/html')) await cache.put('./', response.clone());
      return response;
    } catch (error) {
      const cached = await cache.match('./') || await cache.match(request);
      if (cached) return cached;
      return new Response(`<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>El Radar sin conexión</title><body style="font-family:Arial,sans-serif;background:#f4f6fb;color:#172554;display:grid;place-items:center;min-height:90vh;text-align:center;padding:24px"><main><h1>El Radar</h1><h2>Primera carga sin conexión</h2><p>Conéctate a Internet una vez para guardar la aplicación en este dispositivo. Después podrás abrir la interfaz sin conexión.</p></main>
<script id="radar-no-copy-script">
(function(){
  'use strict';
  const stop = function(e){ e.preventDefault(); e.stopPropagation(); return false; };
  document.addEventListener('copy', stop, true);
  document.addEventListener('cut', stop, true);
  document.addEventListener('contextmenu', stop, true);
  document.addEventListener('dragstart', stop, true);
  document.addEventListener('selectstart', function(e){
    if (!e.target || !e.target.closest || !e.target.closest('input, textarea, [contenteditable="true"]')) stop(e);
  }, true);
  document.addEventListener('keydown', function(e){
    const key=(e.key||'').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && ['c','x','a','insert'].includes(key)) stop(e);
    if (e.shiftKey && (key==='insert' || key==='f10')) stop(e);
  }, true);
})();
</script>
</body></html>`, {headers:{'Content-Type':'text/html; charset=utf-8'}});
    }
  })());
});
self.addEventListener('push', event => {
  let data = {};
  try { data = event.data ? event.data.json() : {}; }
  catch (_) { data = {body: event.data ? event.data.text() : ''}; }
  const title = data.title || 'El Radar';
  const refType = data.reference_type || '';
  const refId = Number(data.reference_id || 0);
  const actions = [];
  let actionUrl = './';
  if (refType === 'solicitud_viaje' && refId > 0 && title === 'Nueva solicitud de viaje') {
    actions.push({action:'respond', title:'Responder'});
    actions.push({action:'open', title:'Ver solicitud'});
    actionUrl = './?radar_action=respond_trip&solicitud_id=' + refId + '&notification_id=' + Number(data.notification_id || 0);
  } else if (refType === 'solicitud_viaje' && refId > 0 && title === 'Precio del viaje recibido') {
    actions.push({action:'accept', title:'Aceptar precio'});
    actions.push({action:'reject', title:'Rechazar'});
    actionUrl = './?radar_action=review_price&solicitud_id=' + refId + '&notification_id=' + Number(data.notification_id || 0);
  } else {
    actions.push({action:'open', title:'Abrir El Radar'});
  }
  const options = {
    body: data.body || 'Tienes una nueva notificación.',
    // URLs absolutas: Chrome Android puede ignorar rutas relativas en notificaciones push.
    icon: data.icon || new URL('icon-192.png', self.registration.scope).href,
    badge: data.badge || new URL('icon-192.png', self.registration.scope).href,
    tag: data.tag || 'el-radar-notification',
    renotify: true,
    requireInteraction: true,
    actions,
    data: {url: data.url || './', action_url: actionUrl, notification_id: data.notification_id || null, reference_type:refType, reference_id:refId}
  };
  event.waitUntil(self.registration.showNotification(title, options));
});
self.addEventListener('notificationclick', event => {
  event.notification.close();
  const noteData = event.notification.data || {};
  const action = event.action || 'open';
  let destination = noteData.url || './';
  if (action === 'respond') destination = noteData.action_url || destination;
  if (action === 'accept' || action === 'reject') { const u = new URL(noteData.action_url || destination, self.registration.scope); u.searchParams.set('decision', action === 'accept' ? 'aceptar' : 'rechazar'); destination = u.href; }
  const target = new URL(destination, self.registration.scope).href;
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({type:'window', includeUncontrolled:true});
    for (const client of windows) {
      if ('focus' in client) {
        await client.focus();
        if ('navigate' in client && client.url !== target) await client.navigate(target);
        return;
      }
    }
    if (self.clients.openWindow) await self.clients.openWindow(target);
  })());
});
const RADAR_VAPID_PUBLIC_KEY = 'BBD-v6lARrtb_CAuyzoYzdaAZT9Bvrd-JcRkxqSYohBIJ2Rafn5xpwWzvX-5-NOJdDjB0meE02kdRmRsiPT0Myg';
function radarPushKeyBytes(base64String) {
  const padding = '='.repeat((4 - base64String.length % 4) % 4);
  const raw = atob((base64String + padding).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, ch => ch.charCodeAt(0));
}
self.addEventListener('pushsubscriptionchange', event => {
  event.waitUntil((async () => {
    try {
      let subscription = event.newSubscription;
      if (!subscription) {
        subscription = await self.registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: radarPushKeyBytes(RADAR_VAPID_PUBLIC_KEY)
        });
      }
      const response = await fetch('./?action=push_subscribe', {
        method: 'POST',
        credentials: 'include',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({subscription: subscription.toJSON()})
      });
      if (!response.ok) throw new Error('No se pudo actualizar la suscripción push');
    } catch (error) {
      console.warn('El Radar: no se pudo renovar la suscripción push en segundo plano.', error);
    }
  })());
});
RADAR_SW;
    exit;
}

/*
 * EL RADAR — archivo único index.php
 * Control de sesión única por cuenta: un dispositivo activo a la vez.
 * Completa estos cuatro datos con los que muestra InfinityFree > MySQL Databases.
 */
if (isset($_GET['action'])) {
    $isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_set_cookie_params(0, '/', '', $isHttps, true);
        session_start();
    }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Content-Type-Options: nosniff');

    function radarReply($status, $data) {
        http_response_code($status);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }
    function radarBody() {
        $raw = file_get_contents('php://input');
        $data = json_decode($raw ?: '{}', true);
        return is_array($data) ? $data : [];
    }
    function radarSmtpSend(array $cfg, string $to, string $subject, string $body): bool {
        $host = trim((string)($cfg['host'] ?? '')); $port = (int)($cfg['port'] ?? 587);
        $mode = (string)($cfg['encryption'] ?? 'tls'); $user = (string)($cfg['user'] ?? '');
        $pass = (string)($cfg['password'] ?? ''); $from = (string)($cfg['email'] ?? '');
        $fromName = (string)($cfg['name'] ?? 'El Radar');
        if ($host === '' || !filter_var($from, FILTER_VALIDATE_EMAIL) || $port < 1 || $port > 65535) return false;
        $target = ($mode === 'ssl' ? 'ssl://' : '') . $host;
        $fp = @stream_socket_client($target . ':' . $port, $errno, $errstr, 15, STREAM_CLIENT_CONNECT);
        if (!$fp) { error_log('El Radar SMTP connect error: '.$errstr); return false; }
        stream_set_timeout($fp, 15);
        $read = static function() use ($fp) { $out=''; while (($line=fgets($fp, 515)) !== false) { $out.=$line; if (strlen($line)<4 || $line[3] !== '-') break; } return $out; };
        $cmd = static function($command, $expect) use ($fp, $read) { if ($command !== null) fwrite($fp, $command."\r\n"); $reply=$read(); return $reply !== '' && in_array((int)substr($reply,0,3), (array)$expect, true); };
        try {
            if (!$cmd(null,[220])) throw new RuntimeException('SMTP greeting failed');
            $hello = $_SERVER['HTTP_HOST'] ?? 'localhost';
            if (!$cmd('EHLO '.$hello,[250])) throw new RuntimeException('SMTP EHLO failed');
            if ($mode === 'tls') { if (!$cmd('STARTTLS',[220])) throw new RuntimeException('SMTP STARTTLS failed'); if (!stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('SMTP TLS failed'); if (!$cmd('EHLO '.$hello,[250])) throw new RuntimeException('SMTP EHLO after TLS failed'); }
            if ($user !== '') { if (!$cmd('AUTH LOGIN',[334]) || !$cmd(base64_encode($user),[334]) || !$cmd(base64_encode($pass),[235])) throw new RuntimeException('SMTP authentication failed'); }
            if (!$cmd('MAIL FROM:<'.$from.'>',[250]) || !$cmd('RCPT TO:<'.$to.'>',[250,251]) || !$cmd('DATA',[354])) throw new RuntimeException('SMTP envelope failed');
            $safeName = '=?UTF-8?B?'.base64_encode($fromName).'?=';
            $headers = 'From: '.$safeName.' <'.$from.">\r\n".'To: <'.$to.">\r\n".'Subject: =?UTF-8?B?'.base64_encode($subject)."?=\r\n".'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n" . 'Content-Transfer-Encoding: 8bit' . "\r\n\r\n";
            $message = $headers . str_replace(["\r\n", "\r"], "\n", $body);
            $message = preg_replace('/(?m)^\./', '..', $message);
            fwrite($fp, str_replace("\n", "\r\n", $message)."\r\n.\r\n");
            $ok = $read(); if ((int)substr($ok,0,3) !== 250) throw new RuntimeException('SMTP message rejected');
            $cmd('QUIT',[221]); fclose($fp); return true;
        } catch (Throwable $e) { error_log('El Radar SMTP send error: '.$e->getMessage()); fclose($fp); return false; }
    }
    function radarPhoto($value) {
        if ($value === null || $value === '') return null;
        if (!is_string($value) || !preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $value, $m)) {
            radarReply(422, ['ok'=>false, 'message'=>'La foto debe ser JPG, PNG o WEBP.']);
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > 2621440) {
            radarReply(422, ['ok'=>false, 'message'=>'La imagen de la novedad no puede superar 2,5 MB.']);
        }
        $imageInfo = @getimagesizefromstring($bytes);
        $allowed = [IMAGETYPE_JPEG=>'jpeg', IMAGETYPE_PNG=>'png', IMAGETYPE_WEBP=>'webp'];
        if (!$imageInfo || !isset($allowed[$imageInfo[2]]) || $allowed[$imageInfo[2]] !== $m[1]) {
            radarReply(422, ['ok'=>false, 'message'=>'El archivo seleccionado no es una imagen válida.']);
        }
        return 'data:image/' . $m[1] . ';base64,' . base64_encode($bytes);
    }
    function radarUserData($row) {
        return [
            'id' => (int)$row['ID'],
            'fecha_creacion' => isset($row['CREADA_EN']) ? $row['CREADA_EN'] : null,
            'usuario' => $row['USUARIO'],
            'nombre' => isset($row['NOMBRE']) ? $row['NOMBRE'] : '',
            'apellidos' => isset($row['APELLIDOS']) ? $row['APELLIDOS'] : '',
            'carnet_identidad' => isset($row['CARNET_IDENTIDAD']) ? $row['CARNET_IDENTIDAD'] : '',
            'telefono' => isset($row['TELEFONO']) ? $row['TELEFONO'] : '',
            'correo' => isset($row['CORREO']) ? $row['CORREO'] : '',
            'provincia' => isset($row['PROVINCIA']) ? $row['PROVINCIA'] : '',
            'municipio' => isset($row['MUNICIPIO']) ? $row['MUNICIPIO'] : '',
            'estado' => isset($row['ESTADO']) ? $row['ESTADO'] : 'Sin especificar',
            'tipo' => isset($row['TIPO']) ? $row['TIPO'] : 'cliente',
            'es_editor' => isset($row['ES_EDITOR']) ? (int)$row['ES_EDITOR'] : 0,
            'foto' => isset($row['FOTO']) ? $row['FOTO'] : null,
            'saldo' => isset($row['SALDO']) ? $row['SALDO'] : 0,
            'tiempo_disponible' => isset($row['TIEMPO_DISPONIBLE']) ? $row['TIEMPO_DISPONIBLE'] : 0,
            'prueba_inicio' => isset($row['PRUEBA_INICIO']) ? $row['PRUEBA_INICIO'] : null,
            'prueba_fin' => isset($row['PRUEBA_FIN']) ? $row['PRUEBA_FIN'] : null,
            'pago_hasta' => isset($row['PAGO_HASTA']) ? $row['PAGO_HASTA'] : null,
            'prueba_dias_restantes' => isset($row['PRUEBA_FIN']) && $row['PRUEBA_FIN'] ? max(0, (int)ceil((strtotime($row['PRUEBA_FIN']) - time()) / 86400)) : null,
            'prueba_vencida' => isset($row['PRUEBA_FIN']) && $row['PRUEBA_FIN'] && strtotime($row['PRUEBA_FIN']) <= time()
        ];
    }
    // Web Push propio (sin Firebase ni OneSignal). Requiere HTTPS y OpenSSL en PHP.
    function radarVapidConfig() {
        return [
            'public' => 'BBD-v6lARrtb_CAuyzoYzdaAZT9Bvrd-JcRkxqSYohBIJ2Rafn5xpwWzvX-5-NOJdDjB0meE02kdRmRsiPT0Myg',
            'private' => "-----BEGIN PRIVATE KEY-----\nMIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQg8sLY42q1mndMEYVS\nnVvQ0UNucSTnY97eVaPqRGjaqlGhRANCAAQQ/r+pQEa7W/wgLss6GM3WgGU/Qb63\nfiXEZMakmKIQSCdkWn5+cacFs71/ufjTiXQ4wdJnhNNpHUZkbIj09DMo\n-----END PRIVATE KEY-----\n",
            'subject' => 'mailto:soporte@elradar.app'
        ];
    }
    function radarB64UrlEncode($value) {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
    function radarB64UrlDecode($value) {
        $value = strtr((string)$value, '-_', '+/');
        $value .= str_repeat('=', (4 - strlen($value) % 4) % 4);
        $decoded = base64_decode($value, true);
        return $decoded === false ? '' : $decoded;
    }
    function radarHkdfExpand($prk, $info, $length) {
        $output = ''; $last = ''; $counter = 1;
        while (strlen($output) < $length) {
            $last = hash_hmac('sha256', $last . $info . chr($counter), $prk, true);
            $output .= $last; $counter++;
            if ($counter > 255) return false;
        }
        return substr($output, 0, $length);
    }
    function radarDerEcdsaToJose($der) {
        $offset = 0;
        if (ord($der[$offset++]) !== 0x30) return false;
        $seqLen = ord($der[$offset++]);
        if ($seqLen & 0x80) { $n = $seqLen & 0x7f; $seqLen = 0; for ($i=0; $i<$n; $i++) $seqLen = ($seqLen << 8) | ord($der[$offset++]); }
        if (ord($der[$offset++]) !== 0x02) return false;
        $rLen = ord($der[$offset++]); $r = substr($der, $offset, $rLen); $offset += $rLen;
        if (ord($der[$offset++]) !== 0x02) return false;
        $sLen = ord($der[$offset++]); $ss = substr($der, $offset, $sLen);
        $r = str_pad(substr(ltrim($r, "\0"), -32), 32, "\0", STR_PAD_LEFT);
        $ss = str_pad(substr(ltrim($ss, "\0"), -32), 32, "\0", STR_PAD_LEFT);
        return $r . $ss;
    }
    function radarWebPushEncrypt($payload, $p256dh, $authSecret) {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_pkey_derive')) return false;
        $uaPublic = radarB64UrlDecode($p256dh); $auth = radarB64UrlDecode($authSecret);
        if (strlen($uaPublic) !== 65 || $uaPublic[0] !== "\x04" || strlen($auth) < 16) return false;
        $clientDer = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200') . $uaPublic;
        $clientPem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($clientDer), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $clientKey = @openssl_pkey_get_public($clientPem);
        $serverKey = @openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_EC, 'curve_name'=>'prime256v1']);
        if (!$clientKey || !$serverKey) return false;
        $details = openssl_pkey_get_details($serverKey);
        if (empty($details['ec']['x']) || empty($details['ec']['y'])) return false;
        $serverPublic = "\x04" . $details['ec']['x'] . $details['ec']['y'];
        if (strlen($serverPublic) !== 65) return false;
        $shared = @openssl_pkey_derive($clientKey, $serverKey, 32);
        if ($shared === false) return false;
        $keyInfo = "WebPush: info\0" . $uaPublic . $serverPublic;
        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = radarHkdfExpand($prkKey, $keyInfo, 32);
        if ($ikm === false) return false;
        $salt = random_bytes(16);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = radarHkdfExpand($prk, "Content-Encoding: aes128gcm\0", 16);
        $nonce = radarHkdfExpand($prk, "Content-Encoding: nonce\0", 12);
        if ($cek === false || $nonce === false) return false;
        $tag = '';
        $cipher = @openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false || strlen($tag) !== 16) return false;
        return $salt . pack('N', 4096) . chr(strlen($serverPublic)) . $serverPublic . $cipher . $tag;
    }
    function radarWebPushSendOne($subscription, $title, $message, $notificationId = 0, $referenceType = null, $referenceId = null) {
        $cfg = radarVapidConfig();
        if (empty($cfg['private']) || empty($cfg['public']) || !function_exists('openssl_sign')) return 0;
        $endpoint = (string)$subscription['ENDPOINT'];
        $parts = parse_url($endpoint);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return 0;
        $audience = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $header = radarB64UrlEncode(json_encode(['typ'=>'JWT','alg'=>'ES256']));
        $claims = radarB64UrlEncode(json_encode(['aud'=>$audience,'exp'=>time()+43200,'sub'=>$cfg['subject']]));
        $unsigned = $header . '.' . $claims;
        $privateKey = @openssl_pkey_get_private($cfg['private']);
        if (!$privateKey || !@openssl_sign($unsigned, $derSignature, $privateKey, OPENSSL_ALGO_SHA256)) return 0;
        $signature = radarDerEcdsaToJose($derSignature);
        if ($signature === false) return 0;
        $jwt = $unsigned . '.' . radarB64UrlEncode($signature);
        // Enviar la URL absoluta del logotipo para que Chrome Android no use
        // el icono genérico del navegador cuando procesa el mensaje Push.
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string)($_SERVER['HTTP_HOST'] ?? '');
        $basePath = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
        $notificationIcon = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/icon-192.png';
        $payload = json_encode([
            'title'=>(string)$title,
            'body'=>(string)$message,
            'icon'=>$notificationIcon,
            'badge'=>$notificationIcon,
            'tag'=>'el-radar-' . (int)$notificationId,
            'url'=>'./',
            'notification_id'=>(int)$notificationId,
            'reference_type'=>(string)($referenceType ?? ''),
            'reference_id'=>(int)($referenceId ?? 0)
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = radarWebPushEncrypt($payload, $subscription['P256DH'], $subscription['AUTH_TOKEN']);
        if ($body === false) return 0;
        $headers = [
            'TTL: 86400',
            'Urgency: high',
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Authorization: vapid t=' . $jwt . ', k=' . $cfg['public']
        ];
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true, CURLOPT_HEADER=>true, CURLOPT_CONNECTTIMEOUT=>8, CURLOPT_TIMEOUT=>15]);
            $response = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return $status;
        }
        $context = stream_context_create(['http'=>['method'=>'POST','header'=>implode("\r\n", $headers), 'content'=>$body, 'timeout'=>15, 'ignore_errors'=>true]]);
        @file_get_contents($endpoint, false, $context);
        $status = 0;
        foreach (($http_response_header ?? []) as $line) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) $status = (int)$m[1];
        return $status;
    }
    function radarSendPushToUser($pdo, $userId, $title, $message, $notificationId = 0, $referenceType = null, $referenceId = null) {
        try {
            $q = $pdo->prepare('SELECT ID, ENDPOINT, P256DH, AUTH_TOKEN FROM NOTIFICACIONES_PUSH WHERE USUARIO_ID=?');
            $q->execute([(int)$userId]);
            foreach ($q->fetchAll() as $subscription) {
                $status = radarWebPushSendOne($subscription, $title, $message, $notificationId, $referenceType, $referenceId);
                if ($status === 404 || $status === 410) {
                    $pdo->prepare('DELETE FROM NOTIFICACIONES_PUSH WHERE ID=?')->execute([(int)$subscription['ID']]);
                } elseif ($status < 200 || $status >= 300) {
                    error_log('El Radar Web Push response HTTP ' . $status . ' for subscription ' . (int)$subscription['ID']);
                }
            }
        } catch (Exception $e) {
            error_log('El Radar Web Push error: ' . $e->getMessage());
        }
    }

    function radarNotify($pdo, $userId, $title, $message, $type = 'info', $referenceType = null, $referenceId = null) {
        $userId = (int)$userId;
        if ($userId < 1) return;
        $allowedTypes = ['info', 'success', 'warning', 'error'];
        if (!in_array($type, $allowedTypes, true)) $type = 'info';
        try {
            $stmt = $pdo->prepare('INSERT INTO NOTIFICACIONES (USUARIO_ID, TITULO, MENSAJE, TIPO, LEIDA, REFERENCIA_TIPO, REFERENCIA_ID) VALUES (?, ?, ?, ?, 0, ?, ?)');
            $cleanTitle = mb_substr((string)$title, 0, 120, 'UTF-8');
            $cleanMessage = mb_substr((string)$message, 0, 1000, 'UTF-8');
            $stmt->execute([$userId, $cleanTitle, $cleanMessage, $type, $referenceType, $referenceId]);
            $notificationId = (int)$pdo->lastInsertId();
            // Enviar también al sistema operativo mediante Web Push; no depende de que la página esté abierta.
            radarSendPushToUser($pdo, $userId, $cleanTitle, $cleanMessage, $notificationId, $referenceType, $referenceId);
        } catch (Exception $e) {
            // A notification failure must not undo the primary action.
            error_log('El Radar notification insert error: ' . $e->getMessage());
        }
    }

    /**
     * Recalcula los días de uso cuando cambia el tipo de cuenta, usando el saldo actual
     * y la tarifa diaria del nuevo tipo: días = floor(saldo / tarifa diaria).
     */
    function radarRecalculateDaysForType($pdo, $userId, $newType) {
        $userId = (int)$userId;
        $type = strtolower(trim((string)$newType));
        if ($userId < 1 || $type === 'admin') return;
        $q = $pdo->prepare('SELECT SALDO FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
        $q->execute([$userId]);
        $u = $q->fetch();
        if (!$u) return;
        $tariffs = $pdo->query('SELECT CLIENTE_IMPORTE, TRANSPORTISTA_IMPORTE FROM RADAR_TARIFAS_USO WHERE ID=1')->fetch();
        if (!$tariffs) return;
        $rate = ($type === 'transportista') ? (float)$tariffs['TRANSPORTISTA_IMPORTE'] : (float)$tariffs['CLIENTE_IMPORTE'];
        $balance = max(0.0, (float)$u['SALDO']);
        // Una tarifa no válida no debe conceder días gratuitos.
        $days = ($rate > 0 && is_finite($rate)) ? (int)floor(($balance + 0.000001) / $rate) : 0;
        $days = max(0, $days);
        $now = new DateTimeImmutable('now');
        $expiry = $days > 0 ? $now->modify('+' . $days . ' days')->format('Y-m-d H:i:s') : $now->format('Y-m-d H:i:s');
        $status = $days > 0 ? 'activo' : 'inactivo';
        $q = $pdo->prepare('UPDATE USUARIOS SET TIEMPO_DISPONIBLE=?, PAGO_HASTA=?, ESTADO=?, SALDO_ULTIMO_COBRO=? WHERE ID=?');
        $q->execute([$days, $expiry, $status, $now->format('Y-m-d H:i:s'), $userId]);
    }

    /**
     * Cobra el uso de la cuenta por días completos transcurridos.
     * La tarifa CLIENTE_IMPORTE o TRANSPORTISTA_IMPORTE se interpreta como tarifa diaria.
     * La columna SALDO_ULTIMO_COBRO evita cobrar dos veces el mismo día.
     */
    function radarApplyDailyCharge($pdo, $userId) {
        $userId = (int)$userId;
        if ($userId < 1) return;
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT ID, TIPO, SALDO, TIEMPO_DISPONIBLE, SALDO_ULTIMO_COBRO FROM USUARIOS WHERE ID=? FOR UPDATE');
            $q->execute([$userId]);
            $u = $q->fetch();
            if (!$u || strtolower(trim((string)$u['TIPO'])) === 'admin') {
                $pdo->commit();
                return;
            }
            $now = new DateTimeImmutable('now');
            if (empty($u['SALDO_ULTIMO_COBRO'])) {
                $init = $pdo->prepare('UPDATE USUARIOS SET SALDO_ULTIMO_COBRO=? WHERE ID=? AND SALDO_ULTIMO_COBRO IS NULL');
                $init->execute([$now->format('Y-m-d H:i:s'), $userId]);
                $pdo->commit();
                return;
            }
            $last = new DateTimeImmutable((string)$u['SALDO_ULTIMO_COBRO']);
            $elapsedDays = (int)floor(max(0, $now->getTimestamp() - $last->getTimestamp()) / 86400);
            if ($elapsedDays < 1) {
                $pdo->commit();
                return;
            }
            $availableDays = max(0, (int)$u['TIEMPO_DISPONIBLE']);
            $balance = max(0.0, (float)$u['SALDO']);
            $newLast = $last->modify('+' . $elapsedDays . ' days')->format('Y-m-d H:i:s');
            if ($availableDays < 1 || $balance <= 0) {
                $pdo->prepare('UPDATE USUARIOS SET SALDO_ULTIMO_COBRO=? WHERE ID=?')->execute([$newLast, $userId]);
                if ($balance <= 0 && $availableDays > 0) {
                    $pdo->prepare("UPDATE USUARIOS SET TIEMPO_DISPONIBLE=0, ESTADO='inactivo', PAGO_HASTA=NOW(), SALDO_ULTIMO_COBRO=? WHERE ID=?")
                        ->execute([$newLast, $userId]);
                }
                $pdo->commit();
                return;
            }
            $tariffs = $pdo->query('SELECT CLIENTE_IMPORTE, TRANSPORTISTA_IMPORTE FROM RADAR_TARIFAS_USO WHERE ID=1')->fetch();
            if (!$tariffs) {
                $pdo->commit();
                return;
            }
            $type = strtolower(trim((string)$u['TIPO']));
            $rate = ($type === 'transportista') ? (float)$tariffs['TRANSPORTISTA_IMPORTE'] : (float)$tariffs['CLIENTE_IMPORTE'];
            if (!is_finite($rate) || $rate <= 0) {
                $pdo->commit();
                return;
            }
            $daysToCharge = min($elapsedDays, $availableDays);
            $charge = min($balance, round($rate * $daysToCharge, 2));
            $newBalance = max(0.0, round($balance - $charge, 2));
            $newDays = max(0, $availableDays - $daysToCharge);
            if ($newBalance < 0.005) {
                $newBalance = 0.0;
                // El saldo agotado no debe dejar días de pago utilizables.
                $newDays = 0;
            }
            if ($newDays === 0 && $newBalance === 0.0) {
                $upd = $pdo->prepare("UPDATE USUARIOS SET SALDO=?, TIEMPO_DISPONIBLE=?, ESTADO='inactivo', PAGO_HASTA=NOW(), SALDO_ULTIMO_COBRO=? WHERE ID=?");
            } else {
                $upd = $pdo->prepare('UPDATE USUARIOS SET SALDO=?, TIEMPO_DISPONIBLE=?, SALDO_ULTIMO_COBRO=? WHERE ID=?');
            }
            $upd->execute([number_format($newBalance, 2, '.', ''), $newDays, $newLast, $userId]);
            if ($charge > 0) {
                $movement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO) VALUES (?, 'cobro_diario', ?, ?, ?, ?)");
                $movement->execute([$userId, 'Descuento automático por uso de la aplicación (' . $daysToCharge . ' día(s))', number_format($charge, 2, '.', ''), number_format($balance, 2, '.', ''), number_format($newBalance, 2, '.', '')]);
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar daily balance charge error: ' . $e->getMessage());
            // No interrumpir el resto de la aplicación por un fallo de facturación.
        }
    }

    // CONFIGURA AQUÍ LOS DATOS REALES DE MYSQL DE INFINITYFREE.
    $dbHost = 'sql102.infinityfree.com';
    $dbPort = '3306';
    $dbName = 'if0_43281780_Radal';
    $dbUser = 'if0_43281780';
    $dbPass = 'Alexalg19912510';
    $dbConfigFile = __DIR__ . '/.radar_db_config.php';
    if (is_file($dbConfigFile)) {
        if (!defined('RADAR_DB_CONFIG_BOOTSTRAPPED')) define('RADAR_DB_CONFIG_BOOTSTRAPPED', true);
        $savedDbConfig = require $dbConfigFile;
        if (is_array($savedDbConfig)) {
            $dbHost = (string)($savedDbConfig['host'] ?? $dbHost);
            $dbPort = (string)($savedDbConfig['port'] ?? $dbPort);
            $dbName = (string)($savedDbConfig['name'] ?? $dbName);
            $dbUser = (string)($savedDbConfig['user'] ?? $dbUser);
            $dbPass = (string)($savedDbConfig['pass'] ?? $dbPass);
        }
    }

    try {
        $pdo = new PDO(
            "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        );
    } catch (Exception $e) {
        error_log('El Radar DB error: ' . $e->getMessage());
        radarReply(500, ['ok'=>false, 'message'=>'No se pudo conectar con MySQL. Revisa los datos de conexión al principio de index.php.']);
    }

    $action = (string)(isset($_GET['action']) ? $_GET['action'] : '');

    // Inicialización/migraciones al entrar a acciones normales. Las peticiones sync
    // omiten este bloque para no ejecutar CREATE/ALTER continuamente durante el sondeo.
    if ($action !== 'sync' && $action !== 'location_update' && $action !== 'nearby_transportistas' && !in_array($action, ['notifications_list', 'notification_mark_read', 'notifications_mark_all_read', 'android_poll', 'trip_arrival_check'], true)) {
    try {
        // En una instalación nueva crea la tabla de usuarios; en una instalación existente no la reemplaza.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `USUARIOS` (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `USUARIO` VARCHAR(100) NOT NULL,
            `CONTRASENA` VARCHAR(255) NOT NULL,
            `TIPO` VARCHAR(40) NOT NULL DEFAULT 'cliente',
            `ESTADO` VARCHAR(30) NOT NULL DEFAULT 'activo',
            `FOTO` MEDIUMTEXT NULL,
            `NOMBRE` VARCHAR(100) NULL,
            `APELLIDOS` VARCHAR(150) NULL,
            `CARNET_IDENTIDAD` VARCHAR(11) NULL,
            `SALDO` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `TIEMPO_DISPONIBLE` INT NOT NULL DEFAULT 0,
            `UBICACION` TEXT NULL,
            `CREADA_EN` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_usuarios_usuario` (`USUARIO`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Agrega solo columnas ausentes, preservando todas las filas y columnas que ya existan.
        $columnsToAdd = [
            'FOTO' => 'MEDIUMTEXT NULL',
            'NOMBRE' => 'VARCHAR(100) NULL',
            'APELLIDOS' => 'VARCHAR(150) NULL',
            'CARNET_IDENTIDAD' => 'VARCHAR(11) NULL',
            'TELEFONO' => 'VARCHAR(25) NULL',
            'CORREO' => 'VARCHAR(190) NULL',
            'PROVINCIA' => 'VARCHAR(80) NULL',
            'MUNICIPIO' => 'VARCHAR(100) NULL',
            'SALDO' => 'DECIMAL(12,2) NOT NULL DEFAULT 0.00',
            'TIEMPO_DISPONIBLE' => 'INT NOT NULL DEFAULT 0',
            'UBICACION' => 'TEXT NULL',
            'CREADA_EN' => 'DATETIME NULL',
            'PRUEBA_INICIO' => 'DATETIME NULL',
            'PRUEBA_FIN' => 'DATETIME NULL',
            'PAGO_HASTA' => 'DATETIME NULL',
            'SALDO_ULTIMO_COBRO' => 'DATETIME NULL',
            'TOKEN_SESION' => 'CHAR(64) NULL',
            'CODIGO_REFERIDO' => 'VARCHAR(40) NULL',
            'REFERIDO_POR' => 'INT NULL',
            'RADIO_LLEGADA_M' => 'TINYINT NOT NULL DEFAULT 10',
            'ES_EDITOR' => 'TINYINT(1) NOT NULL DEFAULT 0',
            'TARJETA_PAGO' => 'VARCHAR(30) NULL'
        ];
        foreach ($columnsToAdd as $columnName => $columnDefinition) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute(['USUARIOS', $columnName]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `USUARIOS` ADD COLUMN `' . $columnName . '` ' . $columnDefinition);
            }
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_PASSWORD_RESETS` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `TOKEN_HASH` CHAR(64) NOT NULL,
            `EXPIRA_EN` DATETIME NOT NULL,
            `CREADO_EN` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_radar_reset_token` (`TOKEN_HASH`),
            KEY `idx_radar_reset_user` (`USUARIO_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $refIndexCheck = $pdo->query("SHOW INDEX FROM `USUARIOS` WHERE Key_name='uq_usuarios_codigo_referido'");
        if (!$refIndexCheck->fetch()) $pdo->exec('ALTER TABLE `USUARIOS` ADD UNIQUE KEY `uq_usuarios_codigo_referido` (`CODIGO_REFERIDO`)');

        // Registro de versión de esquema para que las próximas ampliaciones se gestionen como migraciones.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_SCHEMA_VERSION` (
            `ID` TINYINT NOT NULL PRIMARY KEY,
            `VERSION` INT NOT NULL DEFAULT 1,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec('INSERT INTO `RADAR_SCHEMA_VERSION` (`ID`, `VERSION`) VALUES (1, 1) ON DUPLICATE KEY UPDATE `ID` = VALUES(`ID`)');

        // Solicitudes de cambio de tipo de cuenta a transportista.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `SOLICITUDES_TRANSPORTISTA` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `USUARIO` VARCHAR(100) NOT NULL,
            `NOMBRE` VARCHAR(100) NOT NULL DEFAULT '',
            `APELLIDOS` VARCHAR(150) NOT NULL DEFAULT '',
            `CARNET_IDENTIDAD` VARCHAR(11) NULL,
            `TIPO_VEHICULO` VARCHAR(60) NULL,
            `CHAPA` VARCHAR(30) NULL,
            `LICENCIA_CONDUCCION` VARCHAR(60) NULL,
            `TELEFONO` VARCHAR(30) NULL,
            `FOTO_VEHICULO` MEDIUMTEXT NULL,
            `ESTADO_SOLICITUD` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            `TIPO_ANTERIOR` VARCHAR(40) NULL,
            `NOTA_ADMIN` VARCHAR(500) NULL,
            `CREADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            KEY `idx_transportista_usuario_estado` (`USUARIO_ID`, `ESTADO_SOLICITUD`),
            KEY `idx_transportista_estado_fecha` (`ESTADO_SOLICITUD`, `CREADA_EN`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Historial de auditoría: identifica qué usuario aprobó cada transportista.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_AUDITORIA_TRANSPORTISTAS` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `SOLICITUD_ID` BIGINT UNSIGNED NOT NULL,
            `TRANSPORTISTA_ID` INT NOT NULL,
            `TRANSPORTISTA_USUARIO` VARCHAR(100) NOT NULL,
            `APROBADO_POR_ID` INT NOT NULL,
            `APROBADO_POR_USUARIO` VARCHAR(100) NOT NULL,
            `FECHA_APROBACION` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACCION` VARCHAR(20) NOT NULL DEFAULT 'APROBADO',
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_auditoria_solicitud` (`SOLICITUD_ID`),
            KEY `idx_auditoria_transportista` (`TRANSPORTISTA_ID`),
            KEY `idx_auditoria_aprobador` (`APROBADO_POR_ID`),
            KEY `idx_auditoria_fecha` (`FECHA_APROBACION`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Permite múltiples eventos por solicitud (aprobación y posteriores revocaciones).
        $auditUnique = $pdo->query("SHOW INDEX FROM `RADAR_AUDITORIA_TRANSPORTISTAS` WHERE Key_name='uq_auditoria_solicitud'");
        if ($auditUnique && $auditUnique->fetch()) $pdo->exec('ALTER TABLE `RADAR_AUDITORIA_TRANSPORTISTAS` DROP INDEX `uq_auditoria_solicitud`');
        $editorPercentCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $editorPercentCheck->execute(['RADAR_TARIFAS_USO','PORCENTAJE_EDITOR']);
        if (!(int)$editorPercentCheck->fetchColumn()) $pdo->exec('ALTER TABLE `RADAR_TARIFAS_USO` ADD COLUMN `PORCENTAJE_EDITOR` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `RECOMPENSA_COMPARTIR`');

        // Amplía la tabla ya existente sin borrar las solicitudes anteriores.
        $transportColumnsToAdd = [
            'TIPO_VEHICULO' => 'VARCHAR(60) NULL',
            'CHAPA' => 'VARCHAR(30) NULL',
            'LICENCIA_CONDUCCION' => 'VARCHAR(60) NULL',
            'TELEFONO' => 'VARCHAR(30) NULL',
            'FOTO_VEHICULO' => 'MEDIUMTEXT NULL',
            'TIPO_ANTERIOR' => 'VARCHAR(40) NULL'
        ];
        foreach ($transportColumnsToAdd as $columnName => $columnDefinition) {
            $check = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
            $check->execute(['SOLICITUDES_TRANSPORTISTA', $columnName]);
            if ((int)$check->fetchColumn() === 0) {
                $pdo->exec('ALTER TABLE `SOLICITUDES_TRANSPORTISTA` ADD COLUMN `' . $columnName . '` ' . $columnDefinition);
            }
        }

        // Transferencias disponibles: el cliente confirma y reclama su ID pendiente desde Agregar saldo; el sistema acredita automáticamente.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `Confirmacion` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `ID_TRANSFERENCIA` VARCHAR(150) NOT NULL,
            `FECHA` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `IMPORTE` DECIMAL(12,2) NOT NULL,
            `FECHA_CONFIRMACION` DATETIME NULL DEFAULT NULL,
            `USUARIO` VARCHAR(100) NULL DEFAULT NULL,
            PRIMARY KEY (`ID`),
            KEY `idx_confirmacion_usuario` (`USUARIO`),
            KEY `idx_confirmacion_fecha` (`FECHA`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Libro histórico de movimientos: registra créditos, recargas, transferencias y cobros diarios.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_MOVIMIENTOS_SALDO` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `TIPO` VARCHAR(40) NOT NULL,
            `DESCRIPCION` VARCHAR(255) NOT NULL,
            `IMPORTE` DECIMAL(12,2) NOT NULL,
            `SALDO_ANTERIOR` DECIMAL(12,2) NOT NULL,
            `SALDO_NUEVO` DECIMAL(12,2) NOT NULL,
            `REFERENCIA` VARCHAR(150) NULL,
            `CREADO_EN` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            KEY `idx_radar_mov_usuario_fecha` (`USUARIO_ID`, `CREADO_EN`, `ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Guarda el porcentaje del Editor vigente exactamente al confirmar cada recarga.
        $editorPercentColumn = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='RADAR_MOVIMIENTOS_SALDO' AND COLUMN_NAME='PORCENTAJE_EDITOR_APLICADO'");
        $editorPercentColumn->execute();
        if (!(int)$editorPercentColumn->fetchColumn()) {
            $pdo->exec("ALTER TABLE `RADAR_MOVIMIENTOS_SALDO` ADD COLUMN `PORCENTAJE_EDITOR_APLICADO` DECIMAL(7,2) NULL DEFAULT NULL AFTER `REFERENCIA`");
        }

        // Registro de pagos a editores. Conserva el importe, tarjeta destino e ID de transferencia pagado.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_PAGOS_EDITORES` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `EDITOR_ID` INT NOT NULL,
            `EDITOR_USUARIO` VARCHAR(100) NOT NULL,
            `TARJETA_PAGO` VARCHAR(30) NOT NULL,
            `IMPORTE` DECIMAL(12,2) NOT NULL,
            `ID_TRANSFERENCIA` VARCHAR(150) NOT NULL,
            `ADMIN_ID` INT NOT NULL,
            `ADMIN_USUARIO` VARCHAR(100) NOT NULL,
            `CREADO_EN` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_radar_pago_editor_transferencia` (`ID_TRANSFERENCIA`),
            KEY `idx_radar_pagos_editor_fecha` (`EDITOR_ID`,`CREADO_EN`,`ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Permite que las transferencias importadas estén pendientes de asignación a un usuario.
        $confirmUserColumn = $pdo->prepare("SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'Confirmacion' AND COLUMN_NAME = 'USUARIO' LIMIT 1");
        $confirmUserColumn->execute();
        $confirmUserNullable = $confirmUserColumn->fetchColumn();
        if ($confirmUserNullable === 'NO') {
            $pdo->exec("ALTER TABLE `Confirmacion` MODIFY COLUMN `USUARIO` VARCHAR(100) NULL DEFAULT NULL");
        }

        // Centro de notificaciones persistentes para registrar acciones importantes dentro de la app.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `NOTIFICACIONES` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `TITULO` VARCHAR(120) NOT NULL,
            `MENSAJE` VARCHAR(1000) NOT NULL,
            `TIPO` VARCHAR(20) NOT NULL DEFAULT 'info',
            `LEIDA` TINYINT(1) NOT NULL DEFAULT 0,
            `CREADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            KEY `idx_notificaciones_usuario_fecha` (`USUARIO_ID`, `ID`),
            KEY `idx_notificaciones_usuario_leida` (`USUARIO_ID`, `LEIDA`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Solicitudes de viaje enviadas por clientes a un transportista específico.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `SOLICITUDES_VIAJE` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `CLIENTE_ID` INT NOT NULL,
            `TRANSPORTISTA_ID` INT NOT NULL,
            `DESTINO` VARCHAR(500) NOT NULL,
            `PERSONAS` TINYINT UNSIGNED NOT NULL,
            `FECHA_HORA` VARCHAR(40) NULL,
            `OBSERVACIONES` VARCHAR(1000) NULL,
            `ORIGEN_LAT` DECIMAL(10,7) NULL,
            `ORIGEN_LNG` DECIMAL(10,7) NULL,
            `ORIGEN_DIRECCION` VARCHAR(500) NULL,
            `ESTADO` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
            `CREADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            KEY `idx_solicitud_viaje_transportista` (`TRANSPORTISTA_ID`, `ESTADO`, `ID`),
            KEY `idx_solicitud_viaje_cliente` (`CLIENTE_ID`, `ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Campos de relación y precio para el flujo de respuesta a solicitudes de viaje.
        foreach ([
            'NOTIFICACIONES' => [
                'REFERENCIA_TIPO' => "ALTER TABLE NOTIFICACIONES ADD COLUMN REFERENCIA_TIPO VARCHAR(30) NULL",
                'REFERENCIA_ID' => "ALTER TABLE NOTIFICACIONES ADD COLUMN REFERENCIA_ID BIGINT UNSIGNED NULL"
            ],
            'SOLICITUDES_VIAJE' => [
                'PRECIO' => "ALTER TABLE SOLICITUDES_VIAJE ADD COLUMN PRECIO DECIMAL(12,2) NULL"
            ]
        ] as $tableName => $columns) {
            foreach ($columns as $columnName => $alterSql) {
                $checkColumn = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                $checkColumn->execute([$tableName, $columnName]);
                if (!(int)$checkColumn->fetchColumn()) $pdo->exec($alterSql);
            }
        }
        // Configuración de tarifas de uso por tipo de cuenta; un único registro vigente.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_TARIFAS_USO` (
            `ID` TINYINT NOT NULL PRIMARY KEY,
            `CLIENTE_IMPORTE` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `CLIENTE_PERIODO_CANTIDAD` INT NOT NULL DEFAULT 30,
            `CLIENTE_PERIODO_UNIDAD` VARCHAR(10) NOT NULL DEFAULT 'dias',
            `TRANSPORTISTA_IMPORTE` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `TRANSPORTISTA_PERIODO_CANTIDAD` INT NOT NULL DEFAULT 30,
            `TRANSPORTISTA_PERIODO_UNIDAD` VARCHAR(10) NOT NULL DEFAULT 'dias',
            `PRUEBA_DIAS` INT NOT NULL DEFAULT 0,
            `RECOMPENSA_COMPARTIR` DECIMAL(12,2) NOT NULL DEFAULT 0.20,
            `ACTUALIZADO_POR` INT NULL,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Migración segura para instalaciones que ya tenían la tabla de tarifas.
        $trialColumnCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $trialColumnCheck->execute(['RADAR_TARIFAS_USO', 'PRUEBA_DIAS']);
        if ((int)$trialColumnCheck->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE `RADAR_TARIFAS_USO` ADD COLUMN `PRUEBA_DIAS` INT NOT NULL DEFAULT 0 AFTER `TRANSPORTISTA_PERIODO_UNIDAD`');
        }
        $shareRewardCheck = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $shareRewardCheck->execute(['RADAR_TARIFAS_USO', 'RECOMPENSA_COMPARTIR']);
        if (!(int)$shareRewardCheck->fetchColumn()) $pdo->exec('ALTER TABLE `RADAR_TARIFAS_USO` ADD COLUMN `RECOMPENSA_COMPARTIR` DECIMAL(12,2) NOT NULL DEFAULT 0.20 AFTER `PRUEBA_DIAS`');
        $pdo->exec("INSERT INTO `RADAR_TARIFAS_USO` (`ID`) VALUES (1) ON DUPLICATE KEY UPDATE `ID`=VALUES(`ID`)");

        // Catálogo administrable de tipos de transporte para las cuentas Transportista.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_TIPOS_TRANSPORTE` (
            `ID` INT NOT NULL AUTO_INCREMENT,
            `NOMBRE` VARCHAR(60) NOT NULL,
            `ACTIVO` TINYINT(1) NOT NULL DEFAULT 1,
            `ORDEN` INT NOT NULL DEFAULT 0,
            `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_radar_tipo_transporte_nombre` (`NOMBRE`),
            KEY `idx_radar_tipo_transporte_activo_orden` (`ACTIVO`, `ORDEN`, `NOMBRE`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $transportTypeCount = (int)$pdo->query('SELECT COUNT(*) FROM `RADAR_TIPOS_TRANSPORTE`')->fetchColumn();
        if ($transportTypeCount === 0) {
            $seedTypes = ['Moto','Auto / automóvil','Taxi','Camioneta','Microbús','Ómnibus','Camión','Otro'];
            $seed = $pdo->prepare('INSERT IGNORE INTO `RADAR_TIPOS_TRANSPORTE` (`NOMBRE`, `ACTIVO`, `ORDEN`) VALUES (?, 1, ?)');
            foreach ($seedTypes as $seedOrder => $seedName) $seed->execute([$seedName, $seedOrder + 1]);
        }

        // Cupones promocionales y registro único de canjes por usuario.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_CUPONES` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `CODIGO` VARCHAR(40) NOT NULL,
            `DESCRIPCION` VARCHAR(180) NOT NULL DEFAULT '',
            `CONDICION_TEXTO` VARCHAR(500) NULL,
            `TIPO_USUARIO` VARCHAR(20) NOT NULL DEFAULT 'todos',
            `BONO_IMPORTE` DECIMAL(12,2) NOT NULL,
            `SALDO_MINIMO` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            `MAX_USOS` INT NOT NULL DEFAULT 0,
            `USOS` INT NOT NULL DEFAULT 0,
            `FECHA_INICIO` DATETIME NULL,
            `FECHA_FIN` DATETIME NULL,
            `ACTIVO` TINYINT(1) NOT NULL DEFAULT 1,
            `CREADO_POR` INT NULL,
            `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`), UNIQUE KEY `uq_radar_cupon_codigo` (`CODIGO`), KEY `idx_radar_cupon_activo_fechas` (`ACTIVO`,`FECHA_INICIO`,`FECHA_FIN`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_CUPONES_USOS` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `CUPON_ID` BIGINT UNSIGNED NOT NULL,
            `USUARIO_ID` INT NOT NULL,
            `IMPORTE` DECIMAL(12,2) NOT NULL,
            `SALDO_ANTERIOR` DECIMAL(12,2) NOT NULL,
            `SALDO_NUEVO` DECIMAL(12,2) NOT NULL,
            `USADO_EN` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`), UNIQUE KEY `uq_radar_cupon_usuario` (`CUPON_ID`,`USUARIO_ID`), KEY `idx_radar_cupon_uso_usuario` (`USUARIO_ID`,`USADO_EN`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Ofertas automáticas por importe mínimo de recarga y porcentaje de bonificación.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_OFERTAS_RECARGA` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `IMPORTE_RECARGA` DECIMAL(12,2) NOT NULL,
            `PORCENTAJE` DECIMAL(7,2) NOT NULL,
            `TIPO_USUARIO` VARCHAR(20) NOT NULL DEFAULT 'todos',
            `FECHA_INICIO` DATETIME NULL,
            `FECHA_FIN` DATETIME NULL,
            `ACTIVO` TINYINT(1) NOT NULL DEFAULT 1,
            `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`), KEY `idx_radar_oferta_recarga` (`ACTIVO`,`IMPORTE_RECARGA`,`TIPO_USUARIO`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Publicaciones de novedades, promociones y comunicaciones de la aplicación.
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_NOVEDADES` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `TITULO` VARCHAR(150) NOT NULL,
            `CATEGORIA` VARCHAR(30) NOT NULL DEFAULT 'informacion',
            `CONTENIDO` TEXT NOT NULL,
            `IMAGEN` MEDIUMTEXT NULL,
            `TIPO_USUARIO` VARCHAR(20) NOT NULL DEFAULT 'todos',
            `FECHA_INICIO` DATETIME NULL,
            `FECHA_FIN` DATETIME NULL,
            `ACTIVO` TINYINT(1) NOT NULL DEFAULT 1,
            `CREADO_POR` INT NULL,
            `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`), KEY `idx_radar_novedades_publicacion` (`ACTIVO`,`FECHA_INICIO`,`FECHA_FIN`,`ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Migración compatible con bases de datos donde RADAR_NOVEDADES ya existía
        // con una estructura anterior o incompleta. Se comprueba cada columna antes
        // de crearla; así no se confunde "ya existe" con un fallo de permisos MySQL.
        $newsColumns = [];
        foreach ($pdo->query("SHOW COLUMNS FROM `RADAR_NOVEDADES`")->fetchAll(PDO::FETCH_ASSOC) as $columnInfo) {
            $newsColumns[strtoupper((string)$columnInfo['Field'])] = true;
        }
        $newsMigrations = [
            'TITULO' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `TITULO` VARCHAR(150) NOT NULL DEFAULT ''",
            'CATEGORIA' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `CATEGORIA` VARCHAR(30) NOT NULL DEFAULT 'informacion'",
            'CONTENIDO' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `CONTENIDO` TEXT NULL",
            'IMAGEN' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `IMAGEN` MEDIUMTEXT NULL",
            'TIPO_USUARIO' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `TIPO_USUARIO` VARCHAR(20) NOT NULL DEFAULT 'todos'",
            'FECHA_INICIO' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `FECHA_INICIO` DATETIME NULL",
            'FECHA_FIN' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `FECHA_FIN` DATETIME NULL",
            'ACTIVO' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `ACTIVO` TINYINT(1) NOT NULL DEFAULT 1",
            'CREADO_POR' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `CREADO_POR` INT NULL",
            'CREADO_EN' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'ACTUALIZADO_EN' => "ALTER TABLE `RADAR_NOVEDADES` ADD COLUMN `ACTUALIZADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
        ];
        foreach ($newsMigrations as $columnName => $migrationSql) {
            if (!isset($newsColumns[$columnName])) {
                $pdo->exec($migrationSql);
            }
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS `NOTIFICACIONES_PUSH` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `ENDPOINT_HASH` CHAR(64) NOT NULL,
            `ENDPOINT` TEXT NOT NULL,
            `P256DH` VARCHAR(255) NOT NULL,
            `AUTH_TOKEN` VARCHAR(255) NOT NULL,
            `CREADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ACTUALIZADA_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_push_endpoint_hash` (`ENDPOINT_HASH`),
            KEY `idx_push_usuario` (`USUARIO_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        // Tokens propios para la app Android Kodular (sin Firebase).
        $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_ANDROID_DEVICES` (
            `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `USUARIO_ID` INT NOT NULL,
            `TOKEN_HASH` CHAR(64) NOT NULL,
            `NOMBRE_DISPOSITIVO` VARCHAR(120) NULL,
            `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `ULTIMO_USO` DATETIME NULL,
            `REVOCADO` TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (`ID`),
            UNIQUE KEY `uq_android_token_hash` (`TOKEN_HASH`),
            KEY `idx_android_usuario` (`USUARIO_ID`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Exception $e) {
        error_log('El Radar schema error: ' . $e->getMessage());
        radarReply(500, ['ok'=>false, 'message'=>'No se pudo inicializar automáticamente la estructura de la base de datos. Verifica que el usuario MySQL tenga permisos CREATE y ALTER, y que la tabla se llame USUARIOS. Detalle: ' . $e->getMessage()]);
    }
    }

    // Sesión única por cuenta: cada inicio de sesión reemplaza el token anterior.
    // Las peticiones del dispositivo anterior se invalidan inmediatamente al siguiente sondeo/API.
    if (!empty($_SESSION['user_id']) && !in_array($action, ['login', 'register', 'password_reset_request', 'password_reset_complete', 'health', 'android_authenticate', 'android_poll', 'macro_transfer_import'], true)) {
        $sessionUserId = (int)$_SESSION['user_id'];
        $sessionToken = isset($_SESSION['session_token']) ? (string)$_SESSION['session_token'] : '';
        $sessionCheck = $pdo->prepare('SELECT TOKEN_SESION FROM USUARIOS WHERE ID=? LIMIT 1');
        $sessionCheck->execute([$sessionUserId]);
        $storedSessionHash = (string)($sessionCheck->fetchColumn() ?: '');
        $providedSessionHash = $sessionToken !== '' ? hash('sha256', $sessionToken) : '';
        if ($storedSessionHash === '' || $providedSessionHash === '' || !hash_equals($storedSessionHash, $providedSessionHash)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', time()-42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
            session_destroy();
            radarReply(401, ['ok'=>false, 'sessionExpired'=>true, 'code'=>'SESSION_REPLACED', 'message'=>'Esta cuenta inició sesión en otro dispositivo. Por seguridad, esta sesión se ha cerrado.']);
        }
    }

    // Entrada segura para MacroDroid: registra transferencias recibidas por SMS en Confirmacion.
    // La clave se almacena como hash en .radar_macro_config.php; nunca se guarda en texto en MySQL.
    if ($action === 'macro_transfer_import') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') radarReply(405, ['ok'=>false, 'message'=>'Utiliza POST.']);
        $macroConfigPath = __DIR__ . '/.radar_macro_config.php';
        $macroConfig = is_file($macroConfigPath) ? require $macroConfigPath : [];
        $expectedHash = is_array($macroConfig) ? (string)($macroConfig['token_hash'] ?? '') : '';
        $providedToken = (string)($_SERVER['HTTP_X_RADAR_TOKEN'] ?? '');
        if ($expectedHash === '' || $providedToken === '' || !hash_equals($expectedHash, hash('sha256', $providedToken))) {
            radarReply(401, ['ok'=>false, 'message'=>'Clave de integración no válida.']);
        }
        $data = radarBody();
        $transferId = trim((string)($data['id_transferencia'] ?? ''));
        $amountRaw = trim((string)($data['importe'] ?? ''));
        $amountRaw = str_replace(["\u{00A0}", ' '], '', $amountRaw);
        // MacroDroid debe enviar el importe normalizado con punto decimal y sin separador de miles.
        if ($transferId === '' || mb_strlen($transferId, 'UTF-8') > 150 || !preg_match('/^[A-Za-z0-9._\/-]+$/', $transferId)) {
            radarReply(422, ['ok'=>false, 'message'=>'El ID de transferencia está vacío o tiene un formato no válido.']);
        }
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountRaw)) {
            radarReply(422, ['ok'=>false, 'message'=>'El importe debe enviarse como número decimal, por ejemplo 1250.00.']);
        }
        $amount = round((float)$amountRaw, 2);
        if (!is_finite($amount) || $amount <= 0) radarReply(422, ['ok'=>false, 'message'=>'El importe debe ser mayor que cero.']);
        $dateRaw = trim((string)($data['fecha'] ?? ''));
        try {
            $transferDate = $dateRaw !== '' ? new DateTime($dateRaw) : new DateTime('now');
            $transferDateSql = $transferDate->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            radarReply(422, ['ok'=>false, 'message'=>'La fecha debe ser válida; utiliza YYYY-MM-DD HH:MM:SS.']);
        }
        $smsText = (string)($data['sms'] ?? '');
        $smsHash = hash('sha256', $smsText !== '' ? $smsText : ($transferId . '|' . $amountRaw . '|' . $transferDateSql));
        try {
            $pdo->beginTransaction();
            $pdo->exec("CREATE TABLE IF NOT EXISTS `RADAR_MACRODROID_IMPORTS` (
                `ID` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `ID_TRANSFERENCIA` VARCHAR(150) NOT NULL,
                `IMPORTE` DECIMAL(12,2) NOT NULL,
                `FECHA` DATETIME NOT NULL,
                `SMS_HASH` CHAR(64) NOT NULL,
                `CONFIRMACION_ID` BIGINT UNSIGNED NULL,
                `CREADO_EN` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`ID`),
                UNIQUE KEY `uq_macro_transferencia` (`ID_TRANSFERENCIA`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            $seen = $pdo->prepare('SELECT ID, CONFIRMACION_ID FROM RADAR_MACRODROID_IMPORTS WHERE ID_TRANSFERENCIA=? LIMIT 1');
            $seen->execute([$transferId]);
            if ($seen->fetch()) {
                $pdo->rollBack();
                radarReply(200, ['ok'=>true, 'duplicada'=>true, 'message'=>'Esta transferencia ya fue recibida anteriormente. No se creó otro registro.']);
            }
            $existing = $pdo->prepare('SELECT ID FROM Confirmacion WHERE TRIM(ID_TRANSFERENCIA)=? LIMIT 1');
            $existing->execute([$transferId]);
            if ($existing->fetch()) {
                $pdo->rollBack();
                radarReply(200, ['ok'=>true, 'duplicada'=>true, 'message'=>'El ID ya existe en CONFIRMACION. No se modificó el registro existente.']);
            }
            $log = $pdo->prepare('INSERT INTO RADAR_MACRODROID_IMPORTS (ID_TRANSFERENCIA, IMPORTE, FECHA, SMS_HASH) VALUES (?,?,?,?)');
            $log->execute([$transferId, number_format($amount, 2, '.', ''), $transferDateSql, $smsHash]);
            $ins = $pdo->prepare('INSERT INTO Confirmacion (ID_TRANSFERENCIA, FECHA, IMPORTE, FECHA_CONFIRMACION, USUARIO) VALUES (?, ?, ?, NULL, NULL)');
            $ins->execute([$transferId, $transferDateSql, number_format($amount, 2, '.', '')]);
            $confirmId = (int)$pdo->lastInsertId();
            $upd = $pdo->prepare('UPDATE RADAR_MACRODROID_IMPORTS SET CONFIRMACION_ID=? WHERE ID_TRANSFERENCIA=?');
            $upd->execute([$confirmId, $transferId]);
            $pdo->commit();
            radarReply(201, ['ok'=>true, 'duplicada'=>false, 'message'=>'Transferencia registrada como pendiente en CONFIRMACION.', 'id'=>$confirmId, 'id_transferencia'=>$transferId, 'importe'=>$amount, 'fecha'=>$transferDateSql]);
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string)$e->getCode() === '23000') radarReply(200, ['ok'=>true, 'duplicada'=>true, 'message'=>'La transferencia ya estaba registrada.']);
            error_log('El Radar MacroDroid import DB error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo guardar la transferencia.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar MacroDroid import error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo procesar la transferencia.']);
        }
    }

    // Configuración SMTP: acceso exclusivo al administrador.
    if (in_array($action, ['admin_smtp_info','admin_smtp_save','admin_smtp_test'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión como administrador.']);
        $check = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1'); $check->execute([(int)$_SESSION['user_id']]);
        if (strtolower(trim((string)$check->fetchColumn())) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'Solo un administrador puede modificar el correo SMTP.']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') radarReply(405, ['ok'=>false, 'message'=>'Método no permitido.']);
        $file = __DIR__ . '/.radar_smtp_config.php';
        if (!defined('RADAR_SMTP_CONFIG_BOOTSTRAPPED')) define('RADAR_SMTP_CONFIG_BOOTSTRAPPED', true);
        $cfg = is_file($file) ? (require $file) : [];
        if (!is_array($cfg)) $cfg=[];
        if ($action === 'admin_smtp_info') radarReply(200, ['ok'=>true,'smtp'=>['host'=>$cfg['host']??'','port'=>$cfg['port']??587,'encryption'=>$cfg['encryption']??'tls','user'=>$cfg['user']??'','email'=>$cfg['email']??'','name'=>$cfg['name']??'El Radar','configured'=>!empty($cfg['password'])]]);
        $d = radarBody();
        if ($action === 'admin_smtp_save') {
            $host=trim((string)($d['host']??'')); $port=trim((string)($d['port']??'587')); $enc=(string)($d['encryption']??'tls');
            $user=trim((string)($d['user']??'')); $email=trim((string)($d['email']??'')); $name=trim((string)($d['name']??'El Radar')); $pass=(string)($d['password']??'');
            if ($host==='' || strlen($host)>255 || !preg_match('/^[a-zA-Z0-9.-]+$/',$host)) radarReply(422,['ok'=>false,'message'=>'Servidor SMTP no válido.']);
            if (!ctype_digit($port)||(int)$port<1||(int)$port>65535) radarReply(422,['ok'=>false,'message'=>'Puerto SMTP no válido.']);
            if (!in_array($enc,['tls','ssl','none'],true)) radarReply(422,['ok'=>false,'message'=>'Tipo de cifrado no válido.']);
            if (!filter_var($email,FILTER_VALIDATE_EMAIL)) radarReply(422,['ok'=>false,'message'=>'Introduce un correo remitente válido.']);
            if ($pass==='') $pass=(string)($cfg['password']??'');
            if ($user!=='' && $pass==='') radarReply(422,['ok'=>false,'message'=>'Introduce la contraseña SMTP o deja la ya guardada.']);
            $new=['host'=>$host,'port'=>(int)$port,'encryption'=>$enc,'user'=>$user,'password'=>$pass,'email'=>$email,'name'=>$name?:'El Radar'];
            $contents="<?php\nif (!defined('RADAR_SMTP_CONFIG_BOOTSTRAPPED')) { http_response_code(404); exit; }\nreturn ".var_export($new,true).";\n";
            $tmp=$file.'.tmp'; if (@file_put_contents($tmp,$contents,LOCK_EX)===false || !@rename($tmp,$file)) { @unlink($tmp); radarReply(500,['ok'=>false,'message'=>'No se pudo guardar. Comprueba los permisos de escritura de la carpeta.']); }
            radarReply(200,['ok'=>true,'message'=>'Configuración SMTP guardada correctamente.']);
        }
        if ($action === 'admin_smtp_test') {
            $to=trim((string)($d['email']??($cfg['email']??'')));
            if (!filter_var($to,FILTER_VALIDATE_EMAIL)) radarReply(422,['ok'=>false,'message'=>'Introduce un correo válido para la prueba.']);
            if (!radarSmtpSend($cfg,$to,'Prueba de correo - El Radar','La configuración SMTP de El Radar funciona correctamente.')) radarReply(502,['ok'=>false,'message'=>'No se pudo enviar el correo de prueba. Revisa servidor, puerto, cifrado, usuario y contraseña SMTP.']);
            radarReply(200,['ok'=>true,'message'=>'Correo de prueba enviado correctamente.']);
        }
    }

    // Herramientas de base de datos: acceso exclusivo a administradores.
    if (in_array($action, ['admin_db_export', 'admin_db_import', 'admin_db_clear', 'admin_db_connection_info', 'admin_db_connection_save'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para gestionar la base de datos.']);
        $dbAdminCheck = $pdo->prepare('SELECT ID, TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $dbAdminCheck->execute([(int)$_SESSION['user_id']]);
        $dbAdmin = $dbAdminCheck->fetch();
        if (!$dbAdmin || strtolower(trim((string)$dbAdmin['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'Solo un administrador puede gestionar la base de datos.']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') radarReply(405, ['ok'=>false, 'message'=>'Método no permitido.']);

        if ($action === 'admin_db_connection_info') {
            radarReply(200, ['ok'=>true, 'connection'=>['host'=>$dbHost, 'port'=>$dbPort, 'database'=>$dbName, 'user'=>$dbUser]]);
        }
        if ($action === 'admin_db_connection_save') {
            $requestData = radarBody();
            $newHost = trim((string)($requestData['host'] ?? ''));
            $newPort = trim((string)($requestData['port'] ?? '3306'));
            $newName = trim((string)($requestData['database'] ?? ''));
            $newUser = trim((string)($requestData['user'] ?? ''));
            $newPass = (string)($requestData['password'] ?? '');
            if ($newPass === '') $newPass = $dbPass;
            if ($newHost === '' || strlen($newHost) > 255 || !preg_match('/^[a-zA-Z0-9._:-]+$/', $newHost)) radarReply(422, ['ok'=>false, 'message'=>'El servidor no es válido.']);
            if (!ctype_digit($newPort) || (int)$newPort < 1 || (int)$newPort > 65535) radarReply(422, ['ok'=>false, 'message'=>'El puerto debe estar entre 1 y 65535.']);
            if ($newName === '' || strlen($newName) > 128 || !preg_match('/^[a-zA-Z0-9_$-]+$/', $newName)) radarReply(422, ['ok'=>false, 'message'=>'El nombre de la base de datos no es válido.']);
            if ($newUser === '' || strlen($newUser) > 128) radarReply(422, ['ok'=>false, 'message'=>'El usuario de la base de datos es obligatorio.']);
            // Obtiene el usuario administrador en la conexión actual antes de cambiarla.
            $currentAdminQuery = $pdo->prepare("SELECT USUARIO FROM USUARIOS WHERE ID=? AND LOWER(TRIM(TIPO))='admin' LIMIT 1");
            $currentAdminQuery->execute([(int)$_SESSION['user_id']]);
            $currentAdminUsername = (string)($currentAdminQuery->fetchColumn() ?: '');
            if ($currentAdminUsername === '') {
                radarReply(403, ['ok'=>false, 'message'=>'No se pudo verificar tu cuenta administradora en la conexión actual. Vuelve a iniciar sesión como administrador.']);
            }

            try {
                // Primero intenta abrir la base directamente: así funciona también en InfinityFree,
                // donde normalmente no se concede el permiso global CREATE DATABASE.
                $targetDsn = "mysql:host={$newHost};port={$newPort};dbname={$newName};charset=utf8mb4";
                try {
                    $testPdo = new PDO(
                        $targetDsn,
                        $newUser,
                        $newPass,
                        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>8, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
                    );
                } catch (Throwable $missingDatabaseError) {
                    // Si todavía no existe, intenta crearla desde el servidor. Si el proveedor lo
                    // prohíbe, el mensaje posterior indicará dónde crearla manualmente.
                    $serverPdo = new PDO(
                        "mysql:host={$newHost};port={$newPort};charset=utf8mb4",
                        $newUser,
                        $newPass,
                        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>8, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
                    );
                    $quotedDatabase = '`' . str_replace('`', '``', $newName) . '`';
                    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS {$quotedDatabase} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                    $serverPdo = null;
                    $testPdo = new PDO(
                        $targetDsn,
                        $newUser,
                        $newPass,
                        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT=>8, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]
                    );
                }
                $testPdo->exec('SET FOREIGN_KEY_CHECKS=0');
                $targetTables = $testPdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);

                // Si la base está recién creada/vacía, copia automáticamente la estructura y los datos
                // de la base activa. Así el administrador y el resto de la aplicación siguen existiendo.
                if (!$targetTables) {
                    $sourceTables = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
                    foreach ($sourceTables as $tableRow) {
                        $tableName = (string)$tableRow[0];
                        $quotedTable = '`' . str_replace('`', '``', $tableName) . '`';
                        $createRow = $pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
                        if (!$createRow || empty($createRow[1])) continue;
                        $testPdo->exec($createRow[1]);

                        $columnRows = $pdo->query('SHOW COLUMNS FROM ' . $quotedTable)->fetchAll(PDO::FETCH_ASSOC);
                        $columnNames = array_map(static function($column) { return (string)$column['Field']; }, $columnRows);
                        if (!$columnNames) continue;
                        $quotedColumns = implode(',', array_map(static function($column) { return '`' . str_replace('`', '``', $column) . '`'; }, $columnNames));
                        $placeholders = implode(',', array_fill(0, count($columnNames), '?'));
                        $insert = $testPdo->prepare('INSERT INTO ' . $quotedTable . ' (' . $quotedColumns . ') VALUES (' . $placeholders . ')');
                        $offset = 0;
                        do {
                            $rows = $pdo->query('SELECT * FROM ' . $quotedTable . ' LIMIT 500 OFFSET ' . (int)$offset)->fetchAll(PDO::FETCH_NUM);
                            foreach ($rows as $row) $insert->execute($row);
                            $count = count($rows);
                            $offset += $count;
                        } while ($count === 500);
                    }
                    $testPdo->exec('SET FOREIGN_KEY_CHECKS=1');
                }

                $targetAdmin = $testPdo->prepare("SELECT ID, TIPO FROM USUARIOS WHERE USUARIO=? AND LOWER(TRIM(TIPO))='admin' LIMIT 1");
                $targetAdmin->execute([$currentAdminUsername]);
                $targetAdminRow = $targetAdmin->fetch();
                if (!$targetAdminRow) {
                    radarReply(422, ['ok'=>false, 'message'=>'La base de datos se creó, pero no se encontró tu cuenta Administrador después de inicializarla. Comprueba que la base anterior contiene esa cuenta.']);
                }
                // Asegura que la tabla de sesiones exista en bases antiguas.
                $sessionColumn = $testPdo->query("SHOW COLUMNS FROM USUARIOS LIKE 'TOKEN_SESION'")->fetch();
                if (!$sessionColumn) {
                    $testPdo->exec("ALTER TABLE USUARIOS ADD COLUMN TOKEN_SESION VARCHAR(64) NULL DEFAULT NULL");
                }
                $newSessionToken = bin2hex(random_bytes(32));
                $saveTargetSession = $testPdo->prepare('UPDATE USUARIOS SET TOKEN_SESION=? WHERE ID=?');
                $saveTargetSession->execute([hash('sha256', $newSessionToken), (int)$targetAdminRow['ID']]);
                $testPdo = null;
            } catch (Throwable $connectionError) {
                error_log('El Radar DB connection/create test failed: ' . $connectionError->getMessage());
                $detail = strtolower($connectionError->getMessage());
                if (strpos($detail, 'create command denied') !== false || strpos($detail, 'access denied') !== false || strpos($detail, '1044') !== false || strpos($detail, '1045') !== false) {
                    radarReply(422, ['ok'=>false, 'message'=>'El servidor rechazó la creación automática de la base de datos. Tu proveedor debe permitir CREATE DATABASE a este usuario MySQL. En InfinityFree, crea primero la base desde Control Panel → MySQL Databases y luego vuelve a guardar los datos.']);
                }
                radarReply(422, ['ok'=>false, 'message'=>'No se pudo crear o inicializar la base de datos automáticamente. Verifica servidor, puerto, permisos CREATE/ALTER, usuario y contraseña. Detalle: ' . $connectionError->getMessage()]);
            }
            $configContents = "<?php\nif (!defined('RADAR_DB_CONFIG_BOOTSTRAPPED')) { http_response_code(404); exit; }\nreturn " . var_export(['host'=>$newHost, 'port'=>$newPort, 'name'=>$newName, 'user'=>$newUser, 'pass'=>$newPass], true) . ";\n";
            $configPath = __DIR__ . '/.radar_db_config.php';
            $tempPath = $configPath . '.tmp';
            $written = @file_put_contents($tempPath, $configContents, LOCK_EX);
            $saved = ($written !== false && @rename($tempPath, $configPath));
            // Algunos alojamientos compartidos restringen rename() sobre archivos ocultos.
            // En ese caso se intenta escribir el archivo final directamente.
            if (!$saved) {
                @unlink($tempPath);
                $saved = (@file_put_contents($configPath, $configContents, LOCK_EX) !== false);
            }
            if (!$saved) {
                radarReply(500, ['ok'=>false, 'message'=>'La conexión es válida, pero el hosting no permitió guardar .radar_db_config.php. Revisa los permisos de escritura de la carpeta de index.php (755/775) y que el espacio de hosting no esté agotado.']);
            }
            $_SESSION['user_id'] = (int)$targetAdminRow['ID'];
            $_SESSION['session_token'] = $newSessionToken;
            radarReply(200, ['ok'=>true, 'message'=>'Conexión probada y guardada correctamente. Tu sesión de Administrador se conservó y la nueva conexión se utilizará desde la próxima solicitud.']);
        }

        $tableRows = $pdo->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        $dbTables = array_values(array_map(static fn($r) => (string)$r[0], $tableRows));
        sort($dbTables, SORT_STRING);

        if ($action === 'admin_db_export') {
            $backup = ['format'=>'el-radar-db-backup', 'version'=>1, 'created_at'=>date('c'), 'database'=>$dbName, 'tables'=>[]];
            foreach ($dbTables as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $create = $pdo->query('SHOW CREATE TABLE ' . $quoted)->fetch(PDO::FETCH_NUM);
                $rows = $pdo->query('SELECT * FROM ' . $quoted)->fetchAll(PDO::FETCH_ASSOC);
                $backup['tables'][$table] = ['create'=>$create[1] ?? '', 'rows'=>$rows];
            }
            radarReply(200, ['ok'=>true, 'backup'=>$backup, 'filename'=>'el-radar-respaldo-' . date('Y-m-d_H-i-s') . '.json', 'table_count'=>count($dbTables)]);
        }

        $requestData = radarBody();
        if ($action === 'admin_db_clear') {
            if (($requestData['confirm'] ?? '') !== 'VACIAR BASE DE DATOS') radarReply(422, ['ok'=>false, 'message'=>'Confirmación no válida.']);
            $currentAdminId = (int)$_SESSION['user_id'];
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
                foreach ($dbTables as $table) {
                    $quoted = '`' . str_replace('`', '``', $table) . '`';
                    if (strcasecmp($table, 'USUARIOS') === 0) {
                        $stmt = $pdo->prepare('DELETE FROM ' . $quoted . ' WHERE ID <> ?');
                        $stmt->execute([$currentAdminId]);
                    } else {
                        $pdo->exec('DELETE FROM ' . $quoted);
                    }
                    try { $pdo->exec('ALTER TABLE ' . $quoted . ' AUTO_INCREMENT=1'); } catch (Throwable $ignored) {}
                }
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
                radarReply(200, ['ok'=>true, 'message'=>'Se vaciaron los datos de las tablas. Se conservó únicamente tu cuenta administradora para mantener el acceso a El Radar.', 'table_count'=>count($dbTables)]);
            } catch (Throwable $e) {
                try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
                error_log('El Radar database clear error: ' . $e->getMessage());
                radarReply(500, ['ok'=>false, 'message'=>'No se pudo vaciar la base de datos. Comprueba los permisos MySQL.']);
            }
        }

        $backup = $requestData['backup'] ?? null;
        if (!is_array($backup) || ($backup['format'] ?? '') !== 'el-radar-db-backup' || (int)($backup['version'] ?? 0) !== 1 || !is_array($backup['tables'] ?? null)) {
            radarReply(422, ['ok'=>false, 'message'=>'El archivo no es un respaldo válido de El Radar.']);
        }
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
            $pdo->beginTransaction();
            // Vacía todas las tablas existentes y después restaura solo tablas reconocidas.
            foreach ($dbTables as $table) {
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                $pdo->exec('DELETE FROM ' . $quoted);
            }
            foreach ($backup['tables'] as $table => $tableData) {
                if (!is_string($table) || !in_array($table, $dbTables, true) || !is_array($tableData) || !is_array($tableData['rows'] ?? null)) continue;
                $quoted = '`' . str_replace('`', '``', $table) . '`';
                foreach ($tableData['rows'] as $row) {
                    if (!is_array($row) || !$row) continue;
                    $columns = array_keys($row);
                    foreach ($columns as $column) if (!is_string($column) || !preg_match('/^[A-Za-z0-9_$]+$/', $column)) throw new RuntimeException('Columna no válida en el respaldo.');
                    $quotedColumns = array_map(static fn($c) => '`' . str_replace('`', '``', $c) . '`', $columns);
                    $marks = implode(',', array_fill(0, count($columns), '?'));
                    $sql = 'INSERT INTO ' . $quoted . ' (' . implode(',', $quotedColumns) . ') VALUES (' . $marks . ')';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute(array_values($row));
                }
            }
            $pdo->commit();
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            radarReply(200, ['ok'=>true, 'message'=>'La base de datos se restauró correctamente desde el respaldo.']);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
            error_log('El Radar database import error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo importar el respaldo. Debe corresponder a la estructura actual de El Radar. No se completó la restauración.']);
        }
    }

    // Sincronización ligera: lee exclusivamente la cuenta activa y no vuelve a migrar el esquema.
    if ($action === 'sync') {
        if (!empty($_SESSION['user_id'])) radarApplyDailyCharge($pdo, (int)$_SESSION['user_id']);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') radarReply(405, ['ok'=>false, 'message'=>'Método no permitido.']);
        if (empty($_SESSION['user_id'])) radarReply(200, ['ok'=>true, 'user'=>null, 'sessionExpired'=>true]);
        $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $u = $q->fetch();
        if ($u && strtolower(trim((string)$u['TIPO'])) !== 'admin' && !empty($u['PRUEBA_FIN']) && strtotime((string)$u['PRUEBA_FIN']) <= time() && (empty($u['PAGO_HASTA']) || strtotime((string)$u['PAGO_HASTA']) <= time())) {
            if (strtolower(trim((string)$u['ESTADO'])) !== 'inactivo') {
                $pdo->prepare("UPDATE USUARIOS SET ESTADO='inactivo' WHERE ID=?")->execute([(int)$u['ID']]);
                radarNotify($pdo, (int)$u['ID'], 'Periodo de prueba vencido', 'Tu periodo de prueba ha terminado. Tu cuenta está inactiva; recarga la cuenta para continuar usando El Radar.', 'warning');
            }
            $u['ESTADO'] = 'inactivo';
        }
        if (!$u) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $cookie = session_get_cookie_params();
                setcookie(session_name(), '', time()-42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']);
            }
            session_destroy();
            radarReply(200, ['ok'=>true, 'user'=>null, 'sessionExpired'=>true]);
        }
        // La sesión existente se conserva cuando el usuario se marca como inactivo,
        // para permitir volver a activar el estado desde la ficha. El GPS se detiene en el cliente.
        radarReply(200, ['ok'=>true, 'user'=>radarUserData($u), 'syncedAt'=>gmdate('c')]);
    }

    // Comprueba que PHP se ejecuta y que la conexión con MySQL está disponible.
    if ($action === 'health') {
        try {
            $pdo->query('SELECT 1');
            radarReply(200, ['ok'=>true, 'message'=>'Conexión con el servidor y la base de datos establecida.']);
        } catch (Exception $e) {
            error_log('El Radar health check error: ' . $e->getMessage());
            radarReply(503, ['ok'=>false, 'message'=>'El servidor responde, pero no se pudo comprobar la base de datos.']);
        }
    }
    if ($action === 'me') {
        if (!empty($_SESSION['user_id'])) radarApplyDailyCharge($pdo, (int)$_SESSION['user_id']);
        if (empty($_SESSION['user_id'])) radarReply(200, ['ok'=>true, 'user'=>null]);
        $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $u = $q->fetch();
        if ($u && strtolower(trim((string)$u['TIPO'])) !== 'admin' && !empty($u['PRUEBA_FIN']) && strtotime((string)$u['PRUEBA_FIN']) <= time() && (empty($u['PAGO_HASTA']) || strtotime((string)$u['PAGO_HASTA']) <= time())) {
            if (strtolower(trim((string)$u['ESTADO'])) !== 'inactivo') {
                $pdo->prepare("UPDATE USUARIOS SET ESTADO='inactivo' WHERE ID=?")->execute([(int)$u['ID']]);
                radarNotify($pdo, (int)$u['ID'], 'Periodo de prueba vencido', 'Tu periodo de prueba ha terminado. Tu cuenta está inactiva; recarga la cuenta para continuar usando El Radar.', 'warning');
            }
            $u['ESTADO'] = 'inactivo';
        }
        if (!$u) {
            $_SESSION = [];
            session_destroy();
            radarReply(200, ['ok'=>true, 'user'=>null]);
        }
        radarReply(200, ['ok'=>true, 'user'=>radarUserData($u)]);
    }

    if ($action === 'balance_history') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar tus operaciones.']);
        $userId = (int)$_SESSION['user_id'];
        // Aplicar primero cualquier descuento diario pendiente para que el historial y saldo estén al día.
        radarApplyDailyCharge($pdo, $userId);
        $q = $pdo->prepare('SELECT ID, TIPO AS tipo, DESCRIPCION AS descripcion, IMPORTE AS importe, SALDO_ANTERIOR AS saldo_anterior, SALDO_NUEVO AS saldo_nuevo, REFERENCIA AS referencia, CREADO_EN AS creado_en FROM RADAR_MOVIMIENTOS_SALDO WHERE USUARIO_ID=? ORDER BY CREADO_EN DESC, ID DESC LIMIT 500');
        $q->execute([$userId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $balanceQuery = $pdo->prepare('SELECT SALDO FROM USUARIOS WHERE ID=? LIMIT 1');
        $balanceQuery->execute([$userId]);
        radarReply(200, ['ok'=>true, 'saldo_actual'=>(float)$balanceQuery->fetchColumn(), 'operaciones'=>$rows]);
    }

    if ($action === 'transfer_balance') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para transferir saldo.']);
        $data = radarBody();
        $recipientUsername = trim((string)($data['usuario_destino'] ?? ''));
        $amountRaw = str_replace(',', '.', trim((string)($data['importe'] ?? '')));
        if ($recipientUsername === '' || mb_strlen($recipientUsername, 'UTF-8') > 100) radarReply(422, ['ok'=>false, 'message'=>'Introduce el nombre de usuario del destinatario.']);
        if (!is_numeric($amountRaw)) radarReply(422, ['ok'=>false, 'message'=>'Introduce un importe válido.']);
        $amount = round((float)$amountRaw, 2);
        if (!is_finite($amount) || $amount <= 0) radarReply(422, ['ok'=>false, 'message'=>'El importe debe ser mayor que cero.']);
        try {
            $pdo->beginTransaction();
            $senderId = (int)$_SESSION['user_id'];
            $recipientLookup = $pdo->prepare('SELECT ID, USUARIO, TIPO FROM USUARIOS WHERE LOWER(USUARIO)=LOWER(?) LIMIT 1');
            $recipientLookup->execute([$recipientUsername]);
            $recipient = $recipientLookup->fetch();
            if (!$recipient) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'No se encontró un usuario con ese nombre.']);
            }
            $recipientId = (int)$recipient['ID'];
            if ($recipientId === $senderId) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'No puedes transferir saldo a tu propia cuenta.']);
            }
            $recipientType = mb_strtolower(trim((string)$recipient['TIPO']), 'UTF-8');
            if (!in_array($recipientType, ['cliente', 'transportista'], true)) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'Solo puedes transferir saldo a usuarios de tipo Cliente o Transportista.']);
            }
            // Bloquea ambas cuentas en orden de ID para evitar transferencias simultáneas inconsistentes.
            $ids = [$senderId, $recipientId]; sort($ids, SORT_NUMERIC);
            $lock = $pdo->prepare('SELECT ID, USUARIO, TIPO, SALDO FROM USUARIOS WHERE ID IN (?, ?) ORDER BY ID FOR UPDATE');
            $lock->execute([$ids[0], $ids[1]]);
            $lockedUsers = [];
            foreach ($lock->fetchAll() as $row) $lockedUsers[(int)$row['ID']] = $row;
            if (!isset($lockedUsers[$senderId]) || !isset($lockedUsers[$recipientId])) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'No se pudieron localizar ambas cuentas.']);
            }
            $sender = $lockedUsers[$senderId];
            $recipient = $lockedUsers[$recipientId];
            if (mb_strtolower(trim((string)$sender['TIPO']), 'UTF-8') === 'admin') {
                $pdo->rollBack();
                radarReply(403, ['ok'=>false, 'message'=>'Las cuentas de administración no pueden transferir saldo desde esta sección.']);
            }
            $senderBalance = round((float)$sender['SALDO'], 2);
            if ($senderBalance + 0.00001 < $amount) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'No tienes saldo suficiente para realizar esta transferencia.']);
            }
            $debit = $pdo->prepare('UPDATE USUARIOS SET SALDO = SALDO - ? WHERE ID = ? AND SALDO >= ?');
            $debit->execute([number_format($amount, 2, '.', ''), $senderId, number_format($amount, 2, '.', '')]);
            if ($debit->rowCount() !== 1) {
                $pdo->rollBack();
                radarReply(409, ['ok'=>false, 'message'=>'El saldo cambió durante la operación. Actualiza la página e inténtalo de nuevo.']);
            }
            $credit = $pdo->prepare('UPDATE USUARIOS SET SALDO = SALDO + ? WHERE ID = ?');
            $credit->execute([number_format($amount, 2, '.', ''), $recipientId]);
            if ($credit->rowCount() !== 1) throw new RuntimeException('No se pudo acreditar el saldo al destinatario.');
            $recipientBefore = round((float)$recipient['SALDO'], 2);
            $senderAfter = round($senderBalance - $amount, 2);
            $recipientAfter = round($recipientBefore + $amount, 2);
            $movement = $pdo->prepare('INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $movement->execute([$senderId, 'transferencia_enviada', 'Transferencia enviada a ' . (string)$recipient['USUARIO'], number_format(-$amount, 2, '.', ''), number_format($senderBalance, 2, '.', ''), number_format($senderAfter, 2, '.', ''), (string)$recipient['USUARIO']]);
            $movement->execute([$recipientId, 'transferencia_recibida', 'Transferencia recibida de ' . (string)$sender['USUARIO'], number_format($amount, 2, '.', ''), number_format($recipientBefore, 2, '.', ''), number_format($recipientAfter, 2, '.', ''), (string)$sender['USUARIO']]);
            $pdo->commit();
            radarNotify($pdo, $recipientId, 'Saldo recibido', 'Has recibido una transferencia de saldo de ' . (string)$sender['USUARIO'] . '. Importe: ' . number_format($amount, 2, '.', ''), 'success');
            $remaining = round($senderBalance - $amount, 2);
            radarReply(200, ['ok'=>true, 'message'=>'Saldo transferido correctamente.', 'importe'=>$amount, 'saldo_restante'=>$remaining, 'destinatario'=>(string)$recipient['USUARIO']]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar balance transfer error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo completar la transferencia de saldo. Inténtalo de nuevo.']);
        }
    }

    if ($action === 'confirm_transfer_submission') {
        if (!empty($_SESSION['user_id'])) radarApplyDailyCharge($pdo, (int)$_SESSION['user_id']);
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para confirmar la transferencia.']);
        $data = radarBody();
        $transferId = trim((string)($data['id_transferencia'] ?? ''));
        if ($transferId === '' || mb_strlen($transferId, 'UTF-8') > 150) radarReply(422, ['ok'=>false, 'message'=>'Introduce un ID de transferencia válido.']);

        $userQuery = $pdo->prepare('SELECT ID, USUARIO, TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $userQuery->execute([(int)$_SESSION['user_id']]);
        $loggedUser = $userQuery->fetch();
        if (!$loggedUser) radarReply(401, ['ok'=>false, 'message'=>'La cuenta no existe o la sesión terminó.']);
        $accountType = mb_strtolower(trim((string)$loggedUser['TIPO']), 'UTF-8');
        if ($accountType === 'admin') radarReply(403, ['ok'=>false, 'message'=>'Las cuentas de administración no necesitan recarga.']);

        try {
            $pdo->beginTransaction();
            // Bloquea una transferencia sin usuario para impedir que dos cuentas la cobren simultáneamente.
            $transferQuery = $pdo->prepare("SELECT ID, IMPORTE FROM Confirmacion WHERE TRIM(ID_TRANSFERENCIA) = ? AND (USUARIO IS NULL OR TRIM(USUARIO) = '') ORDER BY ID ASC LIMIT 1 FOR UPDATE");
            $transferQuery->execute([$transferId]);
            $transfer = $transferQuery->fetch();
            if (!$transfer) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'No existe una transferencia pendiente con ese ID o ya fue asignada a otro usuario.']);
            }

            $amount = (float)$transfer['IMPORTE'];
            if (!is_finite($amount) || $amount <= 0) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'El importe registrado para esta transferencia no es válido.']);
            }

            // Días nuevos = importe transferido / tarifa correspondiente al tipo de cuenta.
            $tariffQuery = $pdo->query('SELECT CLIENTE_IMPORTE, TRANSPORTISTA_IMPORTE FROM RADAR_TARIFAS_USO WHERE ID=1');
            $tariffs = $tariffQuery->fetch();
            if (!$tariffs) {
                $pdo->rollBack();
                radarReply(500, ['ok'=>false, 'message'=>'No se encontró la configuración de tarifas de uso.']);
            }
            if ($accountType === 'transportista') {
                $rate = (float)$tariffs['TRANSPORTISTA_IMPORTE'];
            } else {
                $rate = (float)$tariffs['CLIENTE_IMPORTE'];
            }
            if (!is_finite($rate) || $rate <= 0) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'La tarifa configurada para tu tipo de cuenta no es válida. Solicita al administrador que revise las tarifas.']);
            }
            // Buscar ofertas usando comparación decimal explícita y tipo normalizado.
            // Esto evita que espacios/mayúsculas en datos antiguos impidan aplicar una oferta válida.
            // Se elige la mayor bonificación monetaria para esta recarga; si empatan, se prioriza
            // la oferta específica del tipo de cuenta y después el mínimo más alto.
            $offerQuery = $pdo->prepare("SELECT ID, IMPORTE_RECARGA, PORCENTAJE, TIPO_USUARIO
                FROM RADAR_OFERTAS_RECARGA
                WHERE ACTIVO = 1
                  AND CAST(IMPORTE_RECARGA AS DECIMAL(12,2)) <= CAST(? AS DECIMAL(12,2))
                  AND LOWER(TRIM(TIPO_USUARIO)) IN (?, 'todos')
                  AND (FECHA_INICIO IS NULL OR FECHA_INICIO <= NOW())
                  AND (FECHA_FIN IS NULL OR FECHA_FIN >= NOW())
                ORDER BY (CAST(PORCENTAJE AS DECIMAL(7,2)) * CAST(? AS DECIMAL(12,2))) DESC,
                         CASE WHEN LOWER(TRIM(TIPO_USUARIO)) = ? THEN 0 ELSE 1 END,
                         CAST(IMPORTE_RECARGA AS DECIMAL(12,2)) DESC, ID DESC");
            $amountSql = number_format($amount, 2, '.', '');
            $offerQuery->execute([$amountSql, $accountType, $amountSql, $accountType]);
            $eligibleOffers = $offerQuery->fetchAll(PDO::FETCH_ASSOC);
            $rechargeOffer = $eligibleOffers[0] ?? null;
            $bonusAmount = $rechargeOffer ? round($amount * ((float)$rechargeOffer['PORCENTAJE'] / 100), 2) : 0.00;
            $totalCredit = round($amount + $bonusAmount, 2);
            // El saldo promocional también se convierte en tiempo disponible según la tarifa vigente.
            $daysGranted = (int)floor(($totalCredit / $rate) + 0.0000001);
            if ($daysGranted < 1) {
                $pdo->rollBack();
                radarReply(422, ['ok'=>false, 'message'=>'El importe de la transferencia no alcanza para conceder un día completo según la tarifa de tu tipo de cuenta.']);
            }

            $currentQuery = $pdo->prepare('SELECT SALDO, TIEMPO_DISPONIBLE, PAGO_HASTA FROM USUARIOS WHERE ID=? FOR UPDATE');
            $currentQuery->execute([(int)$loggedUser['ID']]);
            $currentUser = $currentQuery->fetch();
            if (!$currentUser) {
                $pdo->rollBack();
                radarReply(401, ['ok'=>false, 'message'=>'No se encontró tu cuenta.']);
            }
            $now = new DateTimeImmutable('now');
            $currentExpiry = !empty($currentUser['PAGO_HASTA']) ? strtotime((string)$currentUser['PAGO_HASTA']) : false;
            $baseDate = ($currentExpiry !== false && $currentExpiry > time()) ? (new DateTimeImmutable((string)$currentUser['PAGO_HASTA'])) : $now;
            $newExpiry = $baseDate->modify('+' . $daysGranted . ' days')->format('Y-m-d H:i:s');

            $assign = $pdo->prepare("UPDATE Confirmacion SET FECHA_CONFIRMACION = NOW(), USUARIO = ? WHERE ID = ? AND (USUARIO IS NULL OR TRIM(USUARIO) = '')");
            $assign->execute([(string)$loggedUser['USUARIO'], (int)$transfer['ID']]);
            if ($assign->rowCount() !== 1) {
                $pdo->rollBack();
                radarReply(409, ['ok'=>false, 'message'=>'La transferencia acaba de ser asignada. Vuelve a intentarlo con otro ID.']);
            }

            $newAvailableDays = max(0, (int)$currentUser['TIEMPO_DISPONIBLE']) + $daysGranted;
            $credit = $pdo->prepare("UPDATE USUARIOS SET SALDO = SALDO + ?, TIEMPO_DISPONIBLE = ?, PAGO_HASTA = ?, ESTADO = 'activo', SALDO_ULTIMO_COBRO = COALESCE(SALDO_ULTIMO_COBRO, NOW()) WHERE ID = ?");
            $credit->execute([number_format($totalCredit, 2, '.', ''), $newAvailableDays, $newExpiry, (int)$loggedUser['ID']]);
            if ($credit->rowCount() !== 1) {
                throw new RuntimeException('No se pudo actualizar el saldo y los días disponibles del usuario.');
            }
            $balanceBefore = round((float)$currentUser['SALDO'], 2);
            $balanceAfter = round($balanceBefore + $totalCredit, 2);
            $editorPercentSnapshot = (float)$pdo->query('SELECT PORCENTAJE_EDITOR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
            $movement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA, PORCENTAJE_EDITOR_APLICADO) VALUES (?, 'recarga', ?, ?, ?, ?, ?, ?)");
            $balanceAfterRecharge = round($balanceBefore + $amount, 2);
            $movement->execute([(int)$loggedUser['ID'], 'Recarga de cuenta confirmada', number_format($amount, 2, '.', ''), number_format($balanceBefore, 2, '.', ''), number_format($balanceAfterRecharge, 2, '.', ''), $transferId, number_format($editorPercentSnapshot, 2, '.', '')]);
            if ($bonusAmount > 0) {
                $bonusBefore = $balanceAfterRecharge;
                $bonusMovement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA) VALUES (?, 'bono_recarga', ?, ?, ?, ?, ?)");
                $bonusMovement->execute([(int)$loggedUser['ID'], 'Bono promocional por recarga (' . number_format((float)$rechargeOffer['PORCENTAJE'],2,'.','') . '%)', number_format($bonusAmount,2,'.',''), number_format($bonusBefore,2,'.',''), number_format($balanceAfter,2,'.',''), $transferId]);
            }
            $pdo->commit();
            if ($bonusAmount > 0) {
                $offerMessage = 'Tu recarga de ' . number_format($amount,2,',','.') . ' CUP recibió un bono del ' . number_format((float)$rechargeOffer['PORCENTAJE'],2,',','.') . '%: +' . number_format($bonusAmount,2,',','.') . ' CUP. Total acreditado: ' . number_format($totalCredit,2,',','.') . ' CUP.';
                radarNotify($pdo, (int)$loggedUser['ID'], '🎉 ¡Bono por recarga!', $offerMessage, 'success', 'recarga_oferta', (int)$rechargeOffer['ID']);
            }
            radarReply(200, [
                'ok'=>true,
                'message'=>$bonusAmount > 0 ? 'Transferencia confirmada. ¡Has recibido un bono adicional!' : 'Transferencia confirmada correctamente.',
                'importe'=>$amount,
                'bono_recarga'=>$bonusAmount,
                'porcentaje_bono'=>$rechargeOffer ? (float)$rechargeOffer['PORCENTAJE'] : 0,
                'total_acreditado'=>$totalCredit,
                'saldo_anterior'=>$balanceBefore,
                'saldo_actual'=>$balanceAfter,
                'dias_otorgados'=>$daysGranted,
                'dias_disponibles'=>$newAvailableDays,
                'fecha_vencimiento'=>$newExpiry,
                'tarifa_aplicada'=>$rate
            ]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar transfer confirmation error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo confirmar la transferencia ni actualizar el saldo. Inténtalo de nuevo.']);
        }
    }

    if ($action === 'transport_request_status') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar tu solicitud.']);
        $q = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $account = $q->fetch();
        if (!$account) radarReply(401, ['ok'=>false, 'message'=>'La cuenta no existe o la sesión terminó.']);
        $q = $pdo->prepare('SELECT ESTADO_SOLICITUD, NOTA_ADMIN, CREADA_EN FROM SOLICITUDES_TRANSPORTISTA WHERE USUARIO_ID=? ORDER BY ID DESC LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $request = $q->fetch();
        radarReply(200, [
            'ok'=>true,
            'tipo'=>strtolower(trim((string)$account['TIPO'])),
            'estado_solicitud'=>$request ? strtolower(trim((string)$request['ESTADO_SOLICITUD'])) : null,
            'nota_admin'=>$request && isset($request['NOTA_ADMIN']) ? $request['NOTA_ADMIN'] : null,
            'creada_en'=>$request && isset($request['CREADA_EN']) ? $request['CREADA_EN'] : null
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !($action === 'chat_messages' && $_SERVER['REQUEST_METHOD'] === 'GET')) radarReply(405, ['ok'=>false, 'message'=>'Método no permitido.']);
    $data = radarBody();

    // API Android para Kodular: autenticación inicial y consulta por token revocable.
    if ($action === 'android_authenticate') {
        $username = trim((string)($data['usuario'] ?? ''));
        $password = (string)($data['contrasena'] ?? '');
        if ($username === '' || $password === '') radarReply(422, ['ok'=>false, 'message'=>'Introduce usuario y contraseña.']);
        $q = $pdo->prepare('SELECT ID, CONTRASENA, ESTADO, TIPO FROM USUARIOS WHERE USUARIO=? LIMIT 1');
        $q->execute([$username]);
        $account = $q->fetch();
        if (!$account || !password_verify($password, (string)$account['CONTRASENA'])) radarReply(401, ['ok'=>false, 'message'=>'Usuario o contraseña incorrectos.']);
        $plainToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $plainToken);
        $deviceName = mb_substr(trim((string)($data['dispositivo'] ?? 'Android Kodular')), 0, 120, 'UTF-8');
        $q = $pdo->prepare('INSERT INTO RADAR_ANDROID_DEVICES (USUARIO_ID, TOKEN_HASH, NOMBRE_DISPOSITIVO) VALUES (?, ?, ?)');
        $q->execute([(int)$account['ID'], $tokenHash, $deviceName]);
        $cursorQuery = $pdo->prepare('SELECT COALESCE(MAX(ID),0) FROM NOTIFICACIONES WHERE USUARIO_ID=?');
        $cursorQuery->execute([(int)$account['ID']]);
        $initialCursor = (int)$cursorQuery->fetchColumn();
        radarReply(200, ['ok'=>true, 'token'=>$plainToken, 'usuario_id'=>(int)$account['ID'], 'tipo'=>$account['TIPO'], 'estado'=>$account['ESTADO'], 'initial_after_id'=>$initialCursor, 'poll_interval_seconds'=>60]);
    }
    if ($action === 'android_poll') {
        $token = trim((string)($data['token'] ?? ''));
        $afterId = filter_var($data['after_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($token === '' || strlen($token) > 200 || $afterId === false || $afterId < 0) radarReply(422, ['ok'=>false, 'message'=>'Token o cursor inválido.']);
        $tokenHash = hash('sha256', $token);
        $q = $pdo->prepare('SELECT ID, USUARIO_ID FROM RADAR_ANDROID_DEVICES WHERE TOKEN_HASH=? AND REVOCADO=0 LIMIT 1');
        $q->execute([$tokenHash]);
        $device = $q->fetch();
        if (!$device) radarReply(401, ['ok'=>false, 'message'=>'Dispositivo no autorizado. Vuelve a iniciar sesión.']);
        $userId = (int)$device['USUARIO_ID'];
        $pdo->prepare('UPDATE RADAR_ANDROID_DEVICES SET ULTIMO_USO=NOW() WHERE ID=?')->execute([(int)$device['ID']]);
        $q = $pdo->prepare('SELECT ID, TITULO, MENSAJE, TIPO, CREADA_EN FROM NOTIFICACIONES WHERE USUARIO_ID=? AND ID>? ORDER BY ID ASC LIMIT 50');
        $q->execute([$userId, (int)$afterId]);
        $items = $q->fetchAll();
        $nextId = (int)$afterId;
        foreach ($items as $item) $nextId = max($nextId, (int)$item['ID']);
        radarReply(200, ['ok'=>true, 'notificaciones'=>$items, 'next_id'=>$nextId, 'count'=>count($items), 'poll_interval_seconds'=>60]);
    }
    if ($action === 'android_revoke') {
        $token = trim((string)($data['token'] ?? ''));
        if ($token === '') radarReply(422, ['ok'=>false, 'message'=>'Token requerido.']);
        $q = $pdo->prepare('UPDATE RADAR_ANDROID_DEVICES SET REVOCADO=1 WHERE TOKEN_HASH=?');
        $q->execute([hash('sha256', $token)]);
        radarReply(200, ['ok'=>true, 'message'=>'Dispositivo desconectado.']);
    }

    // Centro de notificaciones de la sesión actual; nunca acepta el ID de otro usuario.
    if ($action === 'push_subscribe') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para activar las notificaciones.']);
        $sub = $data['subscription'] ?? null;
        if (!is_array($sub) || empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
            radarReply(422, ['ok'=>false, 'message'=>'La suscripción push no es válida.']);
        }
        $endpoint = (string)$sub['endpoint'];
        if (strlen($endpoint) > 2000 || !filter_var($endpoint, FILTER_VALIDATE_URL)) radarReply(422, ['ok'=>false, 'message'=>'El endpoint push no es válido.']);
        $hash = hash('sha256', $endpoint);
        $stmt = $pdo->prepare('INSERT INTO NOTIFICACIONES_PUSH (USUARIO_ID, ENDPOINT_HASH, ENDPOINT, P256DH, AUTH_TOKEN) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE USUARIO_ID=VALUES(USUARIO_ID), ENDPOINT=VALUES(ENDPOINT), P256DH=VALUES(P256DH), AUTH_TOKEN=VALUES(AUTH_TOKEN)');
        $stmt->execute([(int)$_SESSION['user_id'], $hash, $endpoint, (string)$sub['keys']['p256dh'], (string)$sub['keys']['auth']]);
        radarReply(200, ['ok'=>true, 'message'=>'Dispositivo registrado para notificaciones push.']);
    }
    if ($action === 'notifications_list') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar tus notificaciones.']);
        $userId = (int)$_SESSION['user_id'];
        $stmt = $pdo->prepare("SELECT n.ID, n.TITULO, n.MENSAJE, n.TIPO, n.LEIDA, n.CREADA_EN, n.REFERENCIA_TIPO, n.REFERENCIA_ID, sv.ESTADO AS SOLICITUD_ESTADO, CASE WHEN n.USUARIO_ID = sv.CLIENTE_ID THEN ut.FOTO ELSE uc.FOTO END AS FOTO_CONTACTO, ut.NOMBRE AS TRANSPORTISTA_NOMBRE, ut.APELLIDOS AS TRANSPORTISTA_APELLIDOS, (SELECT st.FOTO_VEHICULO FROM SOLICITUDES_TRANSPORTISTA st WHERE st.USUARIO_ID=sv.TRANSPORTISTA_ID ORDER BY st.ID DESC LIMIT 1) AS FOTO_VEHICULO FROM NOTIFICACIONES n LEFT JOIN SOLICITUDES_VIAJE sv ON n.REFERENCIA_TIPO='solicitud_viaje' AND sv.ID=n.REFERENCIA_ID LEFT JOIN USUARIOS uc ON uc.ID=sv.CLIENTE_ID LEFT JOIN USUARIOS ut ON ut.ID=sv.TRANSPORTISTA_ID WHERE n.USUARIO_ID=? ORDER BY n.ID DESC LIMIT 100");
        $stmt->execute([$userId]);
        $items = $stmt->fetchAll();
        $count = $pdo->prepare('SELECT COUNT(*) FROM NOTIFICACIONES WHERE USUARIO_ID=? AND LEIDA=0');
        $count->execute([$userId]);
        $unreadCount = (int)$count->fetchColumn();
        $totalCount = $pdo->prepare('SELECT COUNT(*) FROM NOTIFICACIONES WHERE USUARIO_ID=?');
        $totalCount->execute([$userId]);
        radarReply(200, ['ok'=>true, 'notificaciones'=>$items, 'no_leidas'=>$unreadCount, 'total'=>(int)$totalCount->fetchColumn()]);
    }
    if ($action === 'notification_mark_read') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para actualizar tus notificaciones.']);
        $notificationId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$notificationId || $notificationId < 1) radarReply(422, ['ok'=>false, 'message'=>'La notificación seleccionada no es válida.']);
        $stmt = $pdo->prepare('UPDATE NOTIFICACIONES SET LEIDA=1 WHERE ID=? AND USUARIO_ID=?');
        $stmt->execute([$notificationId, (int)$_SESSION['user_id']]);
        $count = $pdo->prepare('SELECT COUNT(*) FROM NOTIFICACIONES WHERE USUARIO_ID=? AND LEIDA=0');
        $count->execute([(int)$_SESSION['user_id']]);
        radarReply(200, ['ok'=>true, 'no_leidas'=>(int)$count->fetchColumn()]);
    }
    if ($action === 'notifications_mark_all_read') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para actualizar tus notificaciones.']);
        $stmt = $pdo->prepare('UPDATE NOTIFICACIONES SET LEIDA=1 WHERE USUARIO_ID=? AND LEIDA=0');
        $stmt->execute([(int)$_SESSION['user_id']]);
        radarReply(200, ['ok'=>true, 'message'=>'Todas las notificaciones están marcadas como leídas.', 'no_leidas'=>0]);
    }

    // Catálogo de tipos de transporte. La lista activa se usa en el formulario de transportistas.
    if (in_array($action, ['transport_types_list', 'admin_transport_types_list', 'admin_transport_types_save', 'admin_transport_types_delete'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar los tipos de transporte.']);
        if ($action === 'transport_types_list') {
            $rows = $pdo->query('SELECT ID, NOMBRE, ACTIVO, ORDEN FROM RADAR_TIPOS_TRANSPORTE WHERE ACTIVO=1 ORDER BY ORDEN ASC, NOMBRE ASC')->fetchAll();
            radarReply(200, ['ok'=>true, 'tipos'=>$rows]);
        }
        $auth = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([(int)$_SESSION['user_id']]);
        $admin = $auth->fetch();
        if (!$admin || strtolower(trim((string)$admin['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para administrar los tipos de transporte.']);
        if ($action === 'admin_transport_types_list') {
            $rows = $pdo->query('SELECT ID, NOMBRE, ACTIVO, ORDEN FROM RADAR_TIPOS_TRANSPORTE ORDER BY ORDEN ASC, NOMBRE ASC')->fetchAll();
            radarReply(200, ['ok'=>true, 'tipos'=>$rows]);
        }

        if ($action === 'admin_transport_types_delete') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) radarReply(422, ['ok'=>false, 'message'=>'El tipo de transporte seleccionado no es válido.']);
            $q = $pdo->prepare('SELECT NOMBRE FROM RADAR_TIPOS_TRANSPORTE WHERE ID=? LIMIT 1');
            $q->execute([$id]);
            $existing = $q->fetch();
            if (!$existing) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el tipo de transporte.']);
            $used = $pdo->prepare('SELECT COUNT(*) FROM SOLICITUDES_TRANSPORTISTA WHERE TIPO_VEHICULO=?');
            $used->execute([(string)$existing['NOMBRE']]);
            if ((int)$used->fetchColumn() > 0) {
                $q = $pdo->prepare('UPDATE RADAR_TIPOS_TRANSPORTE SET ACTIVO=0 WHERE ID=?');
                $q->execute([$id]);
                radarReply(200, ['ok'=>true, 'message'=>'El tipo ya fue utilizado; se desactivó para conservar el historial.']);
            }
            $q = $pdo->prepare('DELETE FROM RADAR_TIPOS_TRANSPORTE WHERE ID=?');
            $q->execute([$id]);
            radarReply(200, ['ok'=>true, 'message'=>'Tipo de transporte eliminado correctamente.']);
        }

        $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
        $name = trim((string)($data['nombre'] ?? ''));
        $active = !empty($data['activo']) ? 1 : 0;
        $order = filter_var($data['orden'] ?? 0, FILTER_VALIDATE_INT);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 60) radarReply(422, ['ok'=>false, 'message'=>'Escribe un nombre de hasta 60 caracteres.']);
        if ($order === false || $order < 0 || $order > 9999) radarReply(422, ['ok'=>false, 'message'=>'El orden debe estar entre 0 y 9999.']);
        try {
            if ($id && $id > 0) {
                $q = $pdo->prepare('UPDATE RADAR_TIPOS_TRANSPORTE SET NOMBRE=?, ACTIVO=?, ORDEN=? WHERE ID=?');
                $q->execute([$name, $active, $order, $id]);
                if (!$q->rowCount()) {
                    $exists = $pdo->prepare('SELECT ID FROM RADAR_TIPOS_TRANSPORTE WHERE ID=?'); $exists->execute([$id]);
                    if (!$exists->fetch()) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el tipo de transporte.']);
                }
                radarReply(200, ['ok'=>true, 'message'=>'Tipo de transporte actualizado correctamente.']);
            }
            $q = $pdo->prepare('INSERT INTO RADAR_TIPOS_TRANSPORTE (NOMBRE, ACTIVO, ORDEN) VALUES (?, ?, ?)');
            $q->execute([$name, $active, $order]);
            radarReply(200, ['ok'=>true, 'message'=>'Tipo de transporte agregado correctamente.']);
        } catch (PDOException $e) {
            if ((string)$e->getCode() === '23000') radarReply(409, ['ok'=>false, 'message'=>'Ya existe un tipo de transporte con ese nombre.']);
            throw $e;
        }
    }

    // Administración de ofertas automáticas por recarga.
    if (in_array($action, ['admin_recharge_offers_list','admin_recharge_offers_save','admin_recharge_offers_delete'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para administrar las ofertas.']);
        $auth = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([(int)$_SESSION['user_id']]);
        if (strtolower(trim((string)$auth->fetchColumn())) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'Solo Administración puede gestionar las ofertas.']);
        if ($action === 'admin_recharge_offers_list') {
            $rows = $pdo->query('SELECT ID,IMPORTE_RECARGA,PORCENTAJE,TIPO_USUARIO,FECHA_INICIO,FECHA_FIN,ACTIVO,CREADO_EN FROM RADAR_OFERTAS_RECARGA ORDER BY ID DESC')->fetchAll(PDO::FETCH_ASSOC);
            radarReply(200, ['ok'=>true,'ofertas'=>$rows]);
        }
        if ($action === 'admin_recharge_offers_delete') {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) radarReply(422, ['ok'=>false,'message'=>'La oferta seleccionada no es válida.']);
            $q=$pdo->prepare('DELETE FROM RADAR_OFERTAS_RECARGA WHERE ID=?'); $q->execute([$id]);
            if (!$q->rowCount()) radarReply(404, ['ok'=>false,'message'=>'No se encontró la oferta.']);
            radarReply(200, ['ok'=>true,'message'=>'Oferta eliminada correctamente.']);
        }
        $id=filter_var($data['id'] ?? 0,FILTER_VALIDATE_INT);
        $amount=filter_var($data['importe_recarga'] ?? null,FILTER_VALIDATE_FLOAT);
        $percent=filter_var($data['porcentaje'] ?? null,FILTER_VALIDATE_FLOAT);
        $type=strtolower(trim((string)($data['tipo_usuario'] ?? 'todos')));
        $start=trim((string)($data['fecha_inicio'] ?? '')); $end=trim((string)($data['fecha_fin'] ?? ''));
        $start=$start!==''?str_replace('T',' ',$start).(strlen($start)===16?':00':''):null;
        $end=$end!==''?str_replace('T',' ',$end).(strlen($end)===16?':00':''):null;
        $active=!empty($data['activo'])?1:0;
        if ($amount===false || $amount<=0 || $amount>1000000 || $percent===false || $percent<=0 || $percent>500 || !in_array($type,['todos','cliente','transportista'],true)) radarReply(422,['ok'=>false,'message'=>'Indica un importe de recarga válido, un porcentaje entre 0 y 500 y un tipo de usuario.']);
        if ($start && $end && strtotime($end)<strtotime($start)) radarReply(422,['ok'=>false,'message'=>'La fecha final no puede ser anterior a la inicial.']);
        if ($id && $id>0) {
            $q=$pdo->prepare('UPDATE RADAR_OFERTAS_RECARGA SET IMPORTE_RECARGA=?,PORCENTAJE=?,TIPO_USUARIO=?,FECHA_INICIO=?,FECHA_FIN=?,ACTIVO=? WHERE ID=?');
            $q->execute([number_format($amount,2,'.',''),number_format($percent,2,'.',''),$type,$start,$end,$active,$id]);
            if (!$q->rowCount()) { $check=$pdo->prepare('SELECT ID FROM RADAR_OFERTAS_RECARGA WHERE ID=?'); $check->execute([$id]); if (!$check->fetch()) radarReply(404,['ok'=>false,'message'=>'No se encontró la oferta.']); }
        } else {
            $q=$pdo->prepare('INSERT INTO RADAR_OFERTAS_RECARGA (IMPORTE_RECARGA,PORCENTAJE,TIPO_USUARIO,FECHA_INICIO,FECHA_FIN,ACTIVO) VALUES (?,?,?,?,?,?)');
            $q->execute([number_format($amount,2,'.',''),number_format($percent,2,'.',''),$type,$start,$end,$active]);
            $newOfferId = (int)$pdo->lastInsertId();
            // Al crear una oferta activa, avisar a todas las cuentas (clientes, transportistas y administradores).
            // La notificación es informativa; la elegibilidad real se valida al confirmar cada recarga.
            if ($active === 1) {
                $targetLabels = ['todos'=>'Todos los usuarios','cliente'=>'Clientes','transportista'=>'Transportistas'];
                $offerLines = [
                    '¡Hay una nueva oferta de recarga disponible en El Radar!',
                    'Recarga mínima: ' . number_format($amount, 2, ',', '.') . ' CUP',
                    'Bonificación: ' . number_format($percent, 2, ',', '.') . '%',
                    'Dirigida a: ' . ($targetLabels[$type] ?? 'Usuarios elegibles')
                ];
                if ($start) $offerLines[] = 'Válida desde: ' . $start;
                if ($end) $offerLines[] = 'Válida hasta: ' . $end;
                $offerLines[] = 'Para aprovecharla, confirma tu transferencia desde RecargarCuenta > Agregar Saldo a tu cuenta.';
                $offerMessage = implode("\n", $offerLines);
                $recipients = $pdo->query('SELECT ID FROM USUARIOS')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($recipients as $recipientId) {
                    radarNotify($pdo, (int)$recipientId, '🎉 Nueva oferta de recarga', $offerMessage, 'success', 'oferta_recarga', $newOfferId);
                }
            }
        }
        radarReply(200,['ok'=>true,'message'=> $id && $id > 0 ? 'Oferta de recarga actualizada correctamente.' : 'Oferta guardada y notificada a todos los usuarios.']);
    }

    // Novedades visibles para usuarios y administración de publicaciones.
    if (in_array($action, ['latest_news_list','latest_news_detail','admin_news_list','admin_news_save','admin_news_delete'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar las novedades.']);
        $userQ = $pdo->prepare('SELECT ID,TIPO,SALDO FROM USUARIOS WHERE ID=? LIMIT 1');
        $userQ->execute([(int)$_SESSION['user_id']]);
        $newsUser = $userQ->fetch(PDO::FETCH_ASSOC);
        if (!$newsUser) radarReply(401, ['ok'=>false, 'message'=>'La sesión no corresponde a una cuenta válida.']);
        $isAdmin = strtolower(trim((string)$newsUser['TIPO'])) === 'admin';
        if ($action === 'latest_news_detail') {
            $newsId = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$newsId || $newsId < 1) radarReply(422, ['ok'=>false,'message'=>'La novedad seleccionada no es válida.']);
            // Solo se muestra el detalle si esta cuenta recibió la notificación de esa publicación.
            $allowed = $pdo->prepare("SELECT ID FROM NOTIFICACIONES WHERE USUARIO_ID=? AND REFERENCIA_TIPO='novedad' AND REFERENCIA_ID=? LIMIT 1");
            $allowed->execute([(int)$newsUser['ID'], $newsId]);
            if (!$allowed->fetchColumn()) radarReply(403, ['ok'=>false,'message'=>'No tienes acceso a esta publicación.']);
            $detail = $pdo->prepare('SELECT ID,TITULO,CATEGORIA,CONTENIDO,IMAGEN,CREADO_EN FROM RADAR_NOVEDADES WHERE ID=? AND ACTIVO=1 LIMIT 1');
            $detail->execute([$newsId]);
            $item = $detail->fetch(PDO::FETCH_ASSOC);
            if (!$item) radarReply(404, ['ok'=>false,'message'=>'La novedad ya no está disponible.']);
            radarReply(200, ['ok'=>true,'novedad'=>$item]);
        }
        if ($action === 'latest_news_list') {
            $type = strtolower(trim((string)$newsUser['TIPO']));
            $now = date('Y-m-d H:i:s');
            $q = $pdo->prepare("SELECT ID,TITULO,CATEGORIA,CONTENIDO,IMAGEN,CREADO_EN FROM RADAR_NOVEDADES WHERE ACTIVO=1 AND TIPO_USUARIO IN ('todos',?) AND (FECHA_INICIO IS NULL OR FECHA_INICIO<=?) AND (FECHA_FIN IS NULL OR FECHA_FIN>=?) ORDER BY COALESCE(FECHA_INICIO,CREADO_EN) DESC, ID DESC LIMIT 30");
            $q->execute([$type,$now,$now]);
            $news = $q->fetchAll(PDO::FETCH_ASSOC);
            // Agrega bonos/cupones vigentes y ofertas de recarga según la cuenta.
            $q = $pdo->prepare("SELECT CODIGO,DESCRIPCION,BONO_IMPORTE,FECHA_INICIO,FECHA_FIN FROM RADAR_CUPONES WHERE ACTIVO=1 AND TIPO_USUARIO IN ('todos',?) AND (FECHA_INICIO IS NULL OR FECHA_INICIO<=?) AND (FECHA_FIN IS NULL OR FECHA_FIN>=?) AND (MAX_USOS=0 OR USOS<MAX_USOS) AND SALDO_MINIMO<=? ORDER BY ID DESC LIMIT 10");
            $q->execute([$type,$now,$now,(float)$newsUser['SALDO']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $coupon) {
                $news[] = ['ID'=>'coupon-'.$coupon['CODIGO'],'TITULO'=>'🎁 Bono disponible: '.$coupon['CODIGO'],'CATEGORIA'=>'bono','CONTENIDO'=>trim((string)$coupon['DESCRIPCION']) . ' Bono de ' . number_format((float)$coupon['BONO_IMPORTE'],2,',','.').' CUP. Puedes canjearlo desde Operaciones > Canjear cupón.','CREADO_EN'=>$coupon['FECHA_INICIO'] ?: null];
            }
            $q = $pdo->prepare("SELECT IMPORTE_RECARGA,PORCENTAJE,FECHA_INICIO,FECHA_FIN FROM RADAR_OFERTAS_RECARGA WHERE ACTIVO=1 AND TIPO_USUARIO IN ('todos',?) AND (FECHA_INICIO IS NULL OR FECHA_INICIO<=?) AND (FECHA_FIN IS NULL OR FECHA_FIN>=?) ORDER BY PORCENTAJE DESC LIMIT 10");
            $q->execute([$type,$now,$now]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $offer) {
                $news[] = ['ID'=>'recharge-'.md5($offer['IMPORTE_RECARGA'].'-'.$offer['PORCENTAJE'].'-'.$offer['FECHA_INICIO']),'TITULO'=>'💸 Oferta especial de recarga','CATEGORIA'=>'recarga','CONTENIDO'=>'Recarga desde '.number_format((float)$offer['IMPORTE_RECARGA'],2,',','.').' CUP y recibe un bono adicional del '.number_format((float)$offer['PORCENTAJE'],2,',','.').'%. La bonificación se aplica automáticamente al confirmar una transferencia válida.','CREADO_EN'=>$offer['FECHA_INICIO'] ?: null];
            }
            usort($news, static function($a,$b){ return strcmp((string)($b['CREADO_EN']??''),(string)($a['CREADO_EN']??'')); });
            radarReply(200,['ok'=>true,'novedades'=>array_slice($news,0,30)]);
        }
        if (!$isAdmin) radarReply(403, ['ok'=>false, 'message'=>'Solo Administración puede gestionar las novedades.']);
        if ($action === 'admin_news_list') {
            $rows=$pdo->query('SELECT ID,TITULO,CATEGORIA,CONTENIDO,IMAGEN,TIPO_USUARIO,FECHA_INICIO,FECHA_FIN,ACTIVO,CREADO_EN FROM RADAR_NOVEDADES ORDER BY ID DESC')->fetchAll(PDO::FETCH_ASSOC);
            radarReply(200,['ok'=>true,'novedades'=>$rows]);
        }
        if ($action === 'admin_news_delete') {
            $id=filter_var($data['id']??null,FILTER_VALIDATE_INT);
            if(!$id||$id<1)radarReply(422,['ok'=>false,'message'=>'La publicación seleccionada no es válida.']);
            $q=$pdo->prepare('DELETE FROM RADAR_NOVEDADES WHERE ID=?');$q->execute([$id]);
            if(!$q->rowCount())radarReply(404,['ok'=>false,'message'=>'No se encontró la publicación.']);
            radarReply(200,['ok'=>true,'message'=>'Publicación eliminada.']);
        }
        $id=filter_var($data['id']??0,FILTER_VALIDATE_INT);
        $title=trim((string)($data['titulo']??''));$category=strtolower(trim((string)($data['categoria']??'informacion')));
        $content=trim((string)($data['contenido']??''));$audience=strtolower(trim((string)($data['tipo_usuario']??'todos')));
        $imageInput = $data['imagen'] ?? null;
        $image = ($imageInput !== null && $imageInput !== '') ? radarPhoto($imageInput) : null;
        $removeImage = !empty($data['quitar_imagen']);
        $start=trim((string)($data['fecha_inicio']??''));$end=trim((string)($data['fecha_fin']??''));
        $start=$start!==''?str_replace('T',' ',$start).(strlen($start)===16?':00':''):null;
        $end=$end!==''?str_replace('T',' ',$end).(strlen($end)===16?':00':''):null;
        $active=!empty($data['activo'])?1:0;
        if($title===''||mb_strlen($title,'UTF-8')>150||$content===''||mb_strlen($content,'UTF-8')>5000)radarReply(422,['ok'=>false,'message'=>'Escribe un título y un contenido de hasta 5000 caracteres.']);
        if(!in_array($category,['informacion','promocion','bono','recarga','mantenimiento','aviso'],true)||!in_array($audience,['todos','cliente','transportista'],true))radarReply(422,['ok'=>false,'message'=>'Selecciona una categoría y público válidos.']);
        if($start&&$end&&strtotime($end)<strtotime($start))radarReply(422,['ok'=>false,'message'=>'La fecha final no puede ser anterior a la inicial.']);
        try {
            if($id&&$id>0){
                $check=$pdo->prepare('SELECT ID,IMAGEN FROM RADAR_NOVEDADES WHERE ID=? LIMIT 1');$check->execute([$id]);$existing=$check->fetch(PDO::FETCH_ASSOC);
                if(!$existing)radarReply(404,['ok'=>false,'message'=>'No se encontró la publicación que intentas actualizar.']);
                $finalImage = $removeImage ? null : ($image !== null ? $image : ($existing['IMAGEN'] ?? null));
                $q=$pdo->prepare('UPDATE RADAR_NOVEDADES SET TITULO=?,CATEGORIA=?,CONTENIDO=?,IMAGEN=?,TIPO_USUARIO=?,FECHA_INICIO=?,FECHA_FIN=?,ACTIVO=? WHERE ID=?');
                $q->execute([$title,$category,$content,$finalImage,$audience,$start,$end,$active,$id]);
            } else {
                $q=$pdo->prepare('INSERT INTO RADAR_NOVEDADES (TITULO,CATEGORIA,CONTENIDO,IMAGEN,TIPO_USUARIO,FECHA_INICIO,FECHA_FIN,ACTIVO,CREADO_POR) VALUES (?,?,?,?,?,?,?,?,?)');
                $q->execute([$title,$category,$content,$image,$audience,$start,$end,$active,(int)$newsUser['ID']]);
                $id=(int)$pdo->lastInsertId();
                // Cada publicación nueva y activa genera una notificación interna y push
                // para todas las cuentas registradas, independientemente del público del feed.
                if ($active === 1) {
                    $notificationTitle = '📰 Nueva novedad en El Radar';
                    $notificationMessage = $title . "\\n" . $content;
                    $recipients = $pdo->query('SELECT ID FROM USUARIOS')->fetchAll(PDO::FETCH_COLUMN);
                    foreach ($recipients as $recipientId) {
                        radarNotify($pdo, (int)$recipientId, $notificationTitle, $notificationMessage, 'info', 'novedad', $id);
                    }
                }
            }
        } catch (Throwable $saveError) {
            error_log('RADAR_NOVEDADES save error: '.$saveError->getMessage());
            // La respuesta conserva el mensaje técnico para que Administración pueda
            // identificar la columna o permiso MySQL que impide guardar la publicación.
            radarReply(500,['ok'=>false,'message'=>'MySQL no pudo guardar la novedad: '.$saveError->getMessage()]);
        }
        radarReply(200,['ok'=>true,'id'=>(int)$id,'message'=>$id&&$id>0?'Novedad publicada o actualizada correctamente.':'Novedad publicada correctamente.']);
    }

    // Gestión de cupones promocionales y canje seguro de bonos.
    if (in_array($action, ['admin_coupons_list','admin_coupons_save','admin_coupons_delete','admin_coupons_broadcast','coupon_redeem'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para gestionar o canjear cupones.']);
        $currentId = (int)$_SESSION['user_id'];
        $auth = $pdo->prepare('SELECT ID, TIPO, SALDO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([$currentId]);
        $currentAccount = $auth->fetch();
        if (!$currentAccount) radarReply(401, ['ok'=>false, 'message'=>'La sesión no corresponde a una cuenta válida.']);

        if ($action !== 'coupon_redeem') {
            if (strtolower(trim((string)$currentAccount['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'Solo Administración puede gestionar los cupones.']);
            if ($action === 'admin_coupons_list') {
                $rows = $pdo->query('SELECT ID,CODIGO,DESCRIPCION,CONDICION_TEXTO,TIPO_USUARIO,BONO_IMPORTE,SALDO_MINIMO,MAX_USOS,USOS,FECHA_INICIO,FECHA_FIN,ACTIVO,CREADO_EN FROM RADAR_CUPONES ORDER BY ID DESC')->fetchAll();
                radarReply(200, ['ok'=>true, 'cupones'=>$rows]);
            }
            if ($action === 'admin_coupons_broadcast') {
                $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) radarReply(422, ['ok'=>false, 'message'=>'El bono seleccionado no es válido.']);
                $q = $pdo->prepare('SELECT CODIGO,DESCRIPCION,CONDICION_TEXTO,TIPO_USUARIO,BONO_IMPORTE,SALDO_MINIMO,MAX_USOS,USOS,FECHA_INICIO,FECHA_FIN,ACTIVO FROM RADAR_CUPONES WHERE ID=? LIMIT 1');
                $q->execute([$id]);
                $coupon = $q->fetch(PDO::FETCH_ASSOC);
                if (!$coupon) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el bono seleccionado.']);
                if ((int)$coupon['ACTIVO'] !== 1) radarReply(409, ['ok'=>false, 'message'=>'Este bono está inactivo. Actívalo antes de difundirlo.']);

                $money = function($value) { return number_format((float)$value, 2, ',', '.') . ' CUP'; };
                $targetNames = ['todos'=>'Clientes y transportistas','cliente'=>'Clientes','transportista'=>'Transportistas'];
                $details = [
                    'Código: ' . $coupon['CODIGO'],
                    'Bono: ' . $money($coupon['BONO_IMPORTE']),
                    'Dirigido a: ' . ($targetNames[$coupon['TIPO_USUARIO']] ?? 'Usuarios elegibles'),
                    'Saldo mínimo requerido: ' . $money($coupon['SALDO_MINIMO']),
                    'Límite de canjes: ' . ((int)$coupon['MAX_USOS'] > 0 ? (int)$coupon['MAX_USOS'] : 'Sin límite'),
                    'Canjes realizados: ' . (int)$coupon['USOS']
                ];
                if (trim((string)$coupon['DESCRIPCION']) !== '') $details[] = 'Oferta: ' . trim($coupon['DESCRIPCION']);
                if (!empty($coupon['FECHA_INICIO'])) $details[] = 'Válido desde: ' . $coupon['FECHA_INICIO'];
                if (!empty($coupon['FECHA_FIN'])) $details[] = 'Válido hasta: ' . $coupon['FECHA_FIN'];
                if (trim((string)$coupon['CONDICION_TEXTO']) !== '') $details[] = 'Condiciones: ' . trim($coupon['CONDICION_TEXTO']);
                $message = "¡Hay un bono promocional disponible en El Radar!\n" . implode("\n", $details) . "\nCanjéalo desde Operaciones > Canjear bono promocional.";
                // Respetar el tipo de cuenta configurado en el bono. Los administradores
                // reciben siempre una copia informativa, aunque no sean elegibles para canjearlo.
                $couponUserType = strtolower(trim((string)$coupon['TIPO_USUARIO']));
                if ($couponUserType === 'cliente') {
                    $recipientTypes = ['cliente', 'admin'];
                    $audienceLabel = 'clientes y administradores';
                } elseif ($couponUserType === 'transportista') {
                    $recipientTypes = ['transportista', 'admin'];
                    $audienceLabel = 'transportistas y administradores';
                } elseif ($couponUserType === 'todos') {
                    $recipientTypes = ['cliente', 'transportista', 'admin'];
                    $audienceLabel = 'clientes, transportistas y administradores';
                } else {
                    radarReply(422, ['ok'=>false, 'message'=>'El tipo de usuario configurado para este bono no es válido.']);
                }
                // Los usuarios normales solo reciben el aviso si su tipo coincide y
                // su saldo actual alcanza el mínimo exigido por el bono. Administración
                // recibe siempre una copia informativa, sin importar su saldo.
                $placeholders = implode(',', array_fill(0, count($recipientTypes), '?'));
                $recipientQuery = $pdo->prepare(
                    "SELECT ID, LOWER(TRIM(TIPO)) AS TIPO FROM USUARIOS
                     WHERE LOWER(TRIM(TIPO)) IN ($placeholders)
                       AND (LOWER(TRIM(TIPO))='admin' OR SALDO >= ?)"
                );
                $recipientParams = array_merge($recipientTypes, [(float)$coupon['SALDO_MINIMO']]);
                $recipientQuery->execute($recipientParams);
                $recipients = $recipientQuery->fetchAll(PDO::FETCH_ASSOC);
                $sent = 0;
                $eligibleUsers = 0;
                $adminCopies = 0;
                foreach ($recipients as $recipient) {
                    radarNotify($pdo, (int)$recipient['ID'], '🎁 Nuevo bono promocional: ' . $coupon['CODIGO'], $message, 'success');
                    $sent++;
                    if ($recipient['TIPO'] === 'admin') $adminCopies++;
                    else $eligibleUsers++;
                }
                $minimumLabel = $money($coupon['SALDO_MINIMO']);
                radarReply(200, ['ok'=>true, 'sent'=>$sent, 'eligible_users'=>$eligibleUsers, 'admin_copies'=>$adminCopies,
                    'message'=>'Bono difundido: ' . $eligibleUsers . ' usuario(s) elegible(s) por tipo y saldo, más ' . $adminCopies . ' administrador(es). Saldo mínimo exigido: ' . $minimumLabel . '.']);
            }
            if ($action === 'admin_coupons_delete') {
                $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
                if (!$id || $id < 1) radarReply(422, ['ok'=>false, 'message'=>'El cupón seleccionado no es válido.']);
                $q = $pdo->prepare('SELECT USOS FROM RADAR_CUPONES WHERE ID=?'); $q->execute([$id]); $uses = $q->fetchColumn();
                if ($uses === false) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el cupón.']);
                if ((int)$uses > 0) $pdo->prepare('UPDATE RADAR_CUPONES SET ACTIVO=0 WHERE ID=?')->execute([$id]);
                else $pdo->prepare('DELETE FROM RADAR_CUPONES WHERE ID=?')->execute([$id]);
                radarReply(200, ['ok'=>true, 'message'=>(int)$uses > 0 ? 'El cupón ya tuvo canjes; se desactivó para conservar el historial.' : 'Cupón eliminado correctamente.']);
            }
            $id = filter_var($data['id'] ?? 0, FILTER_VALIDATE_INT);
            $code = strtoupper(trim((string)($data['codigo'] ?? '')));
            $description = trim((string)($data['descripcion'] ?? ''));
            $condition = trim((string)($data['condicion_texto'] ?? ''));
            $userType = strtolower(trim((string)($data['tipo_usuario'] ?? 'todos')));
            $bonus = filter_var($data['bono_importe'] ?? null, FILTER_VALIDATE_FLOAT);
            $minimum = filter_var($data['saldo_minimo'] ?? 0, FILTER_VALIDATE_FLOAT);
            $maxUses = filter_var($data['max_usos'] ?? 0, FILTER_VALIDATE_INT);
            $active = !empty($data['activo']) ? 1 : 0;
            $start = trim((string)($data['fecha_inicio'] ?? ''));
            $end = trim((string)($data['fecha_fin'] ?? ''));
            $start = $start !== '' ? str_replace('T',' ',$start) . (strlen($start) === 16 ? ':00' : '') : null;
            $end = $end !== '' ? str_replace('T',' ',$end) . (strlen($end) === 16 ? ':00' : '') : null;
            if (!preg_match('/^[A-Z0-9_-]{4,40}$/', $code)) radarReply(422, ['ok'=>false, 'message'=>'El código debe tener entre 4 y 40 caracteres: letras, números, guion o guion bajo.']);
            if (mb_strlen($description,'UTF-8') > 180 || mb_strlen($condition,'UTF-8') > 500) radarReply(422, ['ok'=>false, 'message'=>'La descripción o condición supera la longitud permitida.']);
            if (!in_array($userType, ['todos','cliente','transportista'], true) || $bonus === false || $bonus <= 0 || $bonus > 1000000 || $minimum === false || $minimum < 0 || $maxUses === false || $maxUses < 0 || $maxUses > 10000000) radarReply(422, ['ok'=>false, 'message'=>'Revisa el tipo de usuario, importe del bono, saldo mínimo y límite de usos.']);
            if ($start && $end && strtotime($end) < strtotime($start)) radarReply(422, ['ok'=>false, 'message'=>'La fecha final no puede ser anterior a la fecha inicial.']);
            try {
                if ($id && $id > 0) {
                    $q = $pdo->prepare('UPDATE RADAR_CUPONES SET CODIGO=?,DESCRIPCION=?,CONDICION_TEXTO=?,TIPO_USUARIO=?,BONO_IMPORTE=?,SALDO_MINIMO=?,MAX_USOS=?,FECHA_INICIO=?,FECHA_FIN=?,ACTIVO=? WHERE ID=?');
                    $q->execute([$code,$description,$condition,$userType,$bonus,$minimum,$maxUses,$start,$end,$active,$id]);
                    $exists = $pdo->prepare('SELECT ID FROM RADAR_CUPONES WHERE ID=?'); $exists->execute([$id]);
                    if (!$exists->fetch()) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el cupón que intentas editar.']);
                } else {
                    $q = $pdo->prepare('INSERT INTO RADAR_CUPONES (CODIGO,DESCRIPCION,CONDICION_TEXTO,TIPO_USUARIO,BONO_IMPORTE,SALDO_MINIMO,MAX_USOS,FECHA_INICIO,FECHA_FIN,ACTIVO,CREADO_POR) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
                    $q->execute([$code,$description,$condition,$userType,$bonus,$minimum,$maxUses,$start,$end,$active,$currentId]);
                }
                radarReply(200, ['ok'=>true, 'message'=>'Cupón guardado correctamente.']);
            } catch (PDOException $e) {
                if ((string)$e->getCode() === '23000') radarReply(409, ['ok'=>false, 'message'=>'Ya existe un cupón con ese código.']);
                throw $e;
            }
        }

        $accountType = strtolower(trim((string)$currentAccount['TIPO']));
        if (!in_array($accountType, ['cliente','transportista'], true)) radarReply(403, ['ok'=>false, 'message'=>'Las cuentas administradoras no pueden canjear cupones.']);
        $code = strtoupper(trim((string)($data['codigo'] ?? '')));
        if ($code === '' || strlen($code) > 40) radarReply(422, ['ok'=>false, 'message'=>'Introduce un código de cupón válido.']);
        try {
            $pdo->beginTransaction();
            $userLock = $pdo->prepare('SELECT ID,TIPO,SALDO FROM USUARIOS WHERE ID=? FOR UPDATE');
            $userLock->execute([$currentId]); $lockedUser = $userLock->fetch();
            $couponQuery = $pdo->prepare('SELECT * FROM RADAR_CUPONES WHERE CODIGO=? FOR UPDATE');
            $couponQuery->execute([$code]); $coupon = $couponQuery->fetch();
            if (!$coupon) { $pdo->rollBack(); radarReply(404, ['ok'=>false, 'message'=>'El código de cupón no existe.']); }
            if ((int)$coupon['ACTIVO'] !== 1) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'Este cupón no está activo.']); }
            if (!in_array($coupon['TIPO_USUARIO'], ['todos',$accountType], true)) { $pdo->rollBack(); radarReply(403, ['ok'=>false, 'message'=>'Este cupón no está disponible para tu tipo de cuenta.']); }
            $now = time();
            if (!empty($coupon['FECHA_INICIO']) && $now < strtotime($coupon['FECHA_INICIO'])) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'Este cupón todavía no está vigente.']); }
            if (!empty($coupon['FECHA_FIN']) && $now > strtotime($coupon['FECHA_FIN'])) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'La vigencia de este cupón ha terminado.']); }
            if ((int)$coupon['MAX_USOS'] > 0 && (int)$coupon['USOS'] >= (int)$coupon['MAX_USOS']) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'Este cupón alcanzó el máximo de canjes permitidos.']); }
            $already = $pdo->prepare('SELECT ID FROM RADAR_CUPONES_USOS WHERE CUPON_ID=? AND USUARIO_ID=? LIMIT 1');
            $already->execute([(int)$coupon['ID'],$currentId]);
            if ($already->fetch()) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'Ya canjeaste este cupón. Cada usuario puede utilizarlo una sola vez.']); }
            $before = round((float)$lockedUser['SALDO'],2);
            if ($before + 0.00001 < (float)$coupon['SALDO_MINIMO']) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'Para canjear este cupón necesitas un saldo mínimo de ' . number_format((float)$coupon['SALDO_MINIMO'],2,'.','') . '. ' . (string)$coupon['CONDICION_TEXTO']]); }
            $bonus = round((float)$coupon['BONO_IMPORTE'],2); $after = round($before + $bonus,2);
            $pdo->prepare('UPDATE USUARIOS SET SALDO=? WHERE ID=?')->execute([number_format($after,2,'.',''),$currentId]);
            $pdo->prepare('INSERT INTO RADAR_CUPONES_USOS (CUPON_ID,USUARIO_ID,IMPORTE,SALDO_ANTERIOR,SALDO_NUEVO) VALUES (?,?,?,?,?)')->execute([(int)$coupon['ID'],$currentId,$bonus,$before,$after]);
            $pdo->prepare('UPDATE RADAR_CUPONES SET USOS=USOS+1 WHERE ID=?')->execute([(int)$coupon['ID']]);
            $movement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID,TIPO,DESCRIPCION,IMPORTE,SALDO_ANTERIOR,SALDO_NUEVO,REFERENCIA) VALUES (?,'bono_cupon',?,?,?,?,?)");
            $movement->execute([$currentId,'Bono promocional por cupón ' . $code,$bonus,$before,$after,$code]);
            radarNotify($pdo,$currentId,'Cupón canjeado','Has recibido un bono de ' . number_format($bonus,2,'.','') . ' por el cupón ' . $code . '.','success');
            $pdo->commit();
            radarReply(200, ['ok'=>true,'message'=>'Cupón canjeado correctamente. Se agregaron ' . number_format($bonus,2,'.','') . ' a tu saldo.','bono'=>$bonus,'saldo_actual'=>$after]);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar coupon redemption error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo canjear el cupón. Inténtalo nuevamente.']);
        }
    }

    // Historial completo de aprobaciones y revocaciones: solo administradores.
    if ($action === 'admin_transport_audit') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false,'message'=>'Inicia sesión.']);
        $auth=$pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=?'); $auth->execute([(int)$_SESSION['user_id']]);
        if (strtolower((string)$auth->fetchColumn()) !== 'admin') radarReply(403,['ok'=>false,'message'=>'Solo los administradores pueden consultar el historial global.']);
        $q=$pdo->query("SELECT a.ID,a.SOLICITUD_ID,a.TRANSPORTISTA_ID,a.TRANSPORTISTA_USUARIO,a.APROBADO_POR_ID,a.APROBADO_POR_USUARIO,a.FECHA_APROBACION,a.ACCION,COALESCE(NULLIF(st.NOMBRE,''),u.NOMBRE) AS NOMBRE,COALESCE(NULLIF(st.APELLIDOS,''),u.APELLIDOS) AS APELLIDOS,u.FOTO AS FOTO_TRANSPORTISTA,st.FOTO_VEHICULO,st.CARNET_IDENTIDAD,st.TIPO_VEHICULO,st.CHAPA,st.LICENCIA_CONDUCCION,st.TELEFONO,st.ESTADO_SOLICITUD,st.CREADA_EN AS SOLICITUD_CREADA_EN,ap.NOMBRE AS APROBADOR_NOMBRE,ap.APELLIDOS AS APROBADOR_APELLIDOS,ap.FOTO AS FOTO_APROBADOR,ap.TIPO AS APROBADOR_TIPO FROM RADAR_AUDITORIA_TRANSPORTISTAS a LEFT JOIN USUARIOS u ON u.ID=a.TRANSPORTISTA_ID LEFT JOIN SOLICITUDES_TRANSPORTISTA st ON st.ID=a.SOLICITUD_ID LEFT JOIN USUARIOS ap ON ap.ID=a.APROBADO_POR_ID ORDER BY a.FECHA_APROBACION DESC,a.ID DESC LIMIT 1000");
        radarReply(200,['ok'=>true,'registros'=>$q->fetchAll()]);
    }

    // El editor debe registrar la tarjeta donde recibirá sus pagos antes de aprobar o revocar transportistas.
    if ($action === 'editor_payment_card_get' || $action === 'editor_payment_card_save') {
        if (empty($_SESSION['user_id'])) radarReply(401,['ok'=>false,'message'=>'Inicia sesión.']);
        $uid=(int)$_SESSION['user_id'];
        $q=$pdo->prepare('SELECT ES_EDITOR,TARJETA_PAGO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$uid]); $editorCardUser=$q->fetch(PDO::FETCH_ASSOC);
        if (!$editorCardUser || (int)$editorCardUser['ES_EDITOR'] !== 1) radarReply(403,['ok'=>false,'message'=>'Esta opción está disponible únicamente para usuarios con Editor activado.']);
        if ($action === 'editor_payment_card_get') {
            $card=(string)($editorCardUser['TARJETA_PAGO'] ?? '');
            radarReply(200,['ok'=>true,'tarjeta_configurada'=>$card !== '']);
        }
        $card=preg_replace('/\D+/', '', (string)($data['tarjeta'] ?? ''));
        if (strlen($card) < 8 || strlen($card) > 30) radarReply(422,['ok'=>false,'message'=>'Introduce un número de tarjeta válido (entre 8 y 30 dígitos).']);
        $save=$pdo->prepare('UPDATE USUARIOS SET TARJETA_PAGO=? WHERE ID=? AND ES_EDITOR=1');
        $save->execute([$card,$uid]);
        radarNotify($pdo,$uid,'Tarjeta de cobro registrada','Tu número de tarjeta quedó guardado. Ya puedes aprobar y revocar solicitudes de transportista.','success');
        radarReply(200,['ok'=>true,'message'=>'Tarjeta de cobro guardada correctamente. Ya puedes gestionar solicitudes.']);
    }

    // Cartera de transportistas gestionados por el editor conectado.
    if ($action === 'editor_my_transportistas') {
        if (empty($_SESSION['user_id'])) radarReply(401,['ok'=>false,'message'=>'Inicia sesión.']);
        $uid=(int)$_SESSION['user_id']; $auth=$pdo->prepare('SELECT TIPO,ES_EDITOR FROM USUARIOS WHERE ID=?'); $auth->execute([$uid]); $me=$auth->fetch();
        if (!$me || (int)$me['ES_EDITOR'] !== 1) radarReply(403,['ok'=>false,'message'=>'Mis Transportistas está disponible solo para usuarios con Editor activado.']);
        $percent=(float)$pdo->query('SELECT PORCENTAJE_EDITOR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
        $q=$pdo->prepare("SELECT a.TRANSPORTISTA_ID, a.TRANSPORTISTA_USUARIO, MAX(a.FECHA_APROBACION) AS FECHA_ULTIMA, MIN(CASE WHEN a.ACCION='APROBADO' THEN a.FECHA_APROBACION ELSE NULL END) AS FECHA_PRIMERA_APROBACION, (SELECT aa.ACCION FROM RADAR_AUDITORIA_TRANSPORTISTAS aa WHERE aa.TRANSPORTISTA_ID=a.TRANSPORTISTA_ID AND aa.APROBADO_POR_ID=? ORDER BY aa.FECHA_APROBACION DESC,aa.ID DESC LIMIT 1) AS ULTIMA_ACCION, u.NOMBRE,u.APELLIDOS,u.FOTO,u.TIPO AS TIPO_CUENTA, (SELECT st.FOTO_VEHICULO FROM SOLICITUDES_TRANSPORTISTA st WHERE st.USUARIO_ID=a.TRANSPORTISTA_ID ORDER BY st.ID DESC LIMIT 1) AS FOTO_VEHICULO FROM RADAR_AUDITORIA_TRANSPORTISTAS a LEFT JOIN USUARIOS u ON u.ID=a.TRANSPORTISTA_ID WHERE a.APROBADO_POR_ID=? GROUP BY a.TRANSPORTISTA_ID,a.TRANSPORTISTA_USUARIO,u.NOMBRE,u.APELLIDOS,u.FOTO,u.TIPO ORDER BY FECHA_ULTIMA DESC");
        $q->execute([$uid,$uid]); $items=$q->fetchAll(PDO::FETCH_ASSOC);
        $moves=$pdo->prepare("SELECT m.ID,m.TIPO,m.DESCRIPCION,m.IMPORTE,m.REFERENCIA,m.CREADO_EN,m.PORCENTAJE_EDITOR_APLICADO FROM RADAR_MOVIMIENTOS_SALDO m WHERE m.USUARIO_ID=? AND LOWER(m.TIPO)='recarga' AND EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS ap WHERE ap.TRANSPORTISTA_ID=m.USUARIO_ID AND ap.APROBADO_POR_ID=? AND ap.ACCION='APROBADO' AND m.CREADO_EN>=ap.FECHA_APROBACION AND NOT EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS rv WHERE rv.TRANSPORTISTA_ID=ap.TRANSPORTISTA_ID AND rv.APROBADO_POR_ID=ap.APROBADO_POR_ID AND rv.ACCION='REVOCADO' AND rv.FECHA_APROBACION>=ap.FECHA_APROBACION AND rv.FECHA_APROBACION<=m.CREADO_EN)) ORDER BY m.CREADO_EN DESC,m.ID DESC LIMIT 500");
        foreach($items as &$item){$moves->execute([(int)$item['TRANSPORTISTA_ID'],$uid]);$item['operaciones']=$moves->fetchAll(PDO::FETCH_ASSOC);$total=0.0;$earnings=0.0;foreach($item['operaciones'] as &$movement){$amount=(float)$movement['IMPORTE'];$snapshot=$movement['PORCENTAJE_EDITOR_APLICADO'];$effectivePercent=$snapshot!==null?(float)$snapshot:$percent;$commission=round(max(0,$amount)*$effectivePercent/100,2);$movement['PORCENTAJE_APLICADO']=$snapshot!==null?(float)$snapshot:null;$movement['PORCENTAJE_ESTIMADO']=$snapshot===null;$movement['GANANCIA_EDITOR']=$commission;if(strtolower((string)$movement['TIPO'])==='recarga'){$total+=$amount;$earnings+=$commission;}}unset($movement);$item['total_recargas']=$total;$item['ganancia_editor']=$earnings;$item['porcentaje_editor']=$percent;$item['estado_actual']=strtoupper((string)$item['ULTIMA_ACCION'])==='REVOCADO'?'REVOCADO':(strtolower((string)$item['TIPO_CUENTA'])==='transportista'?'APROBADO':'REVOCADO');}
        unset($item);
        // El resumen de Mis Transportistas debe mostrar el saldo disponible,
        // descontando los pagos que Administración ya registró para este Editor.
        $earnedTotal=0.0;
        foreach($items as $transportista){$earnedTotal+=max(0,(float)($transportista['ganancia_editor']??0));}
        $paidTotal=0.0;
        try {
            $paidQ=$pdo->prepare('SELECT COALESCE(SUM(ABS(CAST(IMPORTE AS DECIMAL(12,2)))),0) FROM RADAR_PAGOS_EDITORES WHERE EDITOR_ID=?');
            $paidQ->execute([$uid]);
            $paidTotal=(float)$paidQ->fetchColumn();
        } catch(Throwable $e) {
            error_log('El Radar Mis Transportistas balance error: '.$e->getMessage());
        }
        $availableTotal=round(max(0,$earnedTotal-$paidTotal),2);
        radarReply(200,['ok'=>true,'transportistas'=>$items,'porcentaje_editor'=>$percent,'ganancias_generadas'=>round($earnedTotal,2),'total_pagado'=>round($paidTotal,2),'ganancias_acumuladas'=>$availableTotal]);
    }

    // Estado de cuenta del Editor: comisiones generadas y pagos recibidos, con filtro por fechas.
    if ($action === 'editor_account_statement') {
        if (empty($_SESSION['user_id'])) radarReply(401,['ok'=>false,'message'=>'Inicia sesión para consultar tu estado de cuenta.']);
        $uid=(int)$_SESSION['user_id'];
        $auth=$pdo->prepare('SELECT ES_EDITOR FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([$uid]);
        if ((int)$auth->fetchColumn() !== 1) radarReply(403,['ok'=>false,'message'=>'El estado de cuenta está disponible únicamente para Editores.']);
        $data=radarBody();
        $from=trim((string)($data['desde']??'')); $to=trim((string)($data['hasta']??''));
        if (($from!=='' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$from)) || ($to!=='' && !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$to))) radarReply(422,['ok'=>false,'message'=>'Selecciona fechas válidas.']);
        if ($from!=='' && $to!=='' && $from>$to) radarReply(422,['ok'=>false,'message'=>'La fecha inicial no puede ser posterior a la fecha final.']);
        $percent=(float)$pdo->query('SELECT PORCENTAJE_EDITOR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
        $creditsQ=$pdo->prepare("SELECT m.ID,m.DESCRIPCION,m.IMPORTE,m.REFERENCIA,m.CREADO_EN,m.USUARIO_ID,m.PORCENTAJE_EDITOR_APLICADO,u.USUARIO AS TRANSPORTISTA FROM RADAR_MOVIMIENTOS_SALDO m INNER JOIN USUARIOS u ON u.ID=m.USUARIO_ID WHERE LOWER(TRIM(m.TIPO))='recarga' AND EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS ap WHERE ap.TRANSPORTISTA_ID=m.USUARIO_ID AND ap.APROBADO_POR_ID=? AND ap.ACCION='APROBADO' AND m.CREADO_EN>=ap.FECHA_APROBACION AND NOT EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS rv WHERE rv.TRANSPORTISTA_ID=ap.TRANSPORTISTA_ID AND rv.APROBADO_POR_ID=ap.APROBADO_POR_ID AND rv.ACCION='REVOCADO' AND rv.FECHA_APROBACION>=ap.FECHA_APROBACION AND rv.FECHA_APROBACION<=m.CREADO_EN)) ORDER BY m.CREADO_EN ASC,m.ID ASC");
        $creditsQ->execute([$uid]); $rows=[];
        foreach($creditsQ->fetchAll(PDO::FETCH_ASSOC) as $m){
            $snapshot=$m['PORCENTAJE_EDITOR_APLICADO'];
            $effectivePercent=$snapshot!==null?(float)$snapshot:$percent;
            $amount=round(max(0,(float)$m['IMPORTE'])*$effectivePercent/100,2);
            if($amount<=0) continue;
            $rows[]=['id'=>'C'.$m['ID'],'fecha'=>$m['CREADO_EN'],'tipo'=>'credito','descripcion'=>'Comisión por recarga de @'.(string)$m['TRANSPORTISTA'],'referencia'=>(string)($m['REFERENCIA']??''),'importe'=>$amount];
        }
        $paymentsQ=$pdo->prepare('SELECT ID,IMPORTE,ID_TRANSFERENCIA,CREADO_EN FROM RADAR_PAGOS_EDITORES WHERE EDITOR_ID=? ORDER BY CREADO_EN ASC,ID ASC');
        $paymentsQ->execute([$uid]);
        foreach($paymentsQ->fetchAll(PDO::FETCH_ASSOC) as $m){
            $amount=abs((float)$m['IMPORTE']); if($amount<=0) continue;
            $rows[]=['id'=>'P'.$m['ID'],'fecha'=>$m['CREADO_EN'],'tipo'=>'debito','descripcion'=>'Pago recibido de Administración','referencia'=>(string)$m['ID_TRANSFERENCIA'],'importe'=>$amount];
        }
        usort($rows,function($a,$b){$cmp=strcmp((string)$a['fecha'],(string)$b['fecha']);return $cmp!==0?$cmp:strcmp((string)$a['id'],(string)$b['id']);});
        $balance=0.0; $periodCredits=0.0; $periodDebits=0.0; $filtered=[];
        foreach($rows as &$row){
            $balance += $row['tipo']==='credito' ? $row['importe'] : -$row['importe'];
            $row['saldo']=round($balance,2);
            $day=substr((string)$row['fecha'],0,10);
            if(($from==='' || $day>=$from) && ($to==='' || $day<=$to)){
                if($row['tipo']==='credito') $periodCredits+=$row['importe']; else $periodDebits+=$row['importe'];
                $filtered[]=$row;
            }
        }
        unset($row);
        $earned=0.0; $paid=0.0;
        foreach($rows as $row){if($row['tipo']==='credito')$earned+=$row['importe'];else$paid+=$row['importe'];}
        radarReply(200,['ok'=>true,'operaciones'=>array_reverse($filtered),'porcentaje_editor'=>$percent,'ganancias_generadas'=>round($earned,2),'total_pagado'=>round($paid,2),'saldo_disponible'=>round(max(0,$earned-$paid),2),'creditos_periodo'=>round($periodCredits,2),'debitos_periodo'=>round($periodDebits,2),'desde'=>$from,'hasta'=>$to]);
    }

    // Pagos a Editores: listado de ganancias pendientes y registro de pagos por transferencia.
    if ($action === 'admin_editor_payments_list' || $action === 'admin_editor_payment_register') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false,'message'=>'Inicia sesión para acceder a Pagos a Editores.']);
        $adminQ=$pdo->prepare('SELECT ID,USUARIO,TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $adminQ->execute([(int)$_SESSION['user_id']]); $adminUser=$adminQ->fetch(PDO::FETCH_ASSOC);
        if (!$adminUser || strtolower(trim((string)$adminUser['TIPO']))!=='admin') radarReply(403,['ok'=>false,'message'=>'Solo Administración puede gestionar los pagos a Editores.']);
        $percent=(float)$pdo->query('SELECT PORCENTAJE_EDITOR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
        if ($action === 'admin_editor_payments_list') {
            try {
                $editors=$pdo->query("SELECT ID,USUARIO,NOMBRE,APELLIDOS,TELEFONO,CORREO,PROVINCIA,MUNICIPIO,FOTO,TARJETA_PAGO,ESTADO FROM USUARIOS WHERE ES_EDITOR=1 ORDER BY NOMBRE,APELLIDOS,USUARIO")->fetchAll(PDO::FETCH_ASSOC);
                $earnQ=$pdo->prepare("SELECT COALESCE(SUM(m.IMPORTE),0) AS TOTAL_RECARGAS, COALESCE(SUM(m.IMPORTE * COALESCE(m.PORCENTAJE_EDITOR_APLICADO, ?) / 100),0) AS TOTAL_GANANCIAS FROM RADAR_MOVIMIENTOS_SALDO m WHERE LOWER(TRIM(m.TIPO))='recarga' AND EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS ap WHERE ap.TRANSPORTISTA_ID=m.USUARIO_ID AND ap.APROBADO_POR_ID=? AND ap.ACCION='APROBADO' AND m.CREADO_EN>=ap.FECHA_APROBACION AND NOT EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS rv WHERE rv.TRANSPORTISTA_ID=ap.TRANSPORTISTA_ID AND rv.APROBADO_POR_ID=ap.APROBADO_POR_ID AND rv.ACCION='REVOCADO' AND rv.FECHA_APROBACION>=ap.FECHA_APROBACION AND rv.FECHA_APROBACION<=m.CREADO_EN))");
                $paidQ=$pdo->prepare('SELECT COALESCE(SUM(ABS(CAST(IMPORTE AS DECIMAL(12,2)))),0) FROM RADAR_PAGOS_EDITORES WHERE EDITOR_ID=?');
                $totalPending=0.0; $totalEarned=0.0; $totalPaid=0.0;
                foreach($editors as &$editor){
                    $earnQ->execute([$percent,(int)$editor['ID']]); $earnRow=$earnQ->fetch(PDO::FETCH_ASSOC) ?: ['TOTAL_RECARGAS'=>0,'TOTAL_GANANCIAS'=>0]; $recharges=(float)$earnRow['TOTAL_RECARGAS']; $earned=round(max(0,(float)$earnRow['TOTAL_GANANCIAS']),2);
                    $paidQ->execute([(int)$editor['ID']]); $paid=(float)$paidQ->fetchColumn();
                    $pending=round(max(0,$earned-$paid),2);
                    $editor['TOTAL_RECARGAS']=round($recharges,2);
                    $editor['GANANCIAS_GENERADAS']=$earned;
                    // Las ganancias acumuladas disponibles se reducen con cada pago registrado.
                    $editor['GANANCIAS_ACUMULADAS']=$pending;
                    $editor['TOTAL_PAGADO']=round($paid,2);
                    $editor['PENDIENTE_PAGO']=$pending;
                    $editor['PORCENTAJE_EDITOR']=$percent;
                    $editor['TARJETA_CONFIGURADA']=trim((string)($editor['TARJETA_PAGO']??''))!=='';
                    $totalEarned+=$earned; $totalPaid+=$paid; $totalPending+=$pending;
                }
                unset($editor);
                radarReply(200,['ok'=>true,'editores'=>$editors,'resumen'=>['ganancias_generadas'=>round($totalEarned,2),'ganancias_acumuladas'=>round($totalPending,2),'total_pagado'=>round($totalPaid,2),'pendiente_pago'=>round($totalPending,2),'editores'=>count($editors)],'porcentaje_editor'=>$percent]);
            } catch(Throwable $e) {
                error_log('El Radar admin editor payments list error: '.$e->getMessage());
                radarReply(500,['ok'=>false,'message'=>'No se pudo cargar el listado de pagos a Editores.']);
            }
        }

        $editorId=filter_var($data['editor_id']??null,FILTER_VALIDATE_INT);
        $transferId=trim((string)($data['id_transferencia']??''));
        $transferId=preg_replace('/[\x00-\x1F\x7F]/u','',$transferId);
        if (!$editorId || $editorId<1) radarReply(422,['ok'=>false,'message'=>'Selecciona un Editor válido.']);
        if ($transferId==='' || strlen($transferId)>150) radarReply(422,['ok'=>false,'message'=>'Introduce un número de transferencia válido (máximo 150 caracteres).']);
        try {
            $pdo->beginTransaction();
            // Bloquear la cuenta del Editor impide registrar dos pagos simultáneos sobre el mismo saldo pendiente.
            $editorQ=$pdo->prepare('SELECT ID,USUARIO,NOMBRE,APELLIDOS,TARJETA_PAGO,ES_EDITOR FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
            $editorQ->execute([$editorId]); $editor=$editorQ->fetch(PDO::FETCH_ASSOC);
            if (!$editor || (int)$editor['ES_EDITOR']!==1) { $pdo->rollBack(); radarReply(404,['ok'=>false,'message'=>'El usuario seleccionado ya no tiene el permiso de Editor.']); }
            $card=preg_replace('/\D+/','',(string)($editor['TARJETA_PAGO']??''));
            if ($card==='') { $pdo->rollBack(); radarReply(422,['ok'=>false,'message'=>'Este Editor todavía no ha registrado su tarjeta de cobro.']); }
            $earnQ=$pdo->prepare("SELECT COALESCE(SUM(m.IMPORTE * COALESCE(m.PORCENTAJE_EDITOR_APLICADO, ?) / 100),0) FROM RADAR_MOVIMIENTOS_SALDO m WHERE LOWER(TRIM(m.TIPO))='recarga' AND EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS ap WHERE ap.TRANSPORTISTA_ID=m.USUARIO_ID AND ap.APROBADO_POR_ID=? AND ap.ACCION='APROBADO' AND m.CREADO_EN>=ap.FECHA_APROBACION AND NOT EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS rv WHERE rv.TRANSPORTISTA_ID=ap.TRANSPORTISTA_ID AND rv.APROBADO_POR_ID=ap.APROBADO_POR_ID AND rv.ACCION='REVOCADO' AND rv.FECHA_APROBACION>=ap.FECHA_APROBACION AND rv.FECHA_APROBACION<=m.CREADO_EN))");
            $earnQ->execute([$percent,$editorId]); $earned=round(max(0,(float)$earnQ->fetchColumn()),2);
            $paidQ=$pdo->prepare('SELECT COALESCE(SUM(ABS(CAST(IMPORTE AS DECIMAL(12,2)))),0) FROM RADAR_PAGOS_EDITORES WHERE EDITOR_ID=?');
            $paidQ->execute([$editorId]); $paid=(float)$paidQ->fetchColumn();
            $pending=round(max(0,$earned-$paid),2);
            if ($pending<=0) { $pdo->rollBack(); radarReply(409,['ok'=>false,'message'=>'Este Editor no tiene ganancias pendientes de pago.']); }
            $insert=$pdo->prepare('INSERT INTO RADAR_PAGOS_EDITORES (EDITOR_ID,EDITOR_USUARIO,TARJETA_PAGO,IMPORTE,ID_TRANSFERENCIA,ADMIN_ID,ADMIN_USUARIO) VALUES (?,?,?,?,?,?,?)');
            $insert->execute([$editorId,(string)$editor['USUARIO'],$card,number_format($pending,2,'.',''),$transferId,(int)$adminUser['ID'],(string)$adminUser['USUARIO']]);
            $paymentId=(int)$pdo->lastInsertId();
            $pdo->commit();
            // Verificar el total efectivamente guardado para que el saldo mostrado
            // siempre se calcule desde el historial persistente de pagos.
            $verifyPaid=$pdo->prepare('SELECT COALESCE(SUM(ABS(CAST(IMPORTE AS DECIMAL(12,2)))),0) FROM RADAR_PAGOS_EDITORES WHERE EDITOR_ID=?');
            $verifyPaid->execute([$editorId]);
            $paidAfter=(float)$verifyPaid->fetchColumn();
            $pendingAfter=round(max(0,$earned-$paidAfter),2);
            $maskedCard='•••• '.substr($card,-4);
            $displayName=trim((string)($editor['NOMBRE']??'').' '.(string)($editor['APELLIDOS']??''));
            $message="¡Tu pago de Editor ha sido realizado!\nImporte: ".number_format($pending,2,'.',',')." CUP\nID de transferencia: ".$transferId."\nTarjeta destino: ".$maskedCard."\nFecha: ".date('d/m/Y H:i');
            radarNotify($pdo,$editorId,'¡Has recibido el pago de tus ganancias!',$message,'success','editor_salary_paid',$paymentId);
            radarReply(200,['ok'=>true,'message'=>'Pago registrado y notificación enviada al Editor.','pago'=>['id'=>$paymentId,'editor_id'=>$editorId,'importe'=>$pending,'total_pagado'=>$paidAfter,'ganancias_acumuladas'=>$pendingAfter,'id_transferencia'=>$transferId,'tarjeta'=>$maskedCard]]);
        } catch(PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ((string)$e->getCode()==='23000') radarReply(409,['ok'=>false,'message'=>'Ese número de transferencia ya fue registrado en otro pago.']);
            error_log('El Radar editor payment register error: '.$e->getMessage());
            radarReply(500,['ok'=>false,'message'=>'No se pudo registrar el pago del Editor.']);
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar editor payment register error: '.$e->getMessage());
            radarReply(500,['ok'=>false,'message'=>'No se pudo registrar el pago del Editor.']);
        }
    }

    // Tarifas de uso de la aplicación: consulta y modificación exclusivas de administradores.
    if ($action === 'admin_tariffs_get' || $action === 'admin_tariffs_save') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Administración.']);
        $auth = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([(int)$_SESSION['user_id']]);
        $admin = $auth->fetch();
        if (!$admin || strtolower(trim((string)$admin['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para configurar las tarifas.']);
        if ($action === 'admin_tariffs_get') {
            $row = $pdo->query('SELECT CLIENTE_IMPORTE, CLIENTE_PERIODO_CANTIDAD, CLIENTE_PERIODO_UNIDAD, TRANSPORTISTA_IMPORTE, TRANSPORTISTA_PERIODO_CANTIDAD, TRANSPORTISTA_PERIODO_UNIDAD, PRUEBA_DIAS, RECOMPENSA_COMPARTIR, PORCENTAJE_EDITOR, ACTUALIZADO_EN FROM RADAR_TARIFAS_USO WHERE ID=1')->fetch();
            if (!$row) radarReply(500, ['ok'=>false, 'message'=>'No se encontró la configuración de tarifas.']);
            radarReply(200, ['ok'=>true, 'tarifas'=>$row]);
        }
        $clienteImporte = filter_var($data['cliente_importe'] ?? null, FILTER_VALIDATE_FLOAT);
        $clienteCantidad = filter_var($data['cliente_periodo_cantidad'] ?? null, FILTER_VALIDATE_INT);
        $clienteUnidad = strtolower(trim((string)($data['cliente_periodo_unidad'] ?? '')));
        $transportistaImporte = filter_var($data['transportista_importe'] ?? null, FILTER_VALIDATE_FLOAT);
        $transportistaCantidad = filter_var($data['transportista_periodo_cantidad'] ?? null, FILTER_VALIDATE_INT);
        $transportistaUnidad = strtolower(trim((string)($data['transportista_periodo_unidad'] ?? '')));
        $pruebaDias = filter_var($data['prueba_dias'] ?? 0, FILTER_VALIDATE_INT);
        $shareReward = filter_var($data['recompensa_compartir'] ?? null, FILTER_VALIDATE_FLOAT);
        $editorPercent = filter_var($data['porcentaje_editor'] ?? 0, FILTER_VALIDATE_FLOAT);
        $unidades = ['dias','semanas','meses'];
        if ($clienteImporte === false || $clienteImporte < 0 || $clienteCantidad === false || $clienteCantidad < 1 || $clienteCantidad > 3650 || !in_array($clienteUnidad, $unidades, true)
            || $transportistaImporte === false || $transportistaImporte < 0 || $transportistaCantidad === false || $transportistaCantidad < 1 || $transportistaCantidad > 3650 || !in_array($transportistaUnidad, $unidades, true)
            || $pruebaDias === false || $pruebaDias < 0 || $pruebaDias > 3650 || $shareReward === false || $shareReward < 0 || $shareReward > 1000000 || $editorPercent === false || $editorPercent < 0 || $editorPercent > 100) {
            radarReply(422, ['ok'=>false, 'message'=>'Revisa los importes, periodos y días de prueba. Los importes no pueden ser negativos; los periodos deben ser de 1 a 3650 y la prueba de 0 a 3650 días.']);
        }
        $stmt = $pdo->prepare('UPDATE RADAR_TARIFAS_USO SET CLIENTE_IMPORTE=?, CLIENTE_PERIODO_CANTIDAD=?, CLIENTE_PERIODO_UNIDAD=?, TRANSPORTISTA_IMPORTE=?, TRANSPORTISTA_PERIODO_CANTIDAD=?, TRANSPORTISTA_PERIODO_UNIDAD=?, PRUEBA_DIAS=?, RECOMPENSA_COMPARTIR=?, PORCENTAJE_EDITOR=?, ACTUALIZADO_POR=? WHERE ID=1');
        $stmt->execute([$clienteImporte, $clienteCantidad, $clienteUnidad, $transportistaImporte, $transportistaCantidad, $transportistaUnidad, $pruebaDias, $shareReward, $editorPercent, (int)$_SESSION['user_id']]);
        radarReply(200, ['ok'=>true, 'message'=>'Las tarifas de uso se guardaron correctamente.']);
    }

    // Gestión completa de usuarios: solo administradores pueden listar o modificar cuentas.
    if ($action === 'admin_users_list' || $action === 'admin_user_update' || $action === 'admin_user_delete') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Administración.']);
        $auth = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([(int)$_SESSION['user_id']]);
        $admin = $auth->fetch();
        if (!$admin || strtolower(trim((string)$admin['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para gestionar usuarios.']);
        if ($action === 'admin_users_list') {
            try {
                $rows = $pdo->query('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE FROM USUARIOS ORDER BY ID DESC')->fetchAll();
                radarReply(200, ['ok'=>true, 'usuarios'=>array_map('radarUserData', $rows)]);
            } catch (Exception $e) {
                error_log('El Radar admin users list error: '.$e->getMessage());
                radarReply(500, ['ok'=>false, 'message'=>'No se pudo cargar la lista de usuarios.']);
            }
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id || $id < 1) radarReply(422, ['ok'=>false, 'message'=>'El usuario seleccionado no es válido.']);
        if ($action === 'admin_user_delete') {
            if ($id === (int)$_SESSION['user_id']) radarReply(422, ['ok'=>false, 'message'=>'No puedes eliminar tu propia cuenta desde esta pantalla.']);
            try {
                $q = $pdo->prepare('SELECT ID FROM USUARIOS WHERE ID=? LIMIT 1');
                $q->execute([$id]);
                if (!$q->fetch()) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el usuario seleccionado.']);
                $q = $pdo->prepare('DELETE FROM USUARIOS WHERE ID=?');
                $q->execute([$id]);
                radarReply(200, ['ok'=>true, 'message'=>'Usuario eliminado correctamente.']);
            } catch (PDOException $e) {
                error_log('El Radar admin user delete error: '.$e->getMessage());
                radarReply(409, ['ok'=>false, 'message'=>'No se pudo eliminar el usuario. Puede tener registros relacionados que deben conservarse.']);
            }
        }
        $tipo = trim((string)($data['tipo'] ?? ''));
        $password = (string)($data['contrasena'] ?? '');
        $isEditor = !empty($data['es_editor']) ? 1 : 0;
        if (!in_array(strtolower($tipo), ['cliente','transportista','admin'], true)) radarReply(422, ['ok'=>false, 'message'=>'Selecciona un tipo de cuenta válido.']);
        if ($password !== '' && strlen($password) < 8) radarReply(422, ['ok'=>false, 'message'=>'La nueva contraseña debe tener al menos 8 caracteres.']);
        try {
            $q = $pdo->prepare('SELECT ID, TIPO, ES_EDITOR FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([$id]); $target = $q->fetch();
            if (!$target) radarReply(404, ['ok'=>false, 'message'=>'No se encontró el usuario seleccionado.']);
            if ($id === (int)$_SESSION['user_id'] && strtolower($tipo) !== 'admin') radarReply(422, ['ok'=>false, 'message'=>'No puedes quitarte a ti mismo el tipo Administrador desde esta pantalla.']);
            if ($password !== '') {
                $q = $pdo->prepare('UPDATE USUARIOS SET TIPO=?, ES_EDITOR=?, CONTRASENA=? WHERE ID=?');
                $q->execute([$tipo, $isEditor, password_hash($password, PASSWORD_DEFAULT), $id]);
            } else {
                $q = $pdo->prepare('UPDATE USUARIOS SET TIPO=?, ES_EDITOR=? WHERE ID=?');
                $q->execute([$tipo, $isEditor, $id]);
            }
            if (strtolower(trim((string)$target['TIPO'])) !== strtolower($tipo) && strtolower($tipo) !== 'admin') {
                radarRecalculateDaysForType($pdo, $id, $tipo);
            }
            $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([$id]); $updated = $q->fetch();
            radarNotify($pdo, $id, 'Cuenta actualizada', 'La Administración actualizó los datos de tu cuenta.', 'info');
            if ((int)($target['ES_EDITOR'] ?? 0) !== 1 && $isEditor === 1) {
                radarNotify($pdo, $id, 'Completa tu tarjeta de cobro', 'Se ha activado tu permiso de Editor. Para recibir tus pagos, registra el número de la tarjeta en la que deseas recibirlos. No podrás aprobar ni revocar solicitudes de transportista hasta completar este paso.', 'warning', 'editor_payment_card', $id);
            }
            radarReply(200, ['ok'=>true, 'message'=>'Los datos del usuario se actualizaron correctamente.', 'usuario'=>radarUserData($updated)]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') radarReply(409, ['ok'=>false, 'message'=>'El usuario o el carné ya están registrados.']);
            error_log('El Radar admin user update error: '.$e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudieron guardar los cambios del usuario.']);
        }
    }

    // Asignación administrativa de una transferencia pendiente a un usuario y acreditación atómica.
    if ($action === 'admin_assign_confirm_transfer') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Finanzas.']);
        $auth = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $auth->execute([(int)$_SESSION['user_id']]);
        $adminAccount = $auth->fetch();
        if (!$adminAccount || strtolower(trim((string)$adminAccount['TIPO'])) !== 'admin') radarReply(403, ['ok'=>false, 'message'=>'Solo Administración puede asignar transferencias.']);
        $transferRowId = filter_var($data['transfer_id'] ?? null, FILTER_VALIDATE_INT);
        $targetUserId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$transferRowId || $transferRowId < 1 || !$targetUserId || $targetUserId < 1) radarReply(422, ['ok'=>false, 'message'=>'Selecciona una transferencia y un usuario válidos.']);
        try {
            $pdo->beginTransaction();
            $transferQuery = $pdo->prepare("SELECT ID, ID_TRANSFERENCIA, IMPORTE FROM Confirmacion WHERE ID=? AND (USUARIO IS NULL OR TRIM(USUARIO)='') FOR UPDATE");
            $transferQuery->execute([$transferRowId]);
            $transfer = $transferQuery->fetch(PDO::FETCH_ASSOC);
            if (!$transfer) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'La transferencia ya fue asignada o no está disponible. Actualiza Finanzas e inténtalo de nuevo.']); }

            $targetQuery = $pdo->prepare('SELECT ID, USUARIO, TIPO, SALDO, TIEMPO_DISPONIBLE, PAGO_HASTA FROM USUARIOS WHERE ID=? FOR UPDATE');
            $targetQuery->execute([$targetUserId]);
            $target = $targetQuery->fetch(PDO::FETCH_ASSOC);
            if (!$target || strtolower(trim((string)$target['TIPO'])) === 'admin') { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'La cuenta seleccionada no puede recibir recargas. Elige un cliente o transportista.']); }

            $amount = (float)$transfer['IMPORTE'];
            if (!is_finite($amount) || $amount <= 0) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'El importe registrado para esta transferencia no es válido.']); }
            $accountType = mb_strtolower(trim((string)$target['TIPO']), 'UTF-8');
            $tariffs = $pdo->query('SELECT CLIENTE_IMPORTE, TRANSPORTISTA_IMPORTE FROM RADAR_TARIFAS_USO WHERE ID=1')->fetch(PDO::FETCH_ASSOC);
            if (!$tariffs) { $pdo->rollBack(); radarReply(500, ['ok'=>false, 'message'=>'No se encontró la configuración de tarifas de uso.']); }
            $rate = $accountType === 'transportista' ? (float)$tariffs['TRANSPORTISTA_IMPORTE'] : (float)$tariffs['CLIENTE_IMPORTE'];
            if (!is_finite($rate) || $rate <= 0) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'La tarifa configurada para el tipo de cuenta seleccionado no es válida.']); }

            $amountSql = number_format($amount, 2, '.', '');
            $offerQuery = $pdo->prepare("SELECT ID, IMPORTE_RECARGA, PORCENTAJE, TIPO_USUARIO FROM RADAR_OFERTAS_RECARGA WHERE ACTIVO=1 AND CAST(IMPORTE_RECARGA AS DECIMAL(12,2))<=CAST(? AS DECIMAL(12,2)) AND LOWER(TRIM(TIPO_USUARIO)) IN (?, 'todos') AND (FECHA_INICIO IS NULL OR FECHA_INICIO<=NOW()) AND (FECHA_FIN IS NULL OR FECHA_FIN>=NOW()) ORDER BY (CAST(PORCENTAJE AS DECIMAL(7,2))*CAST(? AS DECIMAL(12,2))) DESC, CASE WHEN LOWER(TRIM(TIPO_USUARIO))=? THEN 0 ELSE 1 END, CAST(IMPORTE_RECARGA AS DECIMAL(12,2)) DESC, ID DESC");
            $offerQuery->execute([$amountSql, $accountType, $amountSql, $accountType]);
            $offer = $offerQuery->fetch(PDO::FETCH_ASSOC) ?: null;
            $bonusAmount = $offer ? round($amount * ((float)$offer['PORCENTAJE'] / 100), 2) : 0.00;
            $totalCredit = round($amount + $bonusAmount, 2);
            $daysGranted = (int)floor(($totalCredit / $rate) + 0.0000001);
            if ($daysGranted < 1) { $pdo->rollBack(); radarReply(422, ['ok'=>false, 'message'=>'El importe no alcanza para conceder un día completo según la tarifa de la cuenta seleccionada.']); }

            $now = new DateTimeImmutable('now');
            $expiry = !empty($target['PAGO_HASTA']) ? strtotime((string)$target['PAGO_HASTA']) : false;
            $baseDate = ($expiry !== false && $expiry > time()) ? new DateTimeImmutable((string)$target['PAGO_HASTA']) : $now;
            $newExpiry = $baseDate->modify('+'.$daysGranted.' days')->format('Y-m-d H:i:s');
            $assign = $pdo->prepare("UPDATE Confirmacion SET FECHA_CONFIRMACION=NOW(), USUARIO=? WHERE ID=? AND (USUARIO IS NULL OR TRIM(USUARIO)='')");
            $assign->execute([(string)$target['USUARIO'], (int)$transfer['ID']]);
            if ($assign->rowCount() !== 1) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'La transferencia acaba de ser asignada a otra cuenta.']); }

            $balanceBefore = round((float)$target['SALDO'], 2);
            $balanceAfter = round($balanceBefore + $totalCredit, 2);
            $newAvailableDays = max(0, (int)$target['TIEMPO_DISPONIBLE']) + $daysGranted;
            $credit = $pdo->prepare("UPDATE USUARIOS SET SALDO=?, TIEMPO_DISPONIBLE=?, PAGO_HASTA=?, ESTADO='activo', SALDO_ULTIMO_COBRO=COALESCE(SALDO_ULTIMO_COBRO,NOW()) WHERE ID=?");
            $credit->execute([number_format($balanceAfter,2,'.',''), $newAvailableDays, $newExpiry, (int)$target['ID']]);
            $editorPercentSnapshot = (float)$pdo->query('SELECT PORCENTAJE_EDITOR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
            $movement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA, PORCENTAJE_EDITOR_APLICADO) VALUES (?, 'recarga', ?, ?, ?, ?, ?, ?)");
            $balanceAfterRecharge = round($balanceBefore + $amount, 2);
            $movement->execute([(int)$target['ID'], 'Recarga confirmada por Administración', number_format($amount,2,'.',''), number_format($balanceBefore,2,'.',''), number_format($balanceAfterRecharge,2,'.',''), (string)$transfer['ID_TRANSFERENCIA'], number_format($editorPercentSnapshot,2,'.','')]);
            if ($bonusAmount > 0) {
                $bonusMovement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA) VALUES (?, 'bono_recarga', ?, ?, ?, ?, ?)");
                $bonusMovement->execute([(int)$target['ID'], 'Bono promocional por recarga ('.number_format((float)$offer['PORCENTAJE'],2,'.','').'%)', number_format($bonusAmount,2,'.',''), number_format($balanceAfterRecharge,2,'.',''), number_format($balanceAfter,2,'.',''), (string)$transfer['ID_TRANSFERENCIA']]);
            }
            $pdo->commit();
            $message = 'Tu transferencia '.$transfer['ID_TRANSFERENCIA'].' fue confirmada por Administración. Se acreditaron '.number_format($totalCredit,2,',','.').' CUP y '.$daysGranted.' día(s) de servicio.';
            radarNotify($pdo, (int)$target['ID'], 'Recarga confirmada', $message, 'success', 'recarga_confirmada', (int)$transfer['ID']);
            radarReply(200, ['ok'=>true, 'message'=>'Transferencia asignada y confirmada correctamente.', 'usuario'=>$target['USUARIO'], 'importe'=>$amount, 'bono_recarga'=>$bonusAmount, 'total_acreditado'=>$totalCredit, 'dias_otorgados'=>$daysGranted]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar admin transfer assignment error: '.$e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo asignar la transferencia ni acreditar la cuenta.']);
        }
    }

    // Resumen financiero: ingresos operativos y transferencias confirmadas/pendientes. Solo Admin.
    if ($action === 'admin_finances') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Finanzas.']);
        $q = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $financeAccount = $q->fetch();
        if (!$financeAccount || strtolower(trim((string)$financeAccount['TIPO'])) !== 'admin') {
            radarReply(403, ['ok'=>false, 'message'=>'Solo Administración puede consultar Finanzas.']);
        }
        try {
            $income = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN IMPORTE < 0 THEN -IMPORTE ELSE IMPORTE END),0) FROM RADAR_MOVIMIENTOS_SALDO WHERE TIPO='cobro_diario'")->fetchColumn();
            $confirmed = $pdo->query("SELECT COUNT(*) AS cantidad, COALESCE(SUM(IMPORTE),0) AS importe FROM Confirmacion WHERE USUARIO IS NOT NULL AND TRIM(USUARIO)<>''")->fetch();
            $pending = $pdo->query("SELECT COUNT(*) AS cantidad, COALESCE(SUM(IMPORTE),0) AS importe FROM Confirmacion WHERE USUARIO IS NULL OR TRIM(USUARIO)=''")->fetch();
            $rows = $pdo->query("SELECT ID, ID_TRANSFERENCIA, FECHA, IMPORTE, FECHA_CONFIRMACION, USUARIO, CASE WHEN USUARIO IS NULL OR TRIM(USUARIO)='' THEN 'pendiente' ELSE 'confirmada' END AS ESTADO FROM Confirmacion ORDER BY FECHA DESC, ID DESC LIMIT 100")->fetchAll();
            radarReply(200, ['ok'=>true, 'finanzas'=>[
                'ingresos_app'=>$income,
                'transferencias_confirmadas_cantidad'=>(int)($confirmed['cantidad'] ?? 0),
                'transferencias_confirmadas_importe'=>(float)($confirmed['importe'] ?? 0),
                'transferencias_pendientes_cantidad'=>(int)($pending['cantidad'] ?? 0),
                'transferencias_pendientes_importe'=>(float)($pending['importe'] ?? 0),
                'transferencias'=>$rows
            ], 'actualizado_en'=>gmdate('c')]);
        } catch (Exception $e) {
            error_log('El Radar admin finances error: '.$e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo cargar el resumen financiero.']);
        }
    }

    // Resumen del módulo Administración. La autorización se valida siempre en MySQL.
    if ($action === 'admin_summary') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Administración.']);
        $q = $pdo->prepare('SELECT TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $adminAccount = $q->fetch();
        if (!$adminAccount || strtolower(trim((string)$adminAccount['TIPO'])) !== 'admin') {
            radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para acceder al módulo Administración.']);
        }
        try {
            $counts = $pdo->query("SELECT COUNT(*) AS usuarios, SUM(CASE WHEN LOWER(TRIM(ESTADO))='activo' THEN 1 ELSE 0 END) AS activos, SUM(CASE WHEN LOWER(TRIM(ESTADO))='inactivo' THEN 1 ELSE 0 END) AS inactivos FROM USUARIOS")->fetch();
            $pending = (int)$pdo->query("SELECT COUNT(*) FROM SOLICITUDES_TRANSPORTISTA WHERE LOWER(TRIM(ESTADO_SOLICITUD))='pendiente'")->fetchColumn();
            radarReply(200, ['ok'=>true, 'resumen'=>[
                'usuarios'=>(int)($counts['usuarios'] ?? 0),
                'activos'=>(int)($counts['activos'] ?? 0),
                'inactivos'=>(int)($counts['inactivos'] ?? 0),
                'solicitudes_pendientes'=>$pending
            ], 'actualizado_en'=>gmdate('c')]);
        } catch (Exception $e) {
            error_log('El Radar admin summary error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo cargar el resumen de Administración.']);
        }
    }

    // Lista las solicitudes pendientes o aprobadas para Administración.
    if ($action === 'admin_transport_requests') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Administración.']);
        $q = $pdo->prepare('SELECT ID, USUARIO, TIPO, ES_EDITOR, TARJETA_PAGO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $adminAccount = $q->fetch();
        $canReviewTransportRequests = $adminAccount && (strtolower(trim((string)$adminAccount['TIPO'])) === 'admin' || (int)($adminAccount['ES_EDITOR'] ?? 0) === 1);
        if (!$canReviewTransportRequests) {
            radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para consultar las solicitudes de transportista.']);
        }
        $state = strtolower(trim((string)($data['estado'] ?? 'pendiente')));
        if (!in_array($state, ['pendiente', 'aprobada'], true)) {
            radarReply(422, ['ok'=>false, 'message'=>'El filtro de solicitudes no es válido.']);
        }
        try {
            // Los administradores consultan todas las solicitudes. En la pestaña
            // de aprobadas, cada editor solo ve las autorizaciones que él mismo aprobó.
            $editorOnly = strtolower(trim((string)$adminAccount['TIPO'])) !== 'admin';
            $sql = "SELECT s.ID, s.USUARIO_ID, s.USUARIO, s.NOMBRE, s.APELLIDOS, s.CARNET_IDENTIDAD, s.TIPO_VEHICULO, s.CHAPA, s.LICENCIA_CONDUCCION, s.TELEFONO, s.FOTO_VEHICULO, s.ESTADO_SOLICITUD, s.TIPO_ANTERIOR, s.NOTA_ADMIN, s.CREADA_EN, u.FOTO AS FOTO_USUARIO, u.ESTADO AS ESTADO_USUARIO, u.TIPO AS TIPO_CUENTA FROM SOLICITUDES_TRANSPORTISTA s LEFT JOIN USUARIOS u ON u.ID=s.USUARIO_ID WHERE LOWER(TRIM(s.ESTADO_SOLICITUD))=?";
            $params = [$state];
            if ($editorOnly && $state === 'aprobada') {
                $sql .= " AND EXISTS (SELECT 1 FROM RADAR_AUDITORIA_TRANSPORTISTAS a WHERE a.SOLICITUD_ID=s.ID AND a.APROBADO_POR_ID=? AND a.ACCION='APROBADO')";
                $params[] = (int)$adminAccount['ID'];
            }
            $sql .= " ORDER BY s.CREADA_EN DESC, s.ID DESC";
            $q = $pdo->prepare($sql);
            $q->execute($params);
            $requests = $q->fetchAll();
            radarReply(200, ['ok'=>true, 'estado'=>$state, 'solicitudes'=>$requests, 'total'=>count($requests)]);
        } catch (Exception $e) {
            error_log('El Radar admin transport requests error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudieron cargar las solicitudes de transportista.']);
        }
    }

    // Aprueba, desaprueba o revoca una autorización de transportista. Solo Admin.
    if ($action === 'admin_review_transport_request') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para acceder a Administración.']);
        $q = $pdo->prepare('SELECT ID, USUARIO, TIPO, ES_EDITOR, TARJETA_PAGO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $adminAccount = $q->fetch();
        $canReviewTransportRequests = $adminAccount && (strtolower(trim((string)$adminAccount['TIPO'])) === 'admin' || (int)($adminAccount['ES_EDITOR'] ?? 0) === 1);
        if (!$canReviewTransportRequests) {
            radarReply(403, ['ok'=>false, 'message'=>'No tienes permiso para revisar solicitudes de transportista.']);
        }

        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = strtolower(trim((string)($data['decision'] ?? '')));
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud seleccionada no es válida.']);
        if (!in_array($decision, ['aprobar', 'desaprobar', 'revocar'], true)) radarReply(422, ['ok'=>false, 'message'=>'Selecciona aprobar, desaprobar o revocar.']);
        if (strtolower(trim((string)$adminAccount['TIPO'])) !== 'admin' && in_array($decision, ['aprobar','revocar'], true) && trim((string)($adminAccount['TARJETA_PAGO'] ?? '')) === '') {
            radarReply(403,['ok'=>false,'message'=>'Antes de aprobar o revocar solicitudes, debes registrar el número de la tarjeta donde recibirás tus pagos. Abre la notificación y completa los datos de cobro.']);
        }

        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT ID, USUARIO_ID, ESTADO_SOLICITUD, TIPO_ANTERIOR FROM SOLICITUDES_TRANSPORTISTA WHERE ID=? LIMIT 1 FOR UPDATE');
            $q->execute([$requestId]);
            $request = $q->fetch();
            if (!$request) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'La solicitud ya no existe.']);
            }
            $requestState = strtolower(trim((string)$request['ESTADO_SOLICITUD']));
            // Un editor solo puede revocar una autorización que él mismo aprobó.
            if ($decision === 'revocar' && strtolower(trim((string)$adminAccount['TIPO'])) !== 'admin') {
                $ownerCheck = $pdo->prepare("SELECT COUNT(*) FROM RADAR_AUDITORIA_TRANSPORTISTAS WHERE SOLICITUD_ID=? AND APROBADO_POR_ID=? AND ACCION='APROBADO'");
                $ownerCheck->execute([$requestId, (int)$_SESSION['user_id']]);
                if (!(int)$ownerCheck->fetchColumn()) {
                    $pdo->rollBack();
                    radarReply(403, ['ok'=>false, 'message'=>'Solo puedes revocar transportistas cuya aprobación realizaste tú.']);
                }
            }
            $expectedState = $decision === 'revocar' ? 'aprobada' : 'pendiente';
            if ($requestState !== $expectedState) {
                $pdo->rollBack();
                radarReply(409, ['ok'=>false, 'message'=>$decision === 'revocar' ? 'Esta solicitud ya no está aprobada o ya fue revocada. Actualiza la lista.' : 'Esta solicitud ya fue revisada por otro administrador. Actualiza la lista.']);
            }

            $q = $pdo->prepare('SELECT ID, TIPO FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
            $q->execute([$request['USUARIO_ID']]);
            $targetUser = $q->fetch();
            if (!$targetUser) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'No se encontró la cuenta del solicitante. No se ha cambiado la solicitud.']);
            }

            if ($decision === 'aprobar') {
                $previousType = trim((string)$targetUser['TIPO']);
                if (strtolower($previousType) === 'admin') {
                    $pdo->rollBack();
                    radarReply(409, ['ok'=>false, 'message'=>'No se puede convertir una cuenta de administrador en transportista mediante esta solicitud.']);
                }
                $q = $pdo->prepare("UPDATE USUARIOS SET TIPO='Transportista' WHERE ID=?");
                $q->execute([$request['USUARIO_ID']]);
                if (strtolower($previousType) !== 'transportista') radarRecalculateDaysForType($pdo, (int)$request['USUARIO_ID'], 'Transportista');
                $q = $pdo->prepare('UPDATE SOLICITUDES_TRANSPORTISTA SET ESTADO_SOLICITUD=?, TIPO_ANTERIOR=?, NOTA_ADMIN=? WHERE ID=?');
                $q->execute(['aprobada', ($previousType !== '' && strtolower($previousType) !== 'transportista') ? $previousType : 'cliente', 'Solicitud aprobada por Administración.', $requestId]);
                // Se guarda quién aprobó, a quién y cuándo, dentro de la misma transacción.
                $audit = $pdo->prepare("INSERT INTO RADAR_AUDITORIA_TRANSPORTISTAS
                    (SOLICITUD_ID, TRANSPORTISTA_ID, TRANSPORTISTA_USUARIO, APROBADO_POR_ID, APROBADO_POR_USUARIO, FECHA_APROBACION, ACCION)
                    VALUES (?, ?, ?, ?, ?, NOW(), 'APROBADO')");
                $audit->execute([
                    (int)$request['ID'],
                    (int)$request['USUARIO_ID'],
                    (string)$request['USUARIO'],
                    (int)$adminAccount['ID'],
                    (string)$adminAccount['USUARIO']
                ]);
                $newStatus = 'aprobada';
                $message = 'Solicitud aprobada. La cuenta del usuario ahora es Transportista.';
                radarNotify($pdo, (int)$request['USUARIO_ID'], 'Solicitud de transportista aprobada', 'La Administración aprobó tu solicitud. Tu cuenta ahora es de Transportista.', 'success');
            } elseif ($decision === 'desaprobar') {
                $q = $pdo->prepare('UPDATE SOLICITUDES_TRANSPORTISTA SET ESTADO_SOLICITUD=?, NOTA_ADMIN=? WHERE ID=?');
                $q->execute(['rechazada', 'Solicitud desaprobada por Administración.', $requestId]);
                $newStatus = 'rechazada';
                $message = 'Solicitud desaprobada. La cuenta del usuario no se ha cambiado.';
                radarNotify($pdo, (int)$request['USUARIO_ID'], 'Solicitud de transportista desaprobada', 'La Administración desaprobó tu solicitud. Puedes revisar los datos y enviar una nueva solicitud.', 'warning');
            } else {
                $currentType = strtolower(trim((string)$targetUser['TIPO']));
                if ($currentType === 'admin') {
                    $pdo->rollBack();
                    radarReply(409, ['ok'=>false, 'message'=>'La cuenta ahora es Admin; por seguridad no se modificó el tipo de cuenta ni la solicitud.']);
                }
                $restoreType = trim((string)($request['TIPO_ANTERIOR'] ?? ''));
                if ($restoreType === '' || strtolower($restoreType) === 'transportista' || strtolower($restoreType) === 'admin') $restoreType = 'cliente';
                $q = $pdo->prepare('UPDATE USUARIOS SET TIPO=? WHERE ID=?');
                $q->execute([$restoreType, $request['USUARIO_ID']]);
                if ($currentType !== strtolower($restoreType)) radarRecalculateDaysForType($pdo, (int)$request['USUARIO_ID'], $restoreType);
                $q = $pdo->prepare('UPDATE SOLICITUDES_TRANSPORTISTA SET ESTADO_SOLICITUD=?, NOTA_ADMIN=? WHERE ID=?');
                $q->execute(['revocada', 'Autorización de transportista revocada por Administración.', $requestId]);
                $audit = $pdo->prepare("INSERT INTO RADAR_AUDITORIA_TRANSPORTISTAS (SOLICITUD_ID,TRANSPORTISTA_ID,TRANSPORTISTA_USUARIO,APROBADO_POR_ID,APROBADO_POR_USUARIO,FECHA_APROBACION,ACCION) VALUES (?,?,?,?,?,NOW(),'REVOCADO')");
                $nameQ=$pdo->prepare('SELECT USUARIO FROM USUARIOS WHERE ID=?'); $nameQ->execute([(int)$request['USUARIO_ID']]);
                $audit->execute([(int)$request['ID'],(int)$request['USUARIO_ID'],(string)$nameQ->fetchColumn(),(int)$adminAccount['ID'],(string)$adminAccount['USUARIO']]);
                $newStatus = 'revocada';
                $message = 'Autorización revocada. El tipo de cuenta se cambió a ' . $restoreType . '.';
                radarNotify($pdo, (int)$request['USUARIO_ID'], 'Autorización de transportista revocada', 'La Administración revocó tu autorización de transportista. Tu tipo de cuenta ahora es ' . $restoreType . '.', 'warning');
            }

            radarNotify($pdo, (int)$_SESSION['user_id'], 'Gestión de solicitud completada', 'Has ' . ($decision === 'aprobar' ? 'aprobado' : ($decision === 'desaprobar' ? 'desaprobado' : 'revocado')) . ' la solicitud de transportista #' . (int)$requestId . '.', 'success');
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'message'=>$message, 'solicitud_id'=>(int)$requestId, 'estado_solicitud'=>$newStatus]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar review transport request error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo guardar la decisión. Inténtalo de nuevo.']);
        }
    }

    // Registra una solicitud de cuenta transportista con datos del vehículo.
    // La identidad se toma de la sesión; el cliente no puede elegir un ID de usuario ni aprobarse a sí mismo.
    if ($action === 'request_transport_account') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para solicitar una cuenta de transportista.']);

        $tipoVehiculo = trim((string)($data['tipo_vehiculo'] ?? ''));
        $chapa = strtoupper(trim((string)($data['chapa'] ?? '')));
        $licencia = trim((string)($data['licencia_conduccion'] ?? ''));
        $telefono = trim((string)($data['telefono'] ?? ''));
        $fotoVehiculo = radarPhoto($data['foto_vehiculo'] ?? '');

        if ($tipoVehiculo === '' || mb_strlen($tipoVehiculo, 'UTF-8') > 60) {
            radarReply(422, ['ok'=>false, 'message'=>'Selecciona el tipo de vehículo.']);
        }
        $validVehicleType = $pdo->prepare('SELECT ID FROM RADAR_TIPOS_TRANSPORTE WHERE NOMBRE=? AND ACTIVO=1 LIMIT 1');
        $validVehicleType->execute([$tipoVehiculo]);
        if (!$validVehicleType->fetch()) radarReply(422, ['ok'=>false, 'message'=>'Selecciona un tipo de transporte disponible en la lista.']);
        if ($chapa === '' || mb_strlen($chapa, 'UTF-8') > 30 || !preg_match('/^[A-Z0-9 ._-]+$/u', $chapa)) {
            radarReply(422, ['ok'=>false, 'message'=>'Introduce una chapa válida (letras y números).']);
        }
        if ($licencia === '' || mb_strlen($licencia, 'UTF-8') > 60) {
            radarReply(422, ['ok'=>false, 'message'=>'Introduce el número de licencia de conducción.']);
        }
        if ($telefono === '' || mb_strlen($telefono, 'UTF-8') > 30 || !preg_match('/^[0-9+() .-]{7,30}$/', $telefono)) {
            radarReply(422, ['ok'=>false, 'message'=>'Introduce un teléfono válido.']);
        }
        if (!$fotoVehiculo) {
            radarReply(422, ['ok'=>false, 'message'=>'Debes adjuntar una foto del vehículo.']);
        }

        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
            $q->execute([$_SESSION['user_id']]);
            $account = $q->fetch();
            if (!$account) {
                $pdo->rollBack();
                radarReply(401, ['ok'=>false, 'message'=>'La cuenta no existe o la sesión terminó.']);
            }
            if (strtolower(trim((string)$account['TIPO'])) === 'transportista') {
                $pdo->commit();
                radarReply(200, ['ok'=>true, 'message'=>'Tu cuenta ya es de transportista.', 'estado_solicitud'=>'transportista', 'tipo'=>'transportista']);
            }
            $q = $pdo->prepare("SELECT ID FROM SOLICITUDES_TRANSPORTISTA WHERE USUARIO_ID=? AND LOWER(TRIM(ESTADO_SOLICITUD))='pendiente' ORDER BY ID DESC LIMIT 1");
            $q->execute([$_SESSION['user_id']]);
            if ($q->fetch()) {
                $pdo->commit();
                radarReply(200, ['ok'=>true, 'message'=>'Ya tienes una solicitud de cuenta transportista pendiente de revisión.', 'estado_solicitud'=>'pendiente', 'tipo'=>strtolower(trim((string)$account['TIPO']))]);
            }
            $q = $pdo->prepare("INSERT INTO SOLICITUDES_TRANSPORTISTA (USUARIO_ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TIPO_VEHICULO, CHAPA, LICENCIA_CONDUCCION, TELEFONO, FOTO_VEHICULO, ESTADO_SOLICITUD) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
            $q->execute([
                (int)$account['ID'],
                (string)$account['USUARIO'],
                (string)($account['NOMBRE'] ?? ''),
                (string)($account['APELLIDOS'] ?? ''),
                isset($account['CARNET_IDENTIDAD']) ? (string)$account['CARNET_IDENTIDAD'] : null,
                $tipoVehiculo,
                $chapa,
                $licencia,
                $telefono,
                $fotoVehiculo
            ]);
            // Confirmar primero la solicitud. Los avisos son secundarios: si falla una
            // notificación o la consulta de administradores, no debe perderse la solicitud.
            $pdo->commit();

            try {
                radarNotify($pdo, (int)$account['ID'], 'Solicitud enviada', 'Tu solicitud de cuenta transportista quedó pendiente de revisión.', 'success');
                $adminIds = $pdo->query("SELECT ID FROM USUARIOS WHERE LOWER(TRIM(TIPO))='admin'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($adminIds as $adminId) {
                    radarNotify($pdo, (int)$adminId, 'Nueva solicitud de transportista', 'El usuario ' . (string)$account['USUARIO'] . ' envió una solicitud que requiere revisión.', 'warning');
                }
            } catch (Exception $notificationError) {
                error_log('El Radar transporter request notification error: ' . $notificationError->getMessage());
            }

            radarReply(201, ['ok'=>true, 'message'=>'Tu solicitud con los datos del vehículo se ha registrado correctamente y queda pendiente de revisión.', 'estado_solicitud'=>'pendiente', 'tipo'=>strtolower(trim((string)$account['TIPO']))]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar transporter request error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo registrar la solicitud. Verifica los datos y vuelve a intentarlo. Si el problema continúa, revisa el registro de errores PHP de InfinityFree.']);
        }
    }

    // Registra una solicitud de viaje y notifica al transportista elegido.
    if ($action === 'create_trip_request') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para solicitar un viaje.']);
        $clientId = (int)$_SESSION['user_id'];
        $transporterId = filter_var($data['transportista_id'] ?? null, FILTER_VALIDATE_INT);
        $destination = trim((string)($data['destino'] ?? ''));
        $people = filter_var($data['personas'] ?? null, FILTER_VALIDATE_INT);
        $when = trim((string)($data['fecha_hora'] ?? ''));
        $notes = trim((string)($data['observaciones'] ?? ''));
        if (!$transporterId || $transporterId < 1) radarReply(422, ['ok'=>false, 'message'=>'No se pudo identificar al transportista.']);
        if ($destination === '' || mb_strlen($destination, 'UTF-8') > 500) radarReply(422, ['ok'=>false, 'message'=>'Escribe un destino válido (máximo 500 caracteres).']);
        if (!$people || $people < 1 || $people > 20) radarReply(422, ['ok'=>false, 'message'=>'La cantidad de personas debe estar entre 1 y 20.']);
        if (mb_strlen($when, 'UTF-8') > 40 || mb_strlen($notes, 'UTF-8') > 1000) radarReply(422, ['ok'=>false, 'message'=>'La fecha o las observaciones superan el límite permitido.']);
        $lat = isset($data['origen_lat']) && is_numeric($data['origen_lat']) ? (float)$data['origen_lat'] : null;
        $lng = isset($data['origen_lng']) && is_numeric($data['origen_lng']) ? (float)$data['origen_lng'] : null;
        if ($lat !== null && (!is_finite($lat) || $lat < -90 || $lat > 90)) $lat = null;
        if ($lng !== null && (!is_finite($lng) || $lng < -180 || $lng > 180)) $lng = null;
        $originAddress = trim((string)($data['origen_direccion'] ?? ''));
        $originAddress = mb_substr($originAddress, 0, 500, 'UTF-8');
        try {
            $q = $pdo->prepare('SELECT ID, TIPO, ESTADO, NOMBRE, APELLIDOS FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([$clientId]);
            $client = $q->fetch();
            if (!$client || strtolower(trim((string)$client['ESTADO'])) !== 'activo') radarReply(403, ['ok'=>false, 'message'=>'Tu cuenta debe estar activa para solicitar un viaje.']);
            if (strtolower(trim((string)$client['TIPO'])) === 'transportista') radarReply(403, ['ok'=>false, 'message'=>'Solo una cuenta de cliente puede solicitar un viaje.']);
            $q = $pdo->prepare("SELECT ID FROM USUARIOS WHERE ID=? AND LOWER(TRIM(TIPO))='transportista' AND LOWER(TRIM(ESTADO))='activo' LIMIT 1");
            $q->execute([$transporterId]);
            if (!$q->fetch()) radarReply(409, ['ok'=>false, 'message'=>'Este transportista ya no está activo. Actualiza el mapa e inténtalo con otro.']);
            $q = $pdo->prepare('INSERT INTO SOLICITUDES_VIAJE (CLIENTE_ID, TRANSPORTISTA_ID, DESTINO, PERSONAS, FECHA_HORA, OBSERVACIONES, ORIGEN_LAT, ORIGEN_LNG, ORIGEN_DIRECCION) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $q->execute([$clientId, $transporterId, $destination, $people, $when !== '' ? $when : null, $notes !== '' ? $notes : null, $lat, $lng, $originAddress !== '' ? $originAddress : null]);
            $requestId = (int)$pdo->lastInsertId();
            $clientName = trim((string)($client['NOMBRE'] ?? '') . ' ' . (string)($client['APELLIDOS'] ?? ''));
            if ($clientName === '') $clientName = 'Un cliente';
            $message = $clientName . ' solicita un viaje. Destino: ' . $destination . '. Personas: ' . $people . '.';
            if ($when !== '') $message .= ' Fecha/hora: ' . $when . '.';
            if ($originAddress !== '') $message .= ' Recogida: ' . $originAddress . '.';
            if ($notes !== '') $message .= ' Observaciones: ' . $notes;
            radarNotify($pdo, $transporterId, 'Nueva solicitud de viaje', $message, 'warning', 'solicitud_viaje', $requestId);
            radarNotify($pdo, $clientId, 'Solicitud de viaje enviada', 'Tu solicitud #' . $requestId . ' fue enviada al transportista. Espera su respuesta.', 'success');
            radarReply(200, ['ok'=>true, 'solicitud_id'=>$requestId, 'message'=>'Solicitud enviada al transportista correctamente.']);
        } catch (Exception $e) {
            error_log('El Radar create trip request error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo enviar la solicitud de viaje. Inténtalo nuevamente.']);
        }
    }

    // Consultar los datos de una solicitud recibida por el transportista asignado.
    if ($action === 'trip_request_details') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar la solicitud.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud no es válida.']);
        $q = $pdo->prepare("SELECT s.ID, s.DESTINO, s.PERSONAS, s.OBSERVACIONES, s.ORIGEN_DIRECCION, s.ESTADO, s.PRECIO, u.NOMBRE, u.APELLIDOS FROM SOLICITUDES_VIAJE s JOIN USUARIOS u ON u.ID=s.CLIENTE_ID WHERE s.ID=? AND s.TRANSPORTISTA_ID=? LIMIT 1");
        $q->execute([$requestId, (int)$_SESSION['user_id']]);
        $request = $q->fetch(PDO::FETCH_ASSOC);
        if (!$request) radarReply(404, ['ok'=>false, 'message'=>'No se encontró esta solicitud.']);
        radarReply(200, ['ok'=>true, 'solicitud'=>$request]);
    }

    // El transportista responde con el precio propuesto; se notifica al cliente.
    if ($action === 'trip_request_respond') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para responder.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        $priceRaw = trim((string)($data['precio'] ?? ''));
        $price = is_numeric($priceRaw) ? (float)$priceRaw : -1;
        if (!$requestId || $requestId < 1 || !is_finite($price) || $price <= 0 || $price > 999999999) radarReply(422, ['ok'=>false, 'message'=>'Introduce un precio válido mayor que cero.']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT ID, CLIENTE_ID, DESTINO FROM SOLICITUDES_VIAJE WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='pendiente' LIMIT 1 FOR UPDATE");
            $q->execute([$requestId, (int)$_SESSION['user_id']]);
            $request = $q->fetch(PDO::FETCH_ASSOC);
            if (!$request) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'Esta solicitud ya fue atendida o no te pertenece.']); }
            $upd = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO='respondida', PRECIO=? WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='pendiente'");
            $upd->execute([number_format($price, 2, '.', ''), $requestId, (int)$_SESSION['user_id']]);
            if ($upd->rowCount() !== 1) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'No se pudo responder porque la solicitud ya cambió de estado.']); }
            radarNotify($pdo, (int)$request['CLIENTE_ID'], 'Precio del viaje recibido', 'El transportista respondió tu solicitud #' . $requestId . '. Destino: ' . $request['DESTINO'] . '. Precio propuesto: ' . number_format($price, 2, '.', ''), 'success', 'solicitud_viaje', $requestId);
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'message'=>'Precio enviado al cliente correctamente.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar trip request respond error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo enviar el precio. Inténtalo nuevamente.']);
        }
    }

    // El cliente acepta o rechaza el precio propuesto; se informa al transportista.
    if ($action === 'trip_request_client_decision') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para responder al precio.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        $decision = strtolower(trim((string)($data['decision'] ?? '')));
        if (!$requestId || $requestId < 1 || !in_array($decision, ['aceptar', 'rechazar'], true)) radarReply(422, ['ok'=>false, 'message'=>'La respuesta seleccionada no es válida.']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT ID, CLIENTE_ID, TRANSPORTISTA_ID, DESTINO, PRECIO FROM SOLICITUDES_VIAJE WHERE ID=? AND CLIENTE_ID=? AND ESTADO='respondida' LIMIT 1 FOR UPDATE");
            $q->execute([$requestId, (int)$_SESSION['user_id']]);
            $request = $q->fetch(PDO::FETCH_ASSOC);
            if (!$request) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'Este precio ya fue contestado o la solicitud no te pertenece.']); }
            $newStatus = $decision === 'aceptar' ? 'aceptada' : 'rechazada_cliente';
            $upd = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO=? WHERE ID=? AND CLIENTE_ID=? AND ESTADO='respondida'");
            $upd->execute([$newStatus, $requestId, (int)$_SESSION['user_id']]);
            if ($upd->rowCount() !== 1) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'No se pudo registrar la respuesta porque el precio ya fue contestado.']); }
            $title = $decision === 'aceptar' ? 'Cliente aceptó el precio' : 'Cliente rechazó el precio';
            $message = $decision === 'aceptar'
                ? 'El cliente aceptó el precio de ' . number_format((float)$request['PRECIO'], 2, '.', '') . ' para el viaje #' . $requestId . '. Destino: ' . $request['DESTINO'] . '.'
                : 'El cliente rechazó el precio propuesto para el viaje #' . $requestId . '. Destino: ' . $request['DESTINO'] . '.';
            radarNotify($pdo, (int)$request['TRANSPORTISTA_ID'], $title, $message, $decision === 'aceptar' ? 'success' : 'warning', 'solicitud_viaje', $requestId);
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'message'=>$decision === 'aceptar' ? 'Has aceptado el precio. Se notificó al transportista.' : 'Has rechazado el precio. Se notificó al transportista.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar trip request client decision error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo enviar tu respuesta. Inténtalo nuevamente.']);
        }
    }

    // Comprueba la llegada real del transportista al punto de recogida (< 5 metros).
    // El estado y la notificación se actualizan una sola vez bajo bloqueo transaccional.
    if ($action === 'trip_arrival_check') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para comprobar la llegada.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        $latValue = $data['lat'] ?? null;
        $lngValue = $data['lng'] ?? null;
        if (!$requestId || $requestId < 1 || !is_numeric($latValue) || !is_numeric($lngValue)) radarReply(422, ['ok'=>false, 'message'=>'No se recibieron coordenadas válidas.']);
        $tLat = (float)$latValue; $tLng = (float)$lngValue;
        if (!is_finite($tLat) || !is_finite($tLng) || $tLat < -90 || $tLat > 90 || $tLng < -180 || $tLng > 180) radarReply(422, ['ok'=>false, 'message'=>'Las coordenadas GPS están fuera del rango permitido.']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT ID, CLIENTE_ID, ESTADO, ORIGEN_LAT, ORIGEN_LNG FROM SOLICITUDES_VIAJE WHERE ID=? AND TRANSPORTISTA_ID=? LIMIT 1 FOR UPDATE");
            $q->execute([$requestId, (int)$_SESSION['user_id']]);
            $trip = $q->fetch(PDO::FETCH_ASSOC);
            if (!$trip) { $pdo->rollBack(); radarReply(404, ['ok'=>false, 'message'=>'No se encontró el viaje o no te pertenece.']); }
            if (strtolower((string)$trip['ESTADO']) === 'llegado') {
                $pdo->commit();
                radarReply(200, ['ok'=>true, 'llegado'=>true, 'distancia_m'=>0, 'message'=>'La llegada ya había sido registrada.']);
            }
            if (strtolower((string)$trip['ESTADO']) !== 'en_curso') {
                $pdo->rollBack();
                radarReply(409, ['ok'=>false, 'message'=>'El viaje no está en curso.']);
            }
            if (!is_numeric($trip['ORIGEN_LAT']) || !is_numeric($trip['ORIGEN_LNG'])) {
                $pdo->commit();
                radarReply(200, ['ok'=>true, 'llegado'=>false, 'distancia_m'=>null, 'message'=>'El cliente no compartió coordenadas de recogida.']);
            }
            $cLat = (float)$trip['ORIGEN_LAT']; $cLng = (float)$trip['ORIGEN_LNG'];
            $rad = static function($v) { return $v * M_PI / 180; };
            $dLat = $rad($cLat - $tLat); $dLng = $rad($cLng - $tLng);
            $a = sin($dLat/2) * sin($dLat/2) + cos($rad($tLat)) * cos($rad($cLat)) * sin($dLng/2) * sin($dLng/2);
            $distance = 6371000 * 2 * atan2(sqrt(max(0, $a)), sqrt(max(0, 1-$a)));
            $radiusQuery = $pdo->prepare('SELECT RADIO_LLEGADA_M FROM USUARIOS WHERE ID=? LIMIT 1');
            $radiusQuery->execute([(int)$trip['CLIENTE_ID']]);
            $arrivalRadius = max(5, min(20, (int)$radiusQuery->fetchColumn() ?: 10));
            // La regla del cliente se aplica a metros enteros: redondear primero la distancia GPS.
            $roundedDistance = (int)round($distance);
            if ($roundedDistance <= $arrivalRadius) {
                $upd = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO='llegado' WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='en_curso'");
                $upd->execute([$requestId, (int)$_SESSION['user_id']]);
                if ($upd->rowCount() === 1) {
                    radarNotify($pdo, (int)$trip['CLIENTE_ID'], 'Tu transporte ha llegado', 'El transportista llegó al punto de recogida del viaje #' . $requestId . '. Tu transporte ya está esperándote.', 'success', 'solicitud_viaje', $requestId);
                }
                $pdo->commit();
                radarReply(200, ['ok'=>true, 'llegado'=>true, 'distancia_m'=>$roundedDistance, 'radio_m'=>$arrivalRadius, 'message'=>'Llegada confirmada. Se notificó al cliente.']);
            }
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'llegado'=>false, 'distancia_m'=>$roundedDistance, 'radio_m'=>$arrivalRadius, 'message'=>'El transportista todavía está en camino.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar trip arrival check error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo comprobar la llegada.']);
        }
    }

    // El transportista inicia un viaje aceptado y obtiene ambas ubicaciones para navegar hasta el cliente.
    if ($action === 'trip_start_details') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para iniciar el viaje.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud no es válida.']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT s.ID, s.CLIENTE_ID, s.TRANSPORTISTA_ID, s.ESTADO, s.DESTINO, s.PERSONAS, s.PRECIO, s.ORIGEN_LAT, s.ORIGEN_LNG, s.ORIGEN_DIRECCION, c.NOMBRE AS CLIENTE_NOMBRE, c.APELLIDOS AS CLIENTE_APELLIDOS, c.FOTO AS CLIENTE_FOTO, t.UBICACION AS TRANSPORTISTA_UBICACION, (SELECT st.FOTO_VEHICULO FROM SOLICITUDES_TRANSPORTISTA st WHERE st.USUARIO_ID=s.TRANSPORTISTA_ID ORDER BY st.ID DESC LIMIT 1) AS TRANSPORTISTA_FOTO_VEHICULO FROM SOLICITUDES_VIAJE s JOIN USUARIOS c ON c.ID=s.CLIENTE_ID JOIN USUARIOS t ON t.ID=s.TRANSPORTISTA_ID WHERE s.ID=? AND s.TRANSPORTISTA_ID=? AND s.ESTADO IN ('aceptada','en_curso','llegado') LIMIT 1 FOR UPDATE");
            $q->execute([$requestId, (int)$_SESSION['user_id']]);
            $r = $q->fetch(PDO::FETCH_ASSOC);
            if (!$r) { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'Este viaje no está aceptado o no te pertenece.']); }
            if ($r['ESTADO'] ?? false) { /* Estado se valida en la consulta. */ }
            $upd = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO='en_curso' WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='aceptada'");
            $upd->execute([$requestId, (int)$_SESSION['user_id']]);
            if ($upd->rowCount() === 1) {
                radarNotify($pdo, (int)$r['CLIENTE_ID'], 'Viaje iniciado', 'El transportista inició el viaje #' . $requestId . ' y se dirige a tu punto de recogida.', 'success', 'solicitud_viaje', $requestId);
            }
            $raw = trim((string)($r['TRANSPORTISTA_UBICACION'] ?? ''));
            $loc = $raw !== '' ? json_decode($raw, true) : null;
            if (!is_array($loc) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)\s*$/', $raw, $m)) $loc = ['lat'=>$m[1], 'lng'=>$m[2]];
            $normalized = [];
            if (is_array($loc)) foreach ($loc as $k=>$v) $normalized[strtolower((string)$k)] = $v;
            if (isset($normalized['coordinates']) && is_array($normalized['coordinates']) && count($normalized['coordinates']) >= 2) { $normalized['lng']=$normalized['coordinates'][0]; $normalized['lat']=$normalized['coordinates'][1]; }
            $tLat = $normalized['lat'] ?? ($normalized['latitude'] ?? ($normalized['latitud'] ?? null));
            $tLng = $normalized['lng'] ?? ($normalized['lon'] ?? ($normalized['long'] ?? ($normalized['longitude'] ?? ($normalized['longitud'] ?? null))));
            $tLat = is_numeric($tLat) ? (float)$tLat : null; $tLng = is_numeric($tLng) ? (float)$tLng : null;
            if ($tLat !== null && ($tLat < -90 || $tLat > 90 || !is_finite($tLat))) $tLat = null;
            if ($tLng !== null && ($tLng < -180 || $tLng > 180 || !is_finite($tLng))) $tLng = null;
            $cLat = is_numeric($r['ORIGEN_LAT']) ? (float)$r['ORIGEN_LAT'] : null;
            $cLng = is_numeric($r['ORIGEN_LNG']) ? (float)$r['ORIGEN_LNG'] : null;
            if ($cLat !== null && ($cLat < -90 || $cLat > 90 || !is_finite($cLat))) $cLat = null;
            if ($cLng !== null && ($cLng < -180 || $cLng > 180 || !is_finite($cLng))) $cLng = null;
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'viaje'=>['id'=>(int)$r['ID'], 'cliente'=>trim((string)$r['CLIENTE_NOMBRE'].' '.(string)$r['CLIENTE_APELLIDOS']), 'destino'=>(string)$r['DESTINO'], 'personas'=>(int)$r['PERSONAS'], 'precio'=>$r['PRECIO'], 'recogida'=>(string)($r['ORIGEN_DIRECCION'] ?? ''), 'cliente_foto'=>$r['CLIENTE_FOTO'] ?? null, 'transportista_foto_vehiculo'=>$r['TRANSPORTISTA_FOTO_VEHICULO'] ?? null, 'estado'=>(string)$r['ESTADO'], 'cliente_lat'=>$cLat, 'cliente_lng'=>$cLng, 'transportista_lat'=>$tLat, 'transportista_lng'=>$tLng]]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar trip start error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo iniciar el viaje. Inténtalo nuevamente.']);
        }
    }

    // Rechazar la solicitud y avisar al cliente.
    if ($action === 'trip_request_reject') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para rechazar.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud no es válida.']);
        $q = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO='rechazada' WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='pendiente'");
        $q->execute([$requestId, (int)$_SESSION['user_id']]);
        if ($q->rowCount() !== 1) radarReply(409, ['ok'=>false, 'message'=>'Esta solicitud ya fue atendida o no te pertenece.']);
        $q = $pdo->prepare('SELECT CLIENTE_ID, DESTINO FROM SOLICITUDES_VIAJE WHERE ID=? LIMIT 1');
        $q->execute([$requestId]);
        $request = $q->fetch(PDO::FETCH_ASSOC);
        if ($request) radarNotify($pdo, (int)$request['CLIENTE_ID'], 'Solicitud de viaje rechazada', 'El transportista rechazó tu solicitud #' . $requestId . '. Destino: ' . $request['DESTINO'] . '.', 'error', 'solicitud_viaje', $requestId);
        radarReply(200, ['ok'=>true, 'message'=>'Solicitud rechazada. Se notificó al cliente.']);
    }

    // En el perfil solo se permite modificar teléfono, correo, provincia y municipio.
    if ($action === 'update_profile') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Debes iniciar sesión para modificar tu perfil.']);
        $userId = (int)$_SESSION['user_id'];
        $telefono = trim((string)($data['telefono'] ?? ''));
        $correo = mb_strtolower(trim((string)($data['correo'] ?? '')), 'UTF-8');
        $provincia = trim((string)($data['provincia'] ?? ''));
        $municipio = trim((string)($data['municipio'] ?? ''));
        if (!preg_match('/^\+?[0-9][0-9\s().-]{6,18}[0-9]$/', $telefono) || strlen(preg_replace('/\D/', '', $telefono)) < 8 || strlen(preg_replace('/\D/', '', $telefono)) > 13) {
            radarReply(422, ['ok'=>false, 'message'=>'Introduce un número de teléfono válido.']);
        }
        if (strlen($correo) > 190 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) radarReply(422, ['ok'=>false, 'message'=>'Introduce un correo electrónico válido.']);
        $provinciasCuba = ['Pinar del Río','Artemisa','La Habana','Mayabeque','Matanzas','Cienfuegos','Villa Clara','Sancti Spíritus','Ciego de Ávila','Camagüey','Las Tunas','Holguín','Granma','Santiago de Cuba','Guantánamo','Isla de la Juventud'];
        if (!in_array($provincia, $provinciasCuba, true)) radarReply(422, ['ok'=>false, 'message'=>'Selecciona una provincia válida de Cuba.']);
        if ($municipio === '' || mb_strlen($municipio, 'UTF-8') > 100) radarReply(422, ['ok'=>false, 'message'=>'Selecciona un municipio válido.']);
        $dup = $pdo->prepare('SELECT ID FROM USUARIOS WHERE LOWER(CORREO)=LOWER(?) AND ID<>? LIMIT 1');
        $dup->execute([$correo, $userId]);
        if ($dup->fetchColumn()) radarReply(409, ['ok'=>false, 'message'=>'Ese correo electrónico ya está asociado a otra cuenta.']);
        try {
            $q = $pdo->prepare('UPDATE USUARIOS SET TELEFONO=?, CORREO=?, PROVINCIA=?, MUNICIPIO=? WHERE ID=?');
            $q->execute([$telefono, $correo, $provincia, $municipio, $userId]);
            $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([$userId]);
            $user = $q->fetch();
            if (!$user) radarReply(404, ['ok'=>false, 'message'=>'No se encontró tu cuenta.']);
            radarReply(200, ['ok'=>true, 'message'=>'Tus datos de contacto y residencia se actualizaron correctamente.', 'user'=>radarUserData($user)]);
        } catch (Exception $e) {
            error_log('El Radar profile update error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudieron guardar los cambios del perfil.']);
        }
    }

    // Cambio del estado operativo de la sesión, separado de la edición de datos personales del perfil.
    if ($action === 'update_status') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Debes iniciar sesión para modificar el estado.']);
        $status = strtolower(trim((string)(isset($data['estado']) ? $data['estado'] : '')));
        if (!in_array($status, ['activo', 'inactivo'], true)) {
            radarReply(422, ['ok'=>false, 'message'=>'El estado solo puede ser activo o inactivo.']);
        }
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT ID FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
            $q->execute([$_SESSION['user_id']]);
            if (!$q->fetch()) {
                $pdo->rollBack();
                radarReply(404, ['ok'=>false, 'message'=>'No se encontró la cuenta del usuario.']);
            }
            $q = $pdo->prepare('UPDATE USUARIOS SET ESTADO=? WHERE ID=?');
            $q->execute([$status, $_SESSION['user_id']]);
            $pdo->commit();
            radarNotify($pdo, (int)$_SESSION['user_id'], 'Estado de cuenta actualizado', 'Tu cuenta ahora está ' . ($status === 'activo' ? 'Activa' : 'Inactiva') . '.', 'success');
            $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([$_SESSION['user_id']]);
            $user = $q->fetch();
            radarReply(200, ['ok'=>true, 'message'=>'Estado actualizado correctamente.', 'user'=>radarUserData($user)]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar status update error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo guardar el estado del usuario.']);
        }
    }

    // El transportista finaliza el viaje después de completar el recorrido con el cliente.
    if ($action === 'trip_finish') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para finalizar el viaje.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud no es válida.']);
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("SELECT ID, CLIENTE_ID, ESTADO FROM SOLICITUDES_VIAJE WHERE ID=? AND TRANSPORTISTA_ID=? LIMIT 1 FOR UPDATE");
            $q->execute([$requestId, (int)$_SESSION['user_id']]);
            $trip = $q->fetch(PDO::FETCH_ASSOC);
            if (!$trip) { $pdo->rollBack(); radarReply(404, ['ok'=>false, 'message'=>'No se encontró el viaje o no te pertenece.']); }
            if (strtolower((string)$trip['ESTADO']) === 'finalizado') { $pdo->commit(); radarReply(200, ['ok'=>true, 'message'=>'Este viaje ya estaba finalizado.']); }
            if (strtolower((string)$trip['ESTADO']) !== 'llegado') { $pdo->rollBack(); radarReply(409, ['ok'=>false, 'message'=>'Primero debes llegar al punto de recogida antes de finalizar el viaje.']); }
            $upd = $pdo->prepare("UPDATE SOLICITUDES_VIAJE SET ESTADO='finalizado' WHERE ID=? AND TRANSPORTISTA_ID=? AND ESTADO='llegado'");
            $upd->execute([$requestId, (int)$_SESSION['user_id']]);
            if ($upd->rowCount() === 1) radarNotify($pdo, (int)$trip['CLIENTE_ID'], 'Viaje finalizado', 'El transportista ha marcado como finalizado el viaje #' . $requestId . '.', 'success', 'solicitud_viaje', $requestId);
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'message'=>'Viaje finalizado. Ya apareces nuevamente como disponible en el mapa.']);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar trip finish error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo finalizar el viaje. Inténtalo nuevamente.']);
        }
    }

    // Devuelve las ubicaciones en vivo únicamente a los dos participantes de un viaje en curso.
    if ($action === 'trip_live_locations') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar el seguimiento.']);
        $requestId = filter_var($data['solicitud_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$requestId || $requestId < 1) radarReply(422, ['ok'=>false, 'message'=>'La solicitud no es válida.']);
        $q = $pdo->prepare("SELECT s.ID, s.CLIENTE_ID, s.TRANSPORTISTA_ID, s.ESTADO, c.UBICACION AS CLIENTE_UBICACION, t.UBICACION AS TRANSPORTISTA_UBICACION FROM SOLICITUDES_VIAJE s JOIN USUARIOS c ON c.ID=s.CLIENTE_ID JOIN USUARIOS t ON t.ID=s.TRANSPORTISTA_ID WHERE s.ID=? AND (s.CLIENTE_ID=? OR s.TRANSPORTISTA_ID=?) LIMIT 1");
        $uid = (int)$_SESSION['user_id'];
        $q->execute([$requestId, $uid, $uid]);
        $trip = $q->fetch(PDO::FETCH_ASSOC);
        if (!$trip) radarReply(404, ['ok'=>false, 'message'=>'No tienes acceso a este viaje.']);
        if (!in_array(strtolower((string)$trip['ESTADO']), ['en_curso','llegado'], true)) radarReply(409, ['ok'=>false, 'message'=>'El seguimiento de este viaje ya no está activo.']);
        $parseLocation = static function($raw) {
            $v = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($v) && is_string($raw) && preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)\s*$/', $raw, $m)) $v = ['lat'=>$m[1], 'lng'=>$m[2]];
            if (!is_array($v)) return null;
            $lat = $v['lat'] ?? $v['latitude'] ?? $v['latitud'] ?? null;
            $lng = $v['lng'] ?? $v['lon'] ?? $v['longitude'] ?? $v['longitud'] ?? null;
            if (isset($v['coordinates']) && is_array($v['coordinates']) && count($v['coordinates']) >= 2) { $lng=$v['coordinates'][0]; $lat=$v['coordinates'][1]; }
            if (!is_numeric($lat) || !is_numeric($lng)) return null;
            $lat=(float)$lat; $lng=(float)$lng;
            if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) return null;
            return ['lat'=>$lat, 'lng'=>$lng, 'actualizado_en'=>$v['actualizado_en'] ?? null];
        };
        radarReply(200, ['ok'=>true, 'estado'=>$trip['ESTADO'], 'cliente'=>$parseLocation($trip['CLIENTE_UBICACION']), 'transportista'=>$parseLocation($trip['TRANSPORTISTA_UBICACION'])]);
    }

    // Guarda las coordenadas GPS en UBICACION exclusivamente mientras la cuenta esté activa.
    // La transacción bloquea la fila para evitar una escritura si el estado cambia durante la operación.
    if ($action === 'location_update') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Debes iniciar sesión para actualizar la ubicación.']);
        $latValue = isset($data['lat']) ? $data['lat'] : null;
        $lngValue = isset($data['lng']) ? $data['lng'] : null;
        if (!is_numeric($latValue) || !is_numeric($lngValue)) {
            radarReply(422, ['ok'=>false, 'message'=>'Las coordenadas GPS no son válidas.']);
        }
        $lat = (float)$latValue;
        $lng = (float)$lngValue;
        if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            radarReply(422, ['ok'=>false, 'message'=>'Las coordenadas GPS están fuera del rango permitido.']);
        }
        $accuracyValue = isset($data['accuracy']) && is_numeric($data['accuracy']) ? (float)$data['accuracy'] : 0.0;
        $accuracy = is_finite($accuracyValue) ? max(0.0, min(100000.0, $accuracyValue)) : 0.0;
        $address = isset($data['address']) && is_string($data['address']) ? trim($data['address']) : '';
        $address = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $address);
        $address = substr($address, 0, 500);
        $locationJson = json_encode([
            'lat' => round($lat, 7),
            'lng' => round($lng, 7),
            'precision_m' => round($accuracy, 1),
            'direccion' => $address !== '' ? $address : null,
            'actualizado_en' => gmdate('c')
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($locationJson === false) radarReply(500, ['ok'=>false, 'message'=>'No se pudo preparar la ubicación GPS.']);

        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare('SELECT ESTADO FROM USUARIOS WHERE ID=? LIMIT 1 FOR UPDATE');
            $q->execute([$_SESSION['user_id']]);
            $userState = $q->fetch();
            if (!$userState) {
                $pdo->rollBack();
                radarReply(401, ['ok'=>false, 'message'=>'La cuenta no existe o la sesión terminó.']);
            }
            if (strtolower(trim((string)$userState['ESTADO'])) !== 'activo') {
                $pdo->rollBack();
                radarReply(403, ['ok'=>false, 'message'=>'La ubicación solo se actualiza cuando el estado de la cuenta es activo.']);
            }
            $q = $pdo->prepare("UPDATE USUARIOS SET UBICACION=? WHERE ID=? AND LOWER(TRIM(ESTADO))='activo'");
            $q->execute([$locationJson, $_SESSION['user_id']]);
            $pdo->commit();
            radarReply(200, ['ok'=>true, 'message'=>'Ubicación actualizada.', 'updatedAt'=>gmdate('c')]);
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar location update error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo guardar la ubicación en la base de datos.']);
        }
    }

    // Devuelve exclusivamente transportistas aprobados, activos y con una ubicación reciente
    // a no más de 1 km del dispositivo autenticado. No se exponen ubicaciones de cuentas inactivas.
    if ($action === 'nearby_transportistas') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para consultar los transportistas cercanos.']);
        $clientLatValue = $data['lat'] ?? null;
        $clientLngValue = $data['lng'] ?? null;
        if (!is_numeric($clientLatValue) || !is_numeric($clientLngValue)) {
            radarReply(422, ['ok'=>false, 'message'=>'La ubicación del cliente no es válida.']);
        }
        $clientLat = (float)$clientLatValue;
        $clientLng = (float)$clientLngValue;
        if (!is_finite($clientLat) || !is_finite($clientLng) || $clientLat < -90 || $clientLat > 90 || $clientLng < -180 || $clientLng > 180) {
            radarReply(422, ['ok'=>false, 'message'=>'Las coordenadas del cliente están fuera del rango permitido.']);
        }
        try {
            $q = $pdo->prepare('SELECT TIPO, ESTADO FROM USUARIOS WHERE ID=? LIMIT 1');
            $q->execute([(int)$_SESSION['user_id']]);
            $viewer = $q->fetch();
            if (!$viewer) radarReply(401, ['ok'=>false, 'message'=>'La cuenta no existe o la sesión terminó.']);
            if (strtolower(trim((string)$viewer['ESTADO'])) !== 'activo') {
                radarReply(200, ['ok'=>true, 'transportistas'=>[], 'total'=>0, 'radio_m'=>1000, 'message'=>'Activa tu cuenta para consultar los transportistas cercanos.']);
            }

            // Primero se leen solo los datos pequeños de ubicación. Las fotos pesadas
            // se consultan después y exclusivamente para los transportistas dentro del radio.
            // Diagnóstico incluido en la respuesta para distinguir entre falta de GPS,
            // posiciones antiguas o transportistas que simplemente están fuera del radio.
            // Un transportista queda ocupado desde que inicia el viaje hasta que lo finaliza.
            // Durante el trayecto hacia la recogida (en_curso), los demás marcadores se atenúan.
            $dimQuery = $pdo->query("SELECT EXISTS(SELECT 1 FROM SOLICITUDES_VIAJE WHERE LOWER(TRIM(ESTADO))='en_curso')");
            $dimOtherTransporters = (bool)$dimQuery->fetchColumn();
            $sql = "SELECT u.ID, u.USUARIO, u.NOMBRE, u.APELLIDOS, u.UBICACION
                    FROM USUARIOS u
                    WHERE LOWER(TRIM(u.TIPO))='transportista'
                      AND LOWER(TRIM(u.ESTADO))='activo'
                      AND NOT EXISTS (
                        SELECT 1 FROM SOLICITUDES_VIAJE busy
                        WHERE busy.TRANSPORTISTA_ID=u.ID AND LOWER(TRIM(busy.ESTADO)) IN ('en_curso','llegado')
                      )
                    ORDER BY u.ID DESC";
            $rows = $pdo->query($sql)->fetchAll();
            $nearby = [];
            $nearbyIndexById = [];
            $now = time();
            $diagnostic = ['activos'=>0, 'con_ubicacion'=>0, 'coordenadas_validas'=>0, 'ubicacion_reciente'=>0, 'dentro_radio'=>0, 'mensaje'=>''];
            foreach ($rows as $row) {
                if ((int)$row['ID'] === (int)$_SESSION['user_id']) continue;
                $diagnostic['activos']++;
                $rawLocation = trim((string)($row['UBICACION'] ?? ''));
                if ($rawLocation === '') continue;
                $diagnostic['con_ubicacion']++;
                $loc = json_decode($rawLocation, true);
                if (!is_array($loc)) {
                    // Compatibilidad con ubicaciones guardadas como "lat,lng" por versiones anteriores.
                    if (preg_match('/^\s*(-?\d+(?:\.\d+)?)\s*[,; ]\s*(-?\d+(?:\.\d+)?)\s*$/', $rawLocation, $match)) {
                        $loc = ['lat'=>$match[1], 'lng'=>$match[2]];
                    } else {
                        continue;
                    }
                }
                // Las claves se normalizan para tolerar JSON creado con nombres en mayúsculas.
                $normalizedLoc = [];
                foreach ($loc as $key => $value) $normalizedLoc[strtolower((string)$key)] = $value;
                if (isset($normalizedLoc['coordinates']) && is_array($normalizedLoc['coordinates']) && count($normalizedLoc['coordinates']) >= 2) {
                    // GeoJSON usa [longitud, latitud].
                    $normalizedLoc['lng'] = $normalizedLoc['coordinates'][0];
                    $normalizedLoc['lat'] = $normalizedLoc['coordinates'][1];
                }
                $lat = $normalizedLoc['lat'] ?? ($normalizedLoc['latitude'] ?? ($normalizedLoc['latitud'] ?? null));
                $lng = $normalizedLoc['lng'] ?? ($normalizedLoc['lon'] ?? ($normalizedLoc['long'] ?? ($normalizedLoc['longitude'] ?? ($normalizedLoc['longitud'] ?? null))));
                if (!is_numeric($lat) || !is_numeric($lng)) continue;
                $lat = (float)$lat; $lng = (float)$lng;
                if (!is_finite($lat) || !is_finite($lng) || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
                $diagnostic['coordenadas_validas']++;
                $updatedText = '';
                foreach (['actualizado_en','updated_at','updatedat','timestamp','fecha_actualizacion','actualizado'] as $timeKey) {
                    if (isset($normalizedLoc[$timeKey]) && is_scalar($normalizedLoc[$timeKey])) { $updatedText = trim((string)$normalizedLoc[$timeKey]); break; }
                }
                $updatedEpoch = $updatedText !== '' ? strtotime($updatedText) : false;
                // Posición reciente: tres minutos. El cliente envía un heartbeat mientras el GPS está activo.
                if ($updatedEpoch === false || ($now - $updatedEpoch) > 180 || $updatedEpoch > ($now + 60)) continue;
                $diagnostic['ubicacion_reciente']++;
                $rad = static function($value) { return $value * M_PI / 180; };
                $dLat = $rad($lat - $clientLat);
                $dLng = $rad($lng - $clientLng);
                $a = sin($dLat / 2) * sin($dLat / 2) + cos($rad($clientLat)) * cos($rad($lat)) * sin($dLng / 2) * sin($dLng / 2);
                $distance = 6371000 * 2 * atan2(sqrt(max(0, $a)), sqrt(max(0, 1 - $a)));
                if ($distance > 1000) continue;
                $diagnostic['dentro_radio']++;
                $displayName = trim((string)($row['NOMBRE'] ?? '') . ' ' . (string)($row['APELLIDOS'] ?? ''));
                if ($displayName === '') $displayName = (string)$row['USUARIO'];
                $nearbyIndexById[(int)$row['ID']] = count($nearby);
                $nearby[] = [
                    'id'=>(int)$row['ID'],
                    'usuario'=>(string)$row['USUARIO'],
                    'nombre'=>$displayName,
                    'tipo_vehiculo'=>'',
                    'chapa'=>'',
                    'lat'=>$lat,
                    'lng'=>$lng,
                    'distancia_m'=>(int)round($distance),
                    'foto_vehiculo'=>null,
                    'foto_usuario'=>null,
                    'foto_marcador'=>null,
                    'actualizado_en'=>$updatedText
                ];
            }
            if ($diagnostic['activos'] === 0) {
                $diagnostic['mensaje'] = 'No hay usuarios con tipo Transportista y estado Activo en la base de datos.';
            } elseif ($diagnostic['con_ubicacion'] === 0) {
                $diagnostic['mensaje'] = 'Hay ' . $diagnostic['activos'] . ' transportista(s) activo(s), pero ninguno tiene coordenadas guardadas. Abre El Radar en el dispositivo del transportista, activa su estado y permite el GPS.';
            } elseif ($diagnostic['coordenadas_validas'] === 0) {
                $diagnostic['mensaje'] = 'Hay ubicaciones guardadas, pero ninguna contiene coordenadas reconocibles. El transportista debe abrir la app y actualizar su GPS.';
            } elseif ($diagnostic['ubicacion_reciente'] === 0) {
                $diagnostic['mensaje'] = 'No existen vehículos en el rango de 1 km respecto a tu ubicación actual.';
            } elseif ($diagnostic['dentro_radio'] === 0) {
                $diagnostic['mensaje'] = 'Hay ' . $diagnostic['ubicacion_reciente'] . ' transportista(s) con GPS reciente, pero ninguno está dentro de 1 km de tu ubicación.';
            } else {
                $diagnostic['mensaje'] = $diagnostic['dentro_radio'] . ' transportista(s) encontrado(s) dentro de 1 km.';
            }
            if ($nearby) {
                $nearbyIds = array_keys($nearbyIndexById);
                $in = implode(',', array_fill(0, count($nearbyIds), '?'));
                $photoSql = "SELECT u.ID AS USUARIO_ID, u.FOTO AS FOTO_USUARIO,
                                    s.TIPO_VEHICULO, s.CHAPA, s.LICENCIA_CONDUCCION, s.TELEFONO, s.FOTO_VEHICULO
                             FROM USUARIOS u
                             LEFT JOIN SOLICITUDES_TRANSPORTISTA s ON s.ID = (
                                SELECT MAX(s2.ID) FROM SOLICITUDES_TRANSPORTISTA s2
                                WHERE s2.USUARIO_ID=u.ID AND LOWER(TRIM(s2.ESTADO_SOLICITUD))='aprobada'
                             )
                             WHERE u.ID IN ($in)";
                $photoQuery = $pdo->prepare($photoSql);
                $photoQuery->execute($nearbyIds);
                foreach ($photoQuery->fetchAll() as $photoRow) {
                    $id = (int)$photoRow['USUARIO_ID'];
                    if (!array_key_exists($id, $nearbyIndexById)) continue;
                    $vehiclePhoto = isset($photoRow['FOTO_VEHICULO']) ? (string)$photoRow['FOTO_VEHICULO'] : '';
                    $profilePhoto = isset($photoRow['FOTO_USUARIO']) ? (string)$photoRow['FOTO_USUARIO'] : '';
                    // Las fotos se guardan como Data URI validada al enviarse el formulario.
                    if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $vehiclePhoto)) $vehiclePhoto = '';
                    if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $profilePhoto)) $profilePhoto = '';
                    $idx = $nearbyIndexById[$id];
                    $nearby[$idx]['tipo_vehiculo'] = (string)($photoRow['TIPO_VEHICULO'] ?? '');
                    $nearby[$idx]['chapa'] = (string)($photoRow['CHAPA'] ?? '');
                    $nearby[$idx]['licencia_conduccion'] = (string)($photoRow['LICENCIA_CONDUCCION'] ?? '');
                    $nearby[$idx]['telefono'] = (string)($photoRow['TELEFONO'] ?? '');
                    $nearby[$idx]['foto_vehiculo'] = $vehiclePhoto !== '' ? $vehiclePhoto : null;
                    $nearby[$idx]['foto_usuario'] = $profilePhoto !== '' ? $profilePhoto : null;
                    $nearby[$idx]['foto_marcador'] = $vehiclePhoto !== '' ? $vehiclePhoto : ($profilePhoto !== '' ? $profilePhoto : null);
                }
            }
            usort($nearby, static function($a, $b) { return $a['distancia_m'] <=> $b['distancia_m']; });
            radarReply(200, ['ok'=>true, 'transportistas'=>$nearby, 'total'=>count($nearby), 'radio_m'=>1000, 'atenuar_transportistas'=>$dimOtherTransporters, 'diagnostico'=>$diagnostic, 'actualizado_en'=>gmdate('c')]);
        } catch (Exception $e) {
            error_log('El Radar nearby transportistas error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudieron consultar los transportistas cercanos.']);
        }
    }

    if ($action === 'arrival_radius_get' || $action === 'arrival_radius_save') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para configurar el aviso de llegada.']);
        $userId = (int)$_SESSION['user_id'];
        if ($action === 'arrival_radius_get') {
            $q = $pdo->prepare('SELECT RADIO_LLEGADA_M FROM USUARIOS WHERE ID=? LIMIT 1'); $q->execute([$userId]);
            radarReply(200, ['ok'=>true, 'radio_m'=>(int)($q->fetchColumn() ?: 10)]);
        }
        $radius = filter_var($data['radio_m'] ?? null, FILTER_VALIDATE_INT);
        if ($radius === false || $radius < 5 || $radius > 20) radarReply(422, ['ok'=>false, 'message'=>'La distancia debe estar entre 5 y 20 metros.']);
        $q = $pdo->prepare('UPDATE USUARIOS SET RADIO_LLEGADA_M=? WHERE ID=?'); $q->execute([$radius, $userId]);
        radarReply(200, ['ok'=>true, 'radio_m'=>$radius, 'message'=>'Distancia del aviso de llegada guardada.']);
    }

    if ($action === 'referral_info') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión para obtener tu enlace de invitación.']);
        $userId = (int)$_SESSION['user_id'];
        $q = $pdo->prepare('SELECT ID, TIPO, CODIGO_REFERIDO FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$userId]);
        $owner = $q->fetch();
        if (!$owner) radarReply(401, ['ok'=>false, 'message'=>'No se encontró tu cuenta.']);
        $code = trim((string)($owner['CODIGO_REFERIDO'] ?? ''));
        if ($code === '') {
            for ($attempt=0; $attempt<5; $attempt++) {
                $candidate = strtoupper(bin2hex(random_bytes(8)));
                try {
                    $u = $pdo->prepare('UPDATE USUARIOS SET CODIGO_REFERIDO=? WHERE ID=? AND (CODIGO_REFERIDO IS NULL OR CODIGO_REFERIDO=\'\')');
                    $u->execute([$candidate, $userId]);
                    $q = $pdo->prepare('SELECT CODIGO_REFERIDO FROM USUARIOS WHERE ID=?');
                    $q->execute([$userId]);
                    $code = (string)$q->fetchColumn();
                    if ($code !== '') break;
                } catch (PDOException $e) { if ($attempt === 4) throw $e; }
            }
        }
        if ($code === '') radarReply(500, ['ok'=>false, 'message'=>'No se pudo generar tu enlace. Inténtalo de nuevo.']);
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $reward = (float)$pdo->query('SELECT RECOMPENSA_COMPARTIR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
        radarReply(200, ['ok'=>true, 'codigo'=>$code, 'enlace'=>$base . '?ref=' . rawurlencode($code), 'recompensa'=>$reward]);
    }

    if ($action === 'register') {
        $usuario = trim((string)(isset($data['usuario']) ? $data['usuario'] : ''));
        $pass = (string)(isset($data['contrasena']) ? $data['contrasena'] : '');
        $nombre = trim((string)(isset($data['nombre']) ? $data['nombre'] : ''));
        $apellidos = trim((string)(isset($data['apellidos']) ? $data['apellidos'] : ''));
        $carnetIdentidad = trim((string)(isset($data['carnet_identidad']) ? $data['carnet_identidad'] : ''));
        $telefono = trim((string)($data['telefono'] ?? ''));
        $correo = mb_strtolower(trim((string)($data['correo'] ?? '')), 'UTF-8');
        $provincia = trim((string)($data['provincia'] ?? ''));
        $municipio = trim((string)($data['municipio'] ?? ''));
        $confirmacion = (string)(isset($data['confirmar']) ? $data['confirmar'] : '');
        $fotoRecibida = isset($data['foto']) ? $data['foto'] : null;
        $referralCode = strtoupper(trim((string)($data['codigo_referido'] ?? '')));
        if (!is_string($fotoRecibida) || trim($fotoRecibida) === '') {
            radarReply(422, ['ok'=>false, 'message'=>'La foto de perfil es obligatoria. Selecciona una imagen para continuar.']);
        }
        $foto = radarPhoto($fotoRecibida);
        if ($usuario === '') radarReply(422, ['ok'=>false, 'message'=>'Introduce un nombre de usuario.']);
        if ($pass === '') radarReply(422, ['ok'=>false, 'message'=>'Introduce una contraseña.']);
        if ($confirmacion === '') radarReply(422, ['ok'=>false, 'message'=>'Confirma la contraseña.']);
        if (!hash_equals($pass, $confirmacion)) radarReply(422, ['ok'=>false, 'message'=>'Las contraseñas no coinciden.']);
        if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 100) radarReply(422, ['ok'=>false, 'message'=>'Introduce tu nombre (máximo 100 caracteres).']);
        if ($apellidos === '' || mb_strlen($apellidos, 'UTF-8') > 150) radarReply(422, ['ok'=>false, 'message'=>'Introduce tus apellidos (máximo 150 caracteres).']);
        if (!preg_match('/^[0-9]{11}$/', $carnetIdentidad)) radarReply(422, ['ok'=>false, 'message'=>'Introduce el carné de identidad de 11 dígitos, sin espacios ni guiones.']);
        if (!preg_match('/^\+?[0-9][0-9\s().-]{6,18}[0-9]$/', $telefono) || strlen(preg_replace('/\\D/', '', $telefono)) < 8 || strlen(preg_replace('/\\D/', '', $telefono)) > 13) radarReply(422, ['ok'=>false, 'message'=>'Introduce un número de teléfono válido, incluyendo el código +53 si corresponde.']);
        if (strlen($correo) > 190 || !filter_var($correo, FILTER_VALIDATE_EMAIL)) radarReply(422, ['ok'=>false, 'message'=>'Introduce un correo electrónico válido.']);
        $provinciasCuba = ['Pinar del Río','Artemisa','La Habana','Mayabeque','Matanzas','Cienfuegos','Villa Clara','Sancti Spíritus','Ciego de Ávila','Camagüey','Las Tunas','Holguín','Granma','Santiago de Cuba','Guantánamo','Isla de la Juventud'];
        if (!in_array($provincia, $provinciasCuba, true)) radarReply(422, ['ok'=>false, 'message'=>'Selecciona una provincia válida de Cuba.']);
        if ($municipio === '' || mb_strlen($municipio, 'UTF-8') > 100) radarReply(422, ['ok'=>false, 'message'=>'Selecciona el municipio de residencia.']);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $usuario)) {
            radarReply(422, ['ok'=>false, 'message'=>'El usuario debe tener entre 3 y 50 caracteres y solo puede usar letras, números, punto, guion o guion bajo.']);
        }
        if (strlen($pass) < 8) radarReply(422, ['ok'=>false, 'message'=>'La contraseña debe tener al menos 8 caracteres.']);
        $q = $pdo->prepare('SELECT ID FROM USUARIOS WHERE USUARIO=? LIMIT 1');
        $q->execute([$usuario]);
        if ($q->fetch()) radarReply(409, ['ok'=>false, 'message'=>'Ese nombre de usuario ya está registrado. Elige otro.']);
        $q = $pdo->prepare('SELECT ID FROM USUARIOS WHERE CARNET_IDENTIDAD=? LIMIT 1');
        $q->execute([$carnetIdentidad]);
        if ($q->fetch()) radarReply(409, ['ok'=>false, 'message'=>'Ese carné de identidad ya está registrado.']);
        $q = $pdo->prepare('SELECT ID FROM USUARIOS WHERE LOWER(CORREO)=LOWER(?) LIMIT 1');
        $q->execute([$correo]);
        if ($q->fetch()) radarReply(409, ['ok'=>false, 'message'=>'Ese correo electrónico ya está registrado.']);
        $trialDays = (int)$pdo->query('SELECT PRUEBA_DIAS FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
        $trialStart = date('Y-m-d H:i:s');
        $trialEnd = $trialDays > 0 ? date('Y-m-d H:i:s', strtotime('+' . $trialDays . ' days')) : null;
        $initialStatus = $trialDays > 0 ? 'activo' : 'inactivo';
        $referrerId = null;
        if ($referralCode !== '' && preg_match('/^[A-F0-9]{16}$/', $referralCode)) {
            $refQ = $pdo->prepare("SELECT ID FROM USUARIOS WHERE CODIGO_REFERIDO=? LIMIT 1");
            $refQ->execute([$referralCode]);
            $foundReferrer = $refQ->fetchColumn();
            if ($foundReferrer) $referrerId = (int)$foundReferrer;
        }
        try {
            $pdo->beginTransaction();
            $q = $pdo->prepare("INSERT INTO USUARIOS (USUARIO, CONTRASENA, TIPO, ESTADO, FOTO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, REFERIDO_POR) VALUES (?, ?, 'cliente', ?, ?, ?, ?, ?, ?, ?, ?, ?, 0.00, 0, NOW(), ?, ?, ?)");
            $q->execute([$usuario, password_hash($pass, PASSWORD_DEFAULT), $initialStatus, $foto, $nombre, $apellidos, $carnetIdentidad, $telefono, $correo, $provincia, $municipio, $trialDays > 0 ? $trialStart : null, $trialEnd, $referrerId]);
            $newUserId = (int)$pdo->lastInsertId();
            if ($referrerId && $referrerId !== $newUserId) {
                $lockRef = $pdo->prepare('SELECT SALDO FROM USUARIOS WHERE ID=? FOR UPDATE');
                $lockRef->execute([$referrerId]);
                $before = $lockRef->fetchColumn();
                if ($before !== false) {
                    $before = round((float)$before, 2);
                    $shareReward = (float)$pdo->query('SELECT RECOMPENSA_COMPARTIR FROM RADAR_TARIFAS_USO WHERE ID=1')->fetchColumn();
                    $after = round($before + $shareReward, 2);
                    $pdo->prepare('UPDATE USUARIOS SET SALDO=? WHERE ID=?')->execute([number_format($after, 2, '.', ''), $referrerId]);
                    $movement = $pdo->prepare("INSERT INTO RADAR_MOVIMIENTOS_SALDO (USUARIO_ID, TIPO, DESCRIPCION, IMPORTE, SALDO_ANTERIOR, SALDO_NUEVO, REFERENCIA) VALUES (?, 'invitacion', ?, ?, ?, ?, ?)");
                    $movement->execute([$referrerId, 'Recompensa por invitar a ' . $usuario, number_format($shareReward, 2, '.', ''), number_format($before, 2, '.', ''), number_format($after, 2, '.', ''), (string)$newUserId]);
                }
            }
            $pdo->commit();
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($e->getCode() === '23000') radarReply(409, ['ok'=>false, 'message'=>'El usuario o carné ya está registrado.']);
            throw $e;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$pdo->lastInsertId();
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $_SESSION['session_token']);
        $pdo->prepare('UPDATE USUARIOS SET TOKEN_SESION=? WHERE ID=?')->execute([$sessionHash, (int)$_SESSION['user_id']]);
        radarNotify($pdo, (int)$_SESSION['user_id'], 'Bienvenido a El Radar', 'Tu cuenta se creó correctamente. Ya puedes utilizar los módulos disponibles para tu cuenta.', 'success');
        radarReply(201, ['ok'=>true, 'message'=>'Cuenta creada correctamente.', 'user'=>['id'=>(int)$_SESSION['user_id'], 'fecha_creacion'=>$trialStart, 'usuario'=>$usuario, 'nombre'=>$nombre, 'apellidos'=>$apellidos, 'carnet_identidad'=>$carnetIdentidad, 'telefono'=>$telefono, 'correo'=>$correo, 'provincia'=>$provincia, 'municipio'=>$municipio, 'estado'=>$initialStatus, 'tipo'=>'cliente', 'foto'=>$foto, 'saldo'=>0, 'tiempo_disponible'=>0, 'prueba_inicio'=>$trialDays > 0 ? $trialStart : null, 'prueba_fin'=>$trialEnd, 'prueba_dias_restantes'=>$trialDays > 0 ? $trialDays : 0, 'prueba_vencida'=>$trialDays <= 0]]);
    }

    if ($action === 'password_reset_request') {
        $email = strtolower(trim((string)($data['correo'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) radarReply(422, ['ok'=>false, 'message'=>'Introduce un correo electrónico válido.']);
        $q = $pdo->prepare('SELECT ID, USUARIO, NOMBRE FROM USUARIOS WHERE LOWER(CORREO)=? LIMIT 1');
        $q->execute([$email]);
        $account = $q->fetch();
        // Respuesta idéntica exista o no la cuenta para evitar enumeración de usuarios.
        if ($account) {
            try {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $pdo->prepare('DELETE FROM RADAR_PASSWORD_RESETS WHERE USUARIO_ID=?')->execute([(int)$account['ID']]);
                $pdo->prepare('INSERT INTO RADAR_PASSWORD_RESETS (USUARIO_ID, TOKEN_HASH, EXPIRA_EN) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 MINUTE))')->execute([(int)$account['ID'], $tokenHash]);
                $host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
                if ($host === '') throw new RuntimeException('No se pudo determinar el dominio del sitio.');
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $path = strtok((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), '?');
                $resetUrl = $scheme . '://' . $host . $path . '?reset_password=' . rawurlencode($rawToken);
                $subject = 'Recuperar contraseña - El Radar';
                $name = trim((string)($account['NOMBRE'] ?? '')) ?: (string)$account['USUARIO'];
                $body = "Hola {$name},\n\nRecibimos una solicitud para cambiar la contraseña de tu cuenta de El Radar.\n\nAbre este enlace para crear una contraseña nueva (válido durante 30 minutos y de un solo uso):\n{$resetUrl}\n\nSi no solicitaste este cambio, ignora este mensaje. Tu contraseña actual seguirá funcionando.\n";
                $smtpFile = __DIR__ . '/.radar_smtp_config.php';
                $smtpConfig = [];
                if (is_file($smtpFile)) { if (!defined('RADAR_SMTP_CONFIG_BOOTSTRAPPED')) define('RADAR_SMTP_CONFIG_BOOTSTRAPPED', true); $smtpConfig = require $smtpFile; }
                $sent = is_array($smtpConfig) && !empty($smtpConfig['host'])
                    ? radarSmtpSend($smtpConfig, $email, $subject, $body)
                    : false;
                if (!$sent) {
                    error_log('El Radar password reset email could not be sent; SMTP configuration is missing or invalid.');
                    radarReply(503, ['ok'=>false, 'message'=>'No se pudo enviar el correo. El administrador debe configurar y probar el servidor SMTP en Administración.']);
                }
            } catch (Throwable $e) {
                error_log('El Radar password reset error: ' . $e->getMessage());
                radarReply(500, ['ok'=>false, 'message'=>'No se pudo procesar la recuperación. Inténtalo más tarde.']);
            }
        }
        radarReply(200, ['ok'=>true, 'message'=>'Si el correo está asociado a una cuenta, recibirás un enlace para restablecer la contraseña. Revisa también la carpeta de spam.']);
    }

    if ($action === 'password_reset_complete') {
        $token = trim((string)($data['token'] ?? ''));
        $newPassword = (string)($data['contrasena'] ?? '');
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) radarReply(422, ['ok'=>false, 'message'=>'El enlace no es válido. Solicita uno nuevo.']);
        if (strlen($newPassword) < 8 || strlen($newPassword) > 200) radarReply(422, ['ok'=>false, 'message'=>'La contraseña debe tener al menos 8 caracteres.']);
        $q = $pdo->prepare('SELECT ID, USUARIO_ID FROM RADAR_PASSWORD_RESETS WHERE TOKEN_HASH=? AND EXPIRA_EN>NOW() LIMIT 1');
        $q->execute([hash('sha256', $token)]);
        $reset = $q->fetch();
        if (!$reset) radarReply(410, ['ok'=>false, 'message'=>'El enlace ha caducado o ya fue utilizado. Solicita una recuperación nueva.']);
        try {
            $pdo->beginTransaction();
            $lock = $pdo->prepare('SELECT ID FROM RADAR_PASSWORD_RESETS WHERE ID=? AND EXPIRA_EN>NOW() FOR UPDATE');
            $lock->execute([(int)$reset['ID']]);
            if (!$lock->fetch()) { $pdo->rollBack(); radarReply(410, ['ok'=>false, 'message'=>'El enlace ha caducado o ya fue utilizado. Solicita una recuperación nueva.']); }
            $pdo->prepare('UPDATE USUARIOS SET CONTRASENA=?, TOKEN_SESION=NULL WHERE ID=?')->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$reset['USUARIO_ID']]);
            $pdo->prepare('DELETE FROM RADAR_PASSWORD_RESETS WHERE USUARIO_ID=?')->execute([(int)$reset['USUARIO_ID']]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('El Radar password reset completion error: ' . $e->getMessage());
            radarReply(500, ['ok'=>false, 'message'=>'No se pudo actualizar la contraseña. Inténtalo de nuevo.']);
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) { $cookie = session_get_cookie_params(); setcookie(session_name(), '', time()-42000, $cookie['path'], $cookie['domain'], $cookie['secure'], $cookie['httponly']); }
        session_destroy();
        radarReply(200, ['ok'=>true, 'message'=>'Contraseña actualizada. Ya puedes iniciar sesión con tu nueva contraseña.']);
    }

    if ($action === 'login') {
        $usuario = trim((string)(isset($data['usuario']) ? $data['usuario'] : ''));
        $pass = (string)(isset($data['contrasena']) ? $data['contrasena'] : '');
        if ($usuario === '' || $pass === '') radarReply(422, ['ok'=>false, 'message'=>'Introduce tu usuario y contraseña.']);
        $q = $pdo->prepare('SELECT ID, USUARIO, CONTRASENA, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TIPO, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE USUARIO=? LIMIT 1');
        $q->execute([$usuario]);
        $u = $q->fetch();
        if (!$u || !password_verify($pass, (string)$u['CONTRASENA'])) radarReply(401, ['ok'=>false, 'message'=>'Usuario o contraseña incorrectos.']);
        // Al vencer la prueba, la cuenta pasa automáticamente a inactiva. Se permite iniciar sesión para acceder a Recargar cuenta.
        $tipoLogin = strtolower(trim((string)$u['TIPO']));
        if ($tipoLogin !== 'admin' && !empty($u['PRUEBA_FIN']) && strtotime((string)$u['PRUEBA_FIN']) <= time() && empty($u['PAGO_HASTA'])) {
            $pdo->prepare("UPDATE USUARIOS SET ESTADO='inactivo' WHERE ID=?")->execute([(int)$u['ID']]);
            $u['ESTADO'] = 'inactivo';
            radarNotify($pdo, (int)$u['ID'], 'Periodo de prueba vencido', 'Tu periodo de prueba ha terminado. Recarga tu cuenta para continuar usando El Radar.', 'warning');
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$u['ID'];
        $_SESSION['session_token'] = bin2hex(random_bytes(32));
        $sessionHash = hash('sha256', $_SESSION['session_token']);
        // Reemplaza la sesión activa anterior: solo este dispositivo conserva acceso.
        $pdo->prepare('UPDATE USUARIOS SET TOKEN_SESION=? WHERE ID=?')->execute([$sessionHash, (int)$u['ID']]);
        // Conserva la suscripción Web Push: el dispositivo se sincroniza al entrar en la aplicación.
        radarApplyDailyCharge($pdo, (int)$u['ID']);
        $refresh = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, CARNET_IDENTIDAD, TELEFONO, CORREO, PROVINCIA, MUNICIPIO, TIPO, ES_EDITOR, ESTADO, FOTO, SALDO, TIEMPO_DISPONIBLE, CREADA_EN, PRUEBA_INICIO, PRUEBA_FIN, PAGO_HASTA FROM USUARIOS WHERE ID=? LIMIT 1');
        $refresh->execute([(int)$u['ID']]);
        $u = $refresh->fetch() ?: $u;
        radarNotify($pdo, (int)$u['ID'], 'Inicio de sesión', 'Has iniciado sesión en El Radar correctamente.', 'info');
        radarReply(200, ['ok'=>true, 'user'=>radarUserData($u)]);
    }

    if ($action === 'delete_account') {
        // Solo se permite eliminar la cuenta que pertenece a la sesión actual.
        if (empty($_SESSION['user_id'])) {
            radarReply(401, ['ok'=>false, 'message'=>'Tu sesión ha caducado. Inicia sesión de nuevo.']);
        }

        $passwordToConfirm = (string)(isset($data['contrasena']) ? $data['contrasena'] : '');
        if ($passwordToConfirm === '') {
            radarReply(422, ['ok'=>false, 'message'=>'Introduce tu contraseña para confirmar la eliminación.']);
        }

        $q = $pdo->prepare('SELECT `ID`, `USUARIO`, `CONTRASENA` FROM `USUARIOS` WHERE `ID` = ? LIMIT 1');
        $q->execute([(int)$_SESSION['user_id']]);
        $account = $q->fetch();
        if (!$account || !password_verify($passwordToConfirm, (string)$account['CONTRASENA'])) {
            radarReply(403, ['ok'=>false, 'message'=>'La contraseña no es correcta. La cuenta no se ha eliminado.']);
        }

        try {
            $delete = $pdo->prepare('DELETE FROM `USUARIOS` WHERE `ID` = ? LIMIT 1');
            $delete->execute([(int)$_SESSION['user_id']]);
            if ($delete->rowCount() !== 1) {
                radarReply(404, ['ok'=>false, 'message'=>'No se encontró la cuenta que deseas eliminar.']);
            }
            $adminIds = $pdo->query("SELECT ID FROM USUARIOS WHERE LOWER(TRIM(TIPO))='admin'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($adminIds as $adminId) {
                radarNotify($pdo, (int)$adminId, 'Cuenta eliminada', 'El usuario ' . (string)$account['USUARIO'] . ' eliminó su cuenta de El Radar.', 'warning');
            }
        } catch (PDOException $e) {
            error_log('El Radar delete account error: ' . $e->getMessage());
            radarReply(409, ['ok'=>false, 'message'=>'No se pudo eliminar la cuenta porque existen datos relacionados o la base de datos lo impide. Contacta al administrador.']);
        }

        // Finaliza y destruye la sesión después de borrar exclusivamente esta cuenta.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        radarReply(200, ['ok'=>true, 'message'=>'Tu cuenta se ha eliminado correctamente.']);
    }

    if ($action === 'logout') {
        if (!empty($_SESSION['user_id'])) {
            radarNotify($pdo, (int)$_SESSION['user_id'], 'Sesión cerrada', 'Has cerrado sesión en El Radar.', 'info');
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        radarReply(200, ['ok'=>true, 'message'=>'Sesión cerrada.']);
    }

    if ($action === 'change_password') {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false, 'message'=>'Inicia sesión de nuevo.']);
        $actual = (string)(isset($data['actual']) ? $data['actual'] : '');
        $nueva = (string)(isset($data['nueva']) ? $data['nueva'] : '');
        if (strlen($nueva) < 8) radarReply(422, ['ok'=>false, 'message'=>'La nueva contraseña debe tener al menos 8 caracteres.']);
        $q = $pdo->prepare('SELECT CONTRASENA FROM USUARIOS WHERE ID=? LIMIT 1');
        $q->execute([$_SESSION['user_id']]);
        $u = $q->fetch();
        if (!$u || !password_verify($actual, (string)$u['CONTRASENA'])) radarReply(401, ['ok'=>false, 'message'=>'La contraseña actual es incorrecta.']);
        $q = $pdo->prepare('UPDATE USUARIOS SET CONTRASENA=? WHERE ID=?');
        $q->execute([password_hash($nueva, PASSWORD_DEFAULT), $_SESSION['user_id']]);
        radarNotify($pdo, (int)$_SESSION['user_id'], 'Contraseña modificada', 'La contraseña de tu cuenta se cambió correctamente.', 'success');
        radarReply(200, ['ok'=>true, 'message'=>'Contraseña cambiada correctamente.']);
    }


    // CHAT DE SOPORTE: un hilo privado por cliente/transportista, visible para administración.
    if (in_array($action, ['chat_list','chat_messages','chat_send'], true)) {
        if (empty($_SESSION['user_id'])) radarReply(401, ['ok'=>false,'message'=>'Inicia sesión para utilizar el chat.']);
        $pdo->exec("CREATE TABLE IF NOT EXISTS RADAR_CHAT_CONVERSACIONES (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            USUARIO_ID INT NOT NULL,
            ESTADO VARCHAR(20) NOT NULL DEFAULT 'abierta',
            CREADA_EN TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ACTUALIZADA_EN TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY(ID), UNIQUE KEY uq_chat_usuario(USUARIO_ID), KEY idx_chat_actualizada(ACTUALIZADA_EN)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS RADAR_CHAT_MENSAJES (
            ID BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            CONVERSACION_ID BIGINT UNSIGNED NOT NULL,
            REMITENTE_ID INT NOT NULL,
            REMITENTE_TIPO VARCHAR(20) NOT NULL,
            MENSAJE VARCHAR(2000) NOT NULL,
            CREADO_EN TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY(ID), KEY idx_chat_mensajes(CONVERSACION_ID,ID)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $meId = (int)$_SESSION['user_id'];
        $meQ = $pdo->prepare('SELECT ID, USUARIO, NOMBRE, APELLIDOS, TIPO FROM USUARIOS WHERE ID=? LIMIT 1');
        $meQ->execute([$meId]); $me = $meQ->fetch();
        if (!$me) radarReply(401,['ok'=>false,'message'=>'La sesión no es válida.']);
        $isAdmin = strtolower(trim((string)$me['TIPO'])) === 'admin';

        if ($action === 'chat_list') {
            if ($isAdmin) {
                $q = $pdo->query("SELECT c.ID AS conversacion_id,u.ID AS USUARIO_ID,u.USUARIO,u.NOMBRE,u.APELLIDOS,u.TIPO,u.FOTO,
                    (SELECT m.MENSAJE FROM RADAR_CHAT_MENSAJES m WHERE m.CONVERSACION_ID=c.ID ORDER BY m.ID DESC LIMIT 1) AS ultimo_mensaje,
                    (SELECT m.CREADO_EN FROM RADAR_CHAT_MENSAJES m WHERE m.CONVERSACION_ID=c.ID ORDER BY m.ID DESC LIMIT 1) AS ultimo_en
                    FROM USUARIOS u LEFT JOIN RADAR_CHAT_CONVERSACIONES c ON c.USUARIO_ID=u.ID
                    WHERE LOWER(TRIM(u.TIPO)) IN ('cliente','transportista')
                    ORDER BY COALESCE(c.ACTUALIZADA_EN,'1970-01-01') DESC,u.USUARIO ASC");
                radarReply(200,['ok'=>true,'admin'=>true,'conversaciones'=>$q->fetchAll()]);
            }
            $q=$pdo->prepare("SELECT ID FROM RADAR_CHAT_CONVERSACIONES WHERE USUARIO_ID=? LIMIT 1");
            $q->execute([$meId]); $conversationId=(int)$q->fetchColumn();
            radarReply(200,['ok'=>true,'admin'=>false,'conversacion_id'=>$conversationId]);
        }
        if ($action === 'chat_messages') {
            $conversationId=(int)($_GET['conversation_id'] ?? 0);
            if ($isAdmin) {
                $targetUserId=(int)($_GET['usuario_id'] ?? 0);
                if ($conversationId > 0) {
                    $q=$pdo->prepare('SELECT ID,USUARIO_ID FROM RADAR_CHAT_CONVERSACIONES WHERE ID=?');
                    $q->execute([$conversationId]); $conversation=$q->fetch();
                } elseif ($targetUserId > 0) {
                    $q=$pdo->prepare('SELECT ID,USUARIO_ID FROM RADAR_CHAT_CONVERSACIONES WHERE USUARIO_ID=?');
                    $q->execute([$targetUserId]); $conversation=$q->fetch();
                    if (!$conversation) $conversation=['ID'=>0,'USUARIO_ID'=>$targetUserId];
                } else { $conversation=false; }
                if ($conversation && (int)$conversation['ID']===0) $conversationId=0;
            } else {
                $q=$pdo->prepare('SELECT ID,USUARIO_ID FROM RADAR_CHAT_CONVERSACIONES WHERE USUARIO_ID=?');
                $q->execute([$meId]); $conversation=$q->fetch();
                $conversationId=(int)($conversation['ID'] ?? 0);
            }
            if (!$conversation) radarReply(200,['ok'=>true,'mensajes'=>[],'conversacion_id'=>0]);
            if ($conversationId===0) radarReply(200,['ok'=>true,'mensajes'=>[],'conversacion_id'=>0]);
            $q=$pdo->prepare("SELECT m.ID,m.REMITENTE_ID,m.REMITENTE_TIPO,m.MENSAJE,m.CREADO_EN,
                CASE WHEN LOWER(TRIM(m.REMITENTE_TIPO))='admin' THEN ''
                     ELSE COALESCE(NULLIF(TRIM(CONCAT_WS(' ',NULLIF(u.NOMBRE,''),NULLIF(u.APELLIDOS,''))),''),u.USUARIO,m.REMITENTE_TIPO)
                END AS REMITENTE,
                u.FOTO AS REMITENTE_FOTO
                FROM RADAR_CHAT_MENSAJES m LEFT JOIN USUARIOS u ON u.ID=m.REMITENTE_ID
                WHERE m.CONVERSACION_ID=? ORDER BY m.ID ASC LIMIT 300");
            $q->execute([$conversationId]);
            radarReply(200,['ok'=>true,'conversacion_id'=>$conversationId,'mensajes'=>$q->fetchAll()]);
        }
        if ($action === 'chat_send') {
            $message=trim((string)($data['mensaje'] ?? ''));
            if ($message==='' || mb_strlen($message,'UTF-8')>2000) radarReply(422,['ok'=>false,'message'=>'Escribe un mensaje de hasta 2000 caracteres.']);
            if ($isAdmin) {
                $conversationId=(int)($data['conversacion_id'] ?? 0);
                $targetUserId=(int)($data['usuario_id'] ?? 0);
                if ($conversationId > 0) {
                    $q=$pdo->prepare('SELECT ID,USUARIO_ID FROM RADAR_CHAT_CONVERSACIONES WHERE ID=?'); $q->execute([$conversationId]);
                    $existing=$q->fetch();
                    if (!$existing) radarReply(404,['ok'=>false,'message'=>'La conversación ya no existe.']);
                    $targetUserId=(int)$existing['USUARIO_ID'];
                } else {
                    $q=$pdo->prepare("SELECT ID FROM USUARIOS WHERE ID=? AND LOWER(TRIM(TIPO)) IN ('cliente','transportista') LIMIT 1");
                    $q->execute([$targetUserId]);
                    if (!$q->fetchColumn()) radarReply(404,['ok'=>false,'message'=>'Selecciona un usuario válido.']);
                    $q=$pdo->prepare('SELECT ID FROM RADAR_CHAT_CONVERSACIONES WHERE USUARIO_ID=?');
                    $q->execute([$targetUserId]); $conversationId=(int)$q->fetchColumn();
                    if (!$conversationId) {
                        $q=$pdo->prepare('INSERT INTO RADAR_CHAT_CONVERSACIONES (USUARIO_ID) VALUES (?)');
                        $q->execute([$targetUserId]); $conversationId=(int)$pdo->lastInsertId();
                    }
                }
            } else {
                if (!in_array(strtolower(trim((string)$me['TIPO'])),['cliente','transportista'],true))
                    radarReply(403,['ok'=>false,'message'=>'Este chat está disponible para clientes y transportistas.']);
                $q=$pdo->prepare('SELECT ID FROM RADAR_CHAT_CONVERSACIONES WHERE USUARIO_ID=?');
                $q->execute([$meId]); $conversationId=(int)$q->fetchColumn();
                if (!$conversationId) {
                    $q=$pdo->prepare("INSERT INTO RADAR_CHAT_CONVERSACIONES (USUARIO_ID) VALUES (?)");
                    $q->execute([$meId]); $conversationId=(int)$pdo->lastInsertId();
                }
            }
            $q=$pdo->prepare('INSERT INTO RADAR_CHAT_MENSAJES (CONVERSACION_ID,REMITENTE_ID,REMITENTE_TIPO,MENSAJE) VALUES (?,?,?,?)');
            $q->execute([$conversationId,$meId,$isAdmin?'admin':strtolower((string)$me['TIPO']),$message]);
            $pdo->prepare('UPDATE RADAR_CHAT_CONVERSACIONES SET ACTUALIZADA_EN=NOW() WHERE ID=?')->execute([$conversationId]);
            $targetId=$isAdmin ? (int)$pdo->query('SELECT USUARIO_ID FROM RADAR_CHAT_CONVERSACIONES WHERE ID='.(int)$conversationId)->fetchColumn() : 0;
            if (!$isAdmin) {
                $admins=$pdo->query("SELECT ID FROM USUARIOS WHERE LOWER(TRIM(TIPO))='admin'")->fetchAll(PDO::FETCH_COLUMN);
                foreach($admins as $adminId) radarNotify($pdo,(int)$adminId,'Nuevo mensaje de soporte',(string)$me['USUARIO'].': '.mb_substr($message,0,180),'info');
            } else {
                radarNotify($pdo,$targetId,'Respuesta de Administración',mb_substr($message,0,180),'info');
            }
            radarReply(201,['ok'=>true,'conversacion_id'=>$conversationId,'message'=>'Mensaje enviado.']);
        }
    }

    radarReply(404, ['ok'=>false, 'message'=>'Acción desconocida.']);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <!-- Reduce la exposición de la interfaz a lectores automáticos y cachés públicas. -->
    <meta name="robots" content="noindex, noarchive, nosnippet, noimageindex">
    <meta name="googlebot" content="noindex, noarchive, nosnippet, noimageindex">
    <meta name="format-detection" content="telephone=no">
    <style id="radar-proteccion-lectura-impresion">
      @media print { html, body { display:none !important; visibility:hidden !important; } }
      html { -webkit-print-color-adjust: exact; }
    </style>
    <script id="radar-proteccion-lectura-descarga">
    (function(){
      'use strict';
      const stopAction = function(e){ e.preventDefault(); e.stopImmediatePropagation(); return false; };
      // Bloquea los atajos habituales de guardar, imprimir, ver el código y abrir herramientas.
      document.addEventListener('keydown', function(e){
        const k = String(e.key || '').toLowerCase();
        const mod = e.ctrlKey || e.metaKey;
        if (mod && ['s','p','u'].includes(k)) return stopAction(e);
        if (mod && e.shiftKey && ['s','p'].includes(k)) return stopAction(e);
        if (e.key === 'PrintScreen') { try { if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(''); } catch(_) {} }
      }, true);
      // Evita que la propia aplicación abra una vista imprimible.
      window.addEventListener('beforeprint', stopAction, true);
      window.addEventListener('print', stopAction, true);
      // Evita arrastrar imágenes o enlaces para guardarlos desde la interfaz.
      document.addEventListener('dragstart', function(e){
        if (e.target && e.target.closest && e.target.closest('img, a')) stopAction(e);
      }, true);
    })();
    </script>

    <!-- Manifiesto PWA creado en el navegador para evitar la verificación JS de InfinityFree. -->
    <script>
    (() => {
      try {
        const base = new URL('./', window.location.href);
        const manifest = {
          id: base.href,
          name: 'El Radar - Transporte',
          short_name: 'El Radar',
          description: 'Aplicación de transporte, solicitudes de viaje y notificaciones.',
          start_url: base.href,
          scope: base.href,
          display: 'standalone',
          display_override: ['standalone', 'minimal-ui'],
          orientation: 'any',
          background_color: '#f4f6fb',
          theme_color: '#172554',
          icons: [
            {src: new URL('./icon-192.png', base).href, sizes: '192x192', type: 'image/png', purpose: 'any'},
            {src: new URL('./icon-512.png', base).href, sizes: '512x512', type: 'image/png', purpose: 'any maskable'}
          ]
        };
        const blob = new Blob([JSON.stringify(manifest)], {type: 'application/manifest+json'});
        const manifestUrl = URL.createObjectURL(blob);
        const link = document.createElement('link');
        link.rel = 'manifest';
        link.href = manifestUrl;
        document.head.appendChild(link);
        window.addEventListener('pagehide', () => URL.revokeObjectURL(manifestUrl), {once: true});
      } catch (error) {
        console.error('No se pudo crear el manifiesto PWA:', error);
      }
    })();
    </script>
    <meta name="theme-color" content="#172554">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="El Radar">
    <link rel="apple-touch-icon" href="./icon-192.png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#10132b">
<link rel="icon" href="./index.php?radar_icon=192" type="image/png">
<title>El Radar | Movilidad inteligente</title>

<style>
:root {
    --bg: #f4f5fc;
    --dark: #10132b;
    --dark2: #191d3b;
    --blue: #5b7cfa;
    --purple: #8b5cf6;
    --cyan: #48d8f0;
    --text: #222541;
    --muted: #8b91aa;
    --border: #eaebf5;
    --white: #fff;
    --green: #20c997;
    --shadow: 0 12px 35px rgba(35, 39, 85, .07);
}

* { box-sizing: border-box; }

body {
    margin: 0;
    background: var(--bg);
    color: var(--text);
    font-family: Inter, "Segoe UI", Arial, sans-serif;
}

button, input, select { font: inherit; }
button, a { -webkit-tap-highlight-color: transparent; }

.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: 260px;
    background: linear-gradient(165deg, #11142e 0%, #1b2046 58%, #252056 100%);
    color: white;
    padding: 25px 17px 20px;
    display: flex;
    flex-direction: column;
    z-index: 100;
    overflow-y: auto;
}

.brand {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 4px 9px 28px;
    border-bottom: 1px solid #ffffff16;
}

.brand-logo {
    width: 48px;
    height: 48px;
    display: block;
    flex: 0 0 48px;
    object-fit: cover;
    border-radius: 14px;
    background: #06285a;
    box-shadow: 0 7px 24px #5c7cff55;
}
.about-hero .brand-logo { width: 88px; height: 88px; flex-basis: 88px; border-radius: 22px; }

.brand h2 {
    font-size: 21px;
    letter-spacing: -.7px;
    margin: 0;
}

.brand p {
    margin: 5px 0 0;
    font-size: 11px;
    color: #aeb7e3;
    letter-spacing: .6px;
}

.menu-label {
    color: #858dbb;
    font-size: 10px;
    letter-spacing: 1.7px;
    font-weight: 800;
    margin: 29px 12px 13px;
}

.nav {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.nav-link[hidden] { display: none !important; }

.nav-link {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 13px;
    border: 1px solid transparent;
    border-radius: 13px;
    padding: 14px 13px;
    color: #bfc5e5;
    background: transparent;
    text-decoration: none;
    text-align: left;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: .22s ease;
}

.nav-link:hover {
    color: white;
    background: #ffffff0c;
    transform: translateX(2px);
}

.nav-link.active {
    color: white;
    background: linear-gradient(100deg, #6278f7, #7858e8);
    border-color: #a7a4ff55;
    box-shadow: 0 8px 22px #5f67f642;
}

.nav-icon, .submenu-icon {
    width: 22px;
    height: 22px;
    flex: 0 0 22px;
    display: inline-grid;
    place-items: center;
    color: currentColor;
}
.nav-icon svg, .submenu-icon svg {
    width: 20px;
    height: 20px;
    display: block;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
}

.nav-toggle .chevron {
    margin-left: auto;
    transition: transform .2s;
}

.nav-toggle[aria-expanded="true"] .chevron {
    transform: rotate(180deg);
}

.submenu {
    display: none;
    flex-direction: column;
    gap: 5px;
    margin: 0 0 4px 21px;
    padding-left: 14px;
    border-left: 1px solid #626991;
}

.submenu.open { display: flex; }
/* Oculta de forma efectiva los submenús no autorizados, incluso cuando usan display:flex. */
#adminSubmenu a[hidden] { display: none !important; }

.submenu a {
    color: #b9c1e0;
    text-decoration: none;
    font-size: 12px;
    border-radius: 9px;
    padding: 10px 11px;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: .2s;
}

.submenu a:hover,
.submenu a.active {
    color: white;
    background: #ffffff12;
}

.sidebar-promo {
    margin-top: auto;
    padding: 18px 15px;
    border: 1px solid #ffffff15;
    border-radius: 16px;
    background: linear-gradient(135deg, #ffffff0e, #ffffff04);
}

.promo-icon { font-size: 25px; }

.sidebar-promo strong {
    display: block;
    margin: 10px 0 5px;
    font-size: 12px;
}

.sidebar-promo p {
    margin: 0;
    color: #aeb7d9;
    font-size: 11px;
    line-height: 1.6;
}

.sidebar-footer {
    color: #7f87af;
    font-size: 10px;
    padding: 20px 8px 0;
}

.main {
    min-height: 100vh;
    margin-left: 260px;
}

.topbar {
    height: 82px;
    padding: 0 34px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    background: #ffffffd9;
    backdrop-filter: blur(18px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 50;
}

.topbar-left {
    display: flex;
    align-items: center;
    gap: 12px;
}

.topbar h1 {
    font-size: 21px;
    margin: 0;
    letter-spacing: -.5px;
}

.topbar-subtitle {
    font-size: 11px;
    color: var(--muted);
    margin-top: 6px;
}

.top-actions {
    display: flex;
    align-items: center;
    gap: 19px;
}

.icon-button {
    width: 40px;
    height: 40px;
    border: 1px solid var(--border);
    background: white;
    border-radius: 13px;
    cursor: pointer;
    font-size: 17px;
    position: relative;
}

.notification-count {
    position: absolute;
    top: -6px;
    right: -6px;
    min-width: 19px;
    height: 19px;
    padding: 0 5px;
    display: grid;
    place-items: center;
    border-radius: 999px;
    background: #e83f68;
    color: #fff;
    border: 2px solid #fff;
    font-size: 10px;
    font-weight: 800;
    line-height: 1;
}
.notification-count[hidden] { display: none !important; }

.push-activation-overlay { position:fixed; inset:0; z-index:500; display:flex; align-items:center; justify-content:center; padding:18px; background:rgba(14,17,42,.62); backdrop-filter:blur(6px); }
.push-activation-overlay[hidden] { display:none !important; }
.push-activation-dialog { width:min(100%,440px); padding:28px; border-radius:24px; background:#fff; color:#202541; box-shadow:0 28px 90px #080b2f55; text-align:center; animation:detailsRise .22s ease-out; }
.push-activation-icon { width:66px; height:66px; margin:0 auto 16px; display:grid; place-items:center; border-radius:21px; background:#eef1ff; font-size:31px; }
.push-activation-dialog h2 { margin:0 0 10px; font-size:22px; }
.push-activation-dialog p { margin:0 0 20px; color:#666c86; font-size:13px; line-height:1.65; }
.push-activation-actions { display:flex; flex-direction:column; gap:9px; }
.push-activation-primary,.push-activation-later { min-height:44px; border:0; border-radius:12px; padding:12px 16px; font-weight:800; cursor:pointer; }
.push-activation-primary { background:#5368e8; color:#fff; }
.push-activation-later { background:#f1f3fa; color:#555d7b; }
.push-activation-note { display:block; margin-top:14px; color:#9298ad; font-size:10px; line-height:1.5; }

.notifications-overlay {
    position: fixed;
    inset: 0;
    z-index: 420;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 18px;
    background: rgba(14, 17, 42, .56);
    backdrop-filter: blur(5px);
}
.notifications-overlay[hidden] { display: none !important; }
.notifications-backdrop { position: absolute; inset: 0; }
.notifications-dialog {
    position: relative;
    z-index: 1;
    width: min(100%, 560px);
    max-height: min(82vh, 760px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid #ffffffb8;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 28px 90px #080b2f55;
    animation: detailsRise .22s ease-out;
}
.notifications-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    padding: 22px 22px 16px;
    border-bottom: 1px solid var(--border);
}
.notifications-header h2 { margin: 0 0 5px; font-size: 19px; color: var(--text); }
.notifications-header p { margin: 0; color: var(--muted); font-size: 11px; line-height: 1.5; }
.notifications-header-actions { display: flex; align-items: center; gap: 8px; }
.notifications-mark-all { border: 0; background: #eef1ff; color: #4c5ec3; padding: 9px 11px; border-radius: 10px; font-size: 10px; font-weight: 800; cursor: pointer; }
.notifications-close { width: 34px; height: 34px; border: 1px solid var(--border); background: #fff; border-radius: 10px; color: #656a83; font-size: 22px; cursor: pointer; }
.notifications-summary { padding: 10px 22px 0; font-size: 10px; color: var(--muted); }
.notifications-list { padding: 12px 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 9px; }
.notification-item { width: 100%; display: grid; grid-template-columns: 36px minmax(0,1fr); gap: 11px; padding: 13px; text-align: left; border: 1px solid var(--border); border-radius: 14px; background: #fff; cursor: pointer; color: var(--text); }
.notification-item.unread { background: #f5f7ff; border-color: #dfe5ff; }
.notification-item-icon { width: 35px; height: 35px; display: grid; place-items: center; border-radius: 11px; background: #eef1ff; font-size: 17px; }
.notification-item-icon.notification-item-avatar { overflow:hidden; padding:0; background:#eef1ff; }
.notification-item-icon.notification-item-avatar img { width:100%; height:100%; display:block; object-fit:cover; border-radius:inherit; }
.notification-item[data-type="success"] .notification-item-icon { background: #e5f8ee; }
.notification-item[data-type="warning"] .notification-item-icon { background: #fff4d8; }
.notification-item[data-type="error"] .notification-item-icon { background: #ffebef; }
.notification-item-copy { min-width: 0; }
.notification-item-title { display: flex; align-items: center; gap: 7px; font-size: 12px; font-weight: 800; margin: 1px 0 5px; }
.notification-unread-marker { width: 7px; height: 7px; border-radius: 50%; background: #5b7cfa; flex: 0 0 auto; }
.notification-item-message { font-size: 11px; line-height: 1.6; color: #666c86; white-space: normal; overflow-wrap: anywhere; }
.notification-item-date { margin-top: 7px; font-size: 9px; color: #9ba0b5; }
.notifications-empty { text-align: center; padding: 36px 20px; color: #8187a0; font-size: 12px; line-height: 1.7; }
@media(max-width:520px) {
  .notifications-overlay { padding: 9px; align-items: flex-end; }
  .notifications-dialog { max-height: 88vh; border-radius: 20px 20px 15px 15px; }
  .notifications-header { padding: 18px 15px 13px; }
  .notifications-mark-all { max-width: 93px; line-height: 1.3; }
  .notifications-list { padding: 11px 10px 14px; }
}

.profile {
    display: flex;
    align-items: center;
    gap: 10px;
}

.avatar {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 15px;
    background: linear-gradient(135deg, #d4ddff, #e7d8ff);
    color: #6557c9;
    font-weight: 800;
    overflow: hidden;
}
.avatar img { width:100%; height:100%; display:block; object-fit:cover; border-radius:inherit; }

.profile strong { font-size: 12px; }
.profile small {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 10px;
}

.content {
    max-width: 1500px;
    padding: 30px 34px 45px;
    margin: auto;
}

.page { display: none; }
.page.active { display: block; animation: appear .3s ease; }

@keyframes appear {
    from { opacity: 0; transform: translateY(7px); }
    to { opacity: 1; transform: translateY(0); }
}

.hero {
    min-height: 220px;
    position: relative;
    overflow: hidden;
    padding: 32px 35px;
    border-radius: 25px;
    background: linear-gradient(115deg, #4b62e8, #7758e8 55%, #a65be9);
    color: white;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 20px;
    box-shadow: 0 16px 35px #705be52b;
}

.hero::before,
.hero::after {
    content: "";
    position: absolute;
    border-radius: 50%;
    border: 1px solid #ffffff21;
    pointer-events: none;
}

.hero::before {
    width: 320px;
    height: 320px;
    right: 60px;
    top: -170px;
}

.hero::after {
    width: 240px;
    height: 240px;
    right: -60px;
    bottom: -140px;
}

.hero-content {
    position: relative;
    z-index: 2;
}

.eyebrow {
    font-size: 10px;
    font-weight: 800;
    letter-spacing: 2px;
    text-transform: uppercase;
    color: #e0e5ff;
}

.hero h2 {
    font-size: clamp(24px, 3vw, 34px);
    letter-spacing: -1.1px;
    margin: 14px 0 11px;
}

.hero p {
    max-width: 470px;
    color: #e9eaff;
    font-size: 13px;
    line-height: 1.8;
    margin: 0;
}

.hero-art {
    position: relative;
    z-index: 1;
    width: 150px;
    height: 150px;
    flex-shrink: 0;
    display: grid;
    place-items: center;
    border-radius: 42px;
    background: #ffffff16;
    border: 1px solid #ffffff35;
    box-shadow: inset 0 0 40px #ffffff0e;
    transform: rotate(-7deg);
}

.hero-art span {
    font-size: 70px;
    filter: drop-shadow(0 10px 12px #26215c55);
    transform: rotate(7deg);
}

.section-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 15px;
    margin: 29px 0 16px;
}

.section-heading h2 {
    margin: 0;
    font-size: 17px;
    letter-spacing: -.4px;
}

.section-heading p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 11px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 17px;
    margin-top: 23px;
}

.admin-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 19px;
    padding: 21px;
    min-width: 0;
    box-shadow: var(--shadow);
    transition: transform .2s, box-shadow .2s;
}

.stat-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 18px 35px #292d5c10;
}

.stat-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
}

.stat-icon {
    width: 47px;
    height: 47px;
    border-radius: 15px;
    display: grid;
    place-items: center;
    font-size: 22px;
}

.purple { background: #eee9ff; }
.blue { background: #e6efff; }
.cyan { background: #dffaff; }
.green { background: #def9ee; }

.stat-tag {
    font-size: 9px;
    font-weight: 800;
    color: #13a778;
    background: #e3f9f0;
    border-radius: 20px;
    padding: 6px 8px;
}

.stat-label {
    font-size: 11px;
    color: var(--muted);
    margin-top: 19px;
}

.stat-value {
    font-size: 27px;
    font-weight: 800;
    margin-top: 7px;
    letter-spacing: -.8px;
}

.stat-foot {
    font-size: 10px;
    color: #969bb1;
    margin-top: 8px;
}

/* Revisión de solicitudes de cuenta transportista en Administración */
.admin-requests-panel { margin-top: 22px; }
.admin-requests-heading { display:flex; align-items:center; justify-content:space-between; gap:14px; margin-bottom:15px; }
.admin-requests-heading h3 { margin:0; font-size:15px; }
.admin-requests-heading p { margin:6px 0 0; color:var(--muted); font-size:11px; line-height:1.6; }
.admin-requests-status { color:var(--muted); font-size:11px; margin:0 0 12px; }
.admin-request-tabs { display:flex; flex-wrap:wrap; gap:8px; margin:0 0 15px; padding:5px; border:1px solid var(--border); border-radius:13px; background:#f7f8fc; }
.admin-request-tab { flex:1 1 140px; border:0; border-radius:9px; padding:11px 14px; color:var(--muted); background:transparent; font:inherit; font-size:12px; font-weight:800; cursor:pointer; }
.admin-request-tab.active { color:#fff; background:linear-gradient(110deg,#545fce,#7984f4); box-shadow:0 4px 12px #545fce2b; }
.admin-request-tab:focus-visible { outline:3px solid #8e98ff; outline-offset:2px; }
.app-settings-tabs { margin-top:18px; }
.app-settings-panel { padding:16px 4px 4px; border-top:1px solid var(--border); }
.app-settings-panel h3 { margin:0 0 10px; }
.admin-tariff-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;margin:18px 0}.admin-tariff-card{border:1px solid var(--border);border-radius:14px;padding:18px;background:var(--card,transparent)}.admin-tariff-card h4{margin:0 0 16px}.admin-tariff-period{display:grid;grid-template-columns:minmax(80px,1fr) minmax(110px,1fr);gap:8px}.admin-tariff-period input,.admin-tariff-period select{min-width:0;width:100%}#adminTariffsStatus{margin-top:12px}

.admin-request-approved-badge { flex:0 0 auto; padding:6px 9px; border-radius:99px; background:#dcfce7; color:#166534; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.45px; }
.admin-request-revoked-badge { flex:0 0 auto; padding:6px 9px; border-radius:99px; background:#fee2e2; color:#991b1b; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.45px; }
.admin-requests-list { display:grid; gap:15px; }

.finance-transfer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px;margin-top:12px}
.finance-transfer-card{border:1px solid var(--border);border-radius:16px;padding:16px;background:var(--card,#fff);box-shadow:0 4px 14px rgba(25,35,80,.05);display:grid;gap:10px;min-width:0}
.finance-transfer-card-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.finance-transfer-id{font-weight:800;overflow-wrap:anywhere}
.finance-transfer-status{font-size:11px;font-weight:800;padding:6px 9px;border-radius:99px;background:#fef3c7;color:#92400e;white-space:nowrap}
.finance-transfer-status.is-confirmed{background:#dcfce7;color:#166534}
.finance-transfer-amount{font-size:22px;font-weight:850;letter-spacing:-.4px}
.finance-transfer-meta{display:grid;gap:5px;font-size:12px;color:var(--muted);overflow-wrap:anywhere}
.finance-transfer-card .btn{width:100%;justify-content:center}
.finance-user-picker-overlay{position:fixed;inset:0;z-index:10070;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,20,45,.68);backdrop-filter:blur(4px);overflow-y:auto}
.finance-user-picker-overlay[hidden]{display:none!important}
.finance-user-picker-dialog{width:min(100%,900px);max-height:min(92vh,900px);overflow-y:auto;margin:auto;padding:22px;border-radius:20px;background:#fff;color:#172033;box-shadow:0 24px 80px rgba(0,0,0,.3)}
.finance-user-picker-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}
.finance-user-picker-header h3{margin:0 0 6px}
.finance-user-picker-search-label{display:block;font-size:12px;font-weight:700;margin:0 0 6px}
.finance-user-picker-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:12px;margin-top:12px}
.finance-user-choice-card{border:1px solid #dfe4ee;border-radius:14px;padding:13px;display:flex;gap:12px;align-items:flex-start;background:#fff;min-width:0}
.finance-user-choice-card img{width:58px;height:58px;flex:0 0 58px;object-fit:cover;border-radius:12px;background:#eef1f8}
.finance-user-choice-info{min-width:0;flex:1}
.finance-user-choice-info h4{margin:0 0 3px;font-size:14px;overflow-wrap:anywhere}
.finance-user-choice-info p{margin:3px 0;font-size:11px;color:#647084;overflow-wrap:anywhere}
.finance-user-choice-info .btn{margin-top:8px;width:100%;justify-content:center}
@media(max-width:520px){.finance-user-picker-overlay{padding:8px}.finance-user-picker-dialog{padding:15px;border-radius:15px}.finance-transfer-grid,.finance-user-picker-grid{grid-template-columns:1fr}}

.editor-payment-card{position:relative;overflow:hidden;padding:17px;border:1px solid #dfe7f3;border-radius:20px;background:linear-gradient(155deg,#fff 0%,#f7faff 100%);box-shadow:0 8px 24px rgba(23,48,87,.08);transition:transform .2s ease,box-shadow .2s ease;}
.editor-payment-card:hover{transform:translateY(-3px);box-shadow:0 15px 32px rgba(23,48,87,.14)}
.editor-payment-card:before{content:"";position:absolute;top:0;left:0;right:0;height:5px;background:linear-gradient(90deg,#2457a6,#38b98a)}
.editor-payment-card-head{display:flex;align-items:center;gap:13px;margin:3px 0 15px}
.editor-payment-avatar{width:68px;height:68px;flex:0 0 68px;border-radius:18px;object-fit:cover;border:3px solid #fff;box-shadow:0 4px 14px #1b376126;background:#eaf0f8}
.editor-payment-identity{min-width:0}.editor-payment-identity h3{margin:0;color:#1c3152;font-size:16px;font-weight:900;overflow-wrap:anywhere}.editor-payment-identity p{margin:4px 0;color:#728098;font-size:12px}.editor-payment-status{display:inline-block;margin-top:3px;padding:4px 8px;border-radius:99px;background:#e7f8ef;color:#187347;font-size:9px;font-weight:900;text-transform:uppercase}
.editor-payment-card-number{padding:11px 13px;border-radius:13px;background:linear-gradient(115deg,#172d51,#294c7f);color:#fff}.editor-payment-card-number small{display:block;font-size:9px;letter-spacing:.1em;opacity:.75;font-weight:850}.editor-payment-card-number strong{display:block;margin-top:4px;font-size:14px;letter-spacing:.07em}
.editor-payment-metrics{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px}.editor-payment-metric{padding:10px;border:1px solid #e8edf5;border-radius:12px;background:#fff;min-width:0}.editor-payment-metric.pending{grid-column:1/-1;background:linear-gradient(120deg,#ecfbf3,#e0f7ea);border-color:#c8ecd8}.editor-payment-metric small{display:block;color:#758199;font-size:9px;font-weight:800}.editor-payment-metric strong{display:block;margin-top:4px;color:#263b5b;font-size:16px;font-weight:950;overflow-wrap:anywhere}.editor-payment-metric.pending strong{color:#087443;font-size:22px}
.editor-payment-contact{min-height:32px;margin:11px 0;color:#718096;font-size:10px;line-height:1.5;overflow-wrap:anywhere}.editor-payment-action{width:100%;justify-content:center}.editor-payment-action:disabled{opacity:.58;cursor:not-allowed}
.editor-payment-modal{position:fixed;inset:0;z-index:11010;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(12,24,48,.68);backdrop-filter:blur(6px)}.editor-payment-modal-dialog{width:min(100%,460px);padding:23px;border-radius:21px;background:#fff;box-shadow:0 24px 75px rgba(0,0,0,.28);animation:editorSalaryPop .35s ease}.editor-payment-modal-dialog h3{margin:0;color:#1d3152;font-size:21px}.editor-payment-modal-person{margin:6px 0 15px;color:#758199;font-size:12px}.editor-payment-modal-amount{padding:14px;border-radius:14px;background:linear-gradient(120deg,#e9f8ef,#d9f3e4)}.editor-payment-modal-amount small{display:block;color:#24734d;font-size:9px;font-weight:900;letter-spacing:.08em}.editor-payment-modal-amount strong{display:block;margin-top:3px;color:#087443;font-size:27px;font-weight:950}.editor-payment-modal-card{font-size:12px;color:#51627b}.editor-payment-modal-dialog label{display:block;margin:15px 0 7px;color:#33415d;font-size:12px;font-weight:850}.editor-payment-modal-dialog input{width:100%;box-sizing:border-box;padding:13px;border:1px solid #ccd5e3;border-radius:12px;font-size:16px;outline:none}.editor-payment-modal-dialog input:focus{border-color:#3b70c7;box-shadow:0 0 0 3px #3b70c71c}.editor-payment-modal-status{min-height:18px;color:#b42318;font-size:11px}.editor-payment-modal-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap;margin-top:10px}
@media(max-width:520px){.editor-payment-modal-dialog{padding:18px;border-radius:17px}.editor-payment-metrics{grid-template-columns:1fr 1fr}}
.admin-users-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:14px; }
.admin-user-card { display:flex; align-items:center; gap:13px; padding:14px; border:1px solid var(--border); border-radius:14px; background:#fff; box-shadow:0 3px 12px rgba(25,35,80,.05); }
.admin-user-avatar { width:64px; height:64px; flex:0 0 64px; border-radius:8px; object-fit:cover; background:#eef1f8; }
.admin-user-main { min-width:0; flex:1; }
.admin-user-main h3 { margin:0 0 4px; overflow-wrap:anywhere; }
.admin-user-main p { margin:3px 0; overflow-wrap:anywhere; }
.admin-user-readonly { padding:12px; margin:0 0 16px; border-radius:10px; background:#f6f7fb; }
.admin-user-editor-overlay { position:fixed; inset:0; z-index:10050; display:flex; align-items:center; justify-content:center; padding:20px; background:rgba(15,20,45,.62); backdrop-filter:blur(4px); overflow-y:auto; }
.admin-user-editor-overlay[hidden] { display:none !important; }
.admin-user-editor-dialog { width:min(100%,560px); max-height:min(90vh,850px); overflow-y:auto; position:relative; margin:auto; padding:24px; border-radius:20px; background:#fff; box-shadow:0 24px 80px rgba(0,0,0,.28); }
.admin-user-editor-header { display:flex; align-items:flex-start; justify-content:space-between; gap:14px; margin-bottom:16px; }
.admin-user-editor-header h3 { margin:0 0 6px; }
.admin-user-editor-close { width:36px; height:36px; flex:0 0 36px; border:0; border-radius:10px; background:#f0f2f8; font-size:24px; line-height:1; cursor:pointer; }
.admin-user-editor-identity { display:flex; align-items:center; gap:16px; margin:0 0 18px; padding:15px; border:1px solid var(--border); border-radius:14px; background:#f8f9fd; }
.admin-user-editor-photo { width:94px; height:94px; flex:0 0 94px; object-fit:cover; border-radius:10px; border:1px solid var(--border); background:#eef1f8; }
.admin-user-editor-identity h4 { margin:0 0 5px; overflow-wrap:anywhere; }
.admin-user-editor-identity p { margin:0; color:var(--muted); overflow-wrap:anywhere; }
@media(max-width:520px){ .admin-user-editor-overlay{padding:10px}.admin-user-editor-dialog{padding:17px;border-radius:15px}.admin-user-editor-photo{width:76px;height:76px;flex-basis:76px} }
.admin-user-readonly p { margin:6px 0; }
.admin-request-card { min-width:0; padding:0; border:1px solid var(--border); border-radius:14px; background:#fff; box-shadow:0 3px 12px rgba(25,35,80,.05); overflow:hidden; }
.admin-request-details { min-width:0; }
.admin-request-card-head { display:flex; align-items:center; gap:10px; min-width:0; margin:0; padding:11px 13px; cursor:pointer; list-style:none; user-select:none; transition:background .18s ease; }
.admin-request-card-head::-webkit-details-marker { display:none; }
.admin-request-card-head::marker { content:""; }
.admin-request-card-head:hover { background:#f8f9ff; }
.admin-request-chevron { flex:0 0 22px; width:22px; height:22px; display:grid; place-items:center; border-radius:7px; background:#f1f3fb; color:#5f668a; font-size:16px; line-height:1; transition:transform .18s ease; }
.admin-request-details[open] .admin-request-chevron { transform:rotate(180deg); }
.admin-request-avatar { width:42px; height:42px; flex:0 0 42px; overflow:hidden; display:grid; place-items:center; border-radius:12px; background:#edf0ff; color:#545fce; font-size:16px; font-weight:800; }
.admin-request-avatar img { width:100%; height:100%; object-fit:cover; }
.admin-request-person { flex:1; min-width:0; }
.admin-request-person h4 { margin:0; font-size:13px; overflow-wrap:anywhere; }
.admin-request-person p { margin:3px 0 0; color:var(--muted); font-size:10px; overflow-wrap:anywhere; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.admin-request-pending-badge { flex:0 0 auto; padding:6px 9px; border-radius:99px; background:#fff3d7; color:#966400; font-size:9px; font-weight:800; text-transform:uppercase; letter-spacing:.45px; }
.admin-request-body { display:grid; grid-template-columns:minmax(0,1fr) minmax(210px,270px); gap:14px; align-items:start; padding:0 13px 13px; }
.admin-request-fields { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; min-width:0; }
.admin-request-field { padding:11px; border:1px solid #edf0f7; border-radius:11px; min-width:0; background:#fbfcff; }
.admin-request-field-label { display:block; color:var(--muted); font-size:10px; margin-bottom:5px; }
.admin-request-field-value { display:block; color:#282e50; font-size:12px; line-height:1.45; font-weight:700; overflow-wrap:anywhere; white-space:pre-wrap; }
.admin-request-vehicle-photo { min-width:0; }
.admin-request-vehicle-photo-label { display:block; margin:0 0 7px; font-size:11px; font-weight:800; color:#424966; }
.admin-request-vehicle-photo img { display:block; width:100%; max-height:260px; object-fit:contain; border-radius:13px; background:#f3f5fb; border:1px solid var(--border); }
.admin-request-photo-empty { display:grid; place-items:center; min-height:130px; padding:14px; text-align:center; color:var(--muted); font-size:11px; border:1px dashed var(--border); border-radius:13px; background:#f8f9fd; }
.admin-request-actions { display:flex; justify-content:flex-end; flex-wrap:wrap; gap:8px; margin:0 13px 13px; padding-top:11px; border-top:1px solid #edf0f7; }
.admin-request-actions .btn { padding:9px 13px; font-size:11px; }
.btn-admin-approve { background:linear-gradient(110deg,#13885f,#20ab76); box-shadow:0 7px 17px #13885f22; }
.btn-admin-reject { background:linear-gradient(110deg,#c43752,#a51f3c); box-shadow:0 7px 17px #c4375222; }
.admin-request-actions .btn:disabled { opacity:.55; cursor:wait; transform:none; }
.admin-requests-empty { padding:22px; border:1px dashed var(--border); border-radius:16px; color:var(--muted); background:#fafbff; font-size:12px; line-height:1.7; text-align:center; }

.dashboard-grid {
    display: grid;
    grid-template-columns: 1.3fr 1fr;
    gap: 18px;
    margin-top: 20px;
}

.card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 23px;
    box-shadow: var(--shadow);
    min-width: 0;
}

.card h3 {
    font-size: 15px;
    margin: 0;
}

.card-description {
    color: var(--muted);
    font-size: 11px;
    line-height: 1.7;
    margin: 8px 0 20px;
}

/* Mapa GPS real (Leaflet + OpenStreetMap) */
.mobility-map-tools {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    flex-wrap:wrap;
    margin:0 0 10px;
}
.map-vehicle-filter {
    display:flex;
    flex-direction:column;
    gap:6px;
    margin:0 0 10px;
}
.map-vehicle-filter label {
    color:var(--muted);
    font-size:11px;
    font-weight:750;
}
.map-vehicle-filter select {
    width:100%;
    min-height:42px;
    padding:10px 12px;
    border:1px solid var(--line, #dce2ed);
    border-radius:11px;
    background:var(--card, #fff);
    color:var(--text, #202842);
    font:inherit;
    font-size:12px;
    outline:none;
}
.map-vehicle-filter select:focus { border-color:#6478ee; box-shadow:0 0 0 3px rgba(100,120,238,.12); }
.gps-status {
    display:block;
    flex:1 1 180px;
    color:#68708a;
    font-size:11px;
    line-height:1.6;
}
.gps-status.active { color:#138c68; }
.gps-status.error { color:#b42342; }
.btn-map-location {
    flex:0 0 auto;
    padding:10px 13px;
    border-radius:10px;
    font-size:11px;
    box-shadow:none;
}
.map-actions-right {
    display:flex;
    align-items:center;
    justify-content:flex-end;
    flex:0 0 auto;
    gap:9px;
    flex-wrap:wrap;
}
.map-status-control {
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:38px;
    padding:6px 10px;
    border:1px solid #dce5e8;
    border-radius:11px;
    background:#fff;
    color:#344054;
    font-size:11px;
    font-weight:800;
    cursor:pointer;
    user-select:none;
    white-space:nowrap;
}
.map-status-control.active {
    border-color:#b9ead8;
    background:#f0fdf7;
    color:#138c68;
}
.map-status-control.inactive {
    border-color:#f2cfd5;
    background:#fff7f8;
    color:#b42342;
}
.map-status-control.saving { opacity:.65; cursor:wait; }
.map-switch {
    position:relative;
    display:inline-block;
    width:38px;
    height:22px;
    flex:0 0 38px;
}
.map-switch input {
    position:absolute;
    width:1px;
    height:1px;
    opacity:0;
    margin:0;
}
.map-switch-slider {
    position:absolute;
    inset:0;
    border-radius:999px;
    background:#aab1c0;
    transition:background .18s ease;
}
.map-switch-slider::before {
    content:"";
    position:absolute;
    left:2px;
    top:2px;
    width:18px;
    height:18px;
    border-radius:50%;
    background:#fff;
    box-shadow:0 1px 3px #10182833;
    transition:transform .18s ease;
}
.map-switch input:checked + .map-switch-slider { background:#20b486; }
.map-switch input:checked + .map-switch-slider::before { transform:translateX(16px); }
.map-switch input:focus-visible + .map-switch-slider { outline:3px solid #5b7cfa55; outline-offset:3px; }
#mobilityMap.account-inactive {
    display:none !important;
}
.mobility-map {
    position:relative;
    z-index:0;
    width:100%;
    height:330px;
    min-height:260px;
    border-radius:15px;
    overflow:hidden;
    background:#e7edf4;
    border:1px solid #e1e6f1;
}
.gps-address-panel {
    display:flex;
    align-items:flex-start;
    gap:10px;
    margin-top:12px;
    padding:12px 14px;
    border:1px solid #e1e6f1;
    border-radius:12px;
    background:#f8faff;
    color:#25304b;
}
.gps-address-icon { font-size:19px; line-height:1.35; }
.gps-address-panel strong { display:block; font-size:11px; margin-bottom:4px; }
.gps-address-panel p { margin:0; color:#68708a; font-size:12px; line-height:1.6; overflow-wrap:anywhere; }
.leaflet-control-attribution { font-size:9px!important; opacity:.8; }
.map-privacy-note {
    display:block;
    color:var(--muted);
    font-size:10px;
    line-height:1.6;
    margin-top:9px;
}
.gps-div-icon { background:transparent!important; border:0!important; cursor:default!important; pointer-events:none!important; }
.gps-div-icon .gps-marker-wrap, .gps-div-icon .gps-avatar-marker, .gps-div-icon .gps-avatar-marker img { cursor:default!important; pointer-events:none!important; }
.gps-marker-wrap {
    width:56px;
    height:63px;
    position:relative;
    display:flex;
    align-items:flex-start;
    justify-content:center;
    filter:drop-shadow(0 4px 5px #1a27434d);
}
.gps-avatar-marker {
    width:49px;
    height:49px;
    border:3px solid #fff;
    border-radius:50%;
    overflow:hidden;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    background:linear-gradient(135deg,#5b7cfa,#8b5cf6);
    font-size:19px;
    font-weight:800;
    box-shadow:0 2px 9px #14213d3d;
}
.gps-avatar-marker img { width:100%;height:100%;object-fit:cover;display:block; }
.gps-marker-pointer {
    position:absolute;
    left:21px;
    top:42px;
    width:14px;
    height:14px;
    background:#fff;
    transform:rotate(45deg);
    border-radius:2px;
    z-index:-1;
}

.nearby-transport-status{display:flex;align-items:center;gap:8px;margin-top:10px;padding:9px 12px;border:1px solid #e1e6f1;border-radius:10px;background:#f8faff;color:#47516d;font-size:12px;font-weight:650;line-height:1.5}
.nearby-transport-status::before{content:"🚕";font-size:16px}
.transport-div-icon{background:transparent!important;border:0!important;cursor:pointer!important;pointer-events:auto!important}
.transport-div-icon *{pointer-events:auto!important;cursor:pointer!important}
.transport-marker-dimmed .transport-marker-wrap{opacity:1;filter:grayscale(1) drop-shadow(0 4px 5px #1a27434d);transition:filter .25s ease}
.transport-marker-wrap{width:58px;height:64px;position:relative;display:flex;justify-content:center;filter:drop-shadow(0 4px 5px #1a27434d)}
.transport-photo-marker{width:49px;height:49px;border:3px solid #fff;border-radius:13px;overflow:hidden;display:flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#159f81,#6478ee);font-size:22px;font-weight:800;box-shadow:0 2px 9px #14213d3d}
.transport-photo-marker img{width:100%;height:100%;object-fit:cover;display:block}
.transport-marker-pointer{position:absolute;left:22px;top:42px;width:14px;height:14px;background:#fff;transform:rotate(45deg);border-radius:2px;z-index:-1}

.leaflet-popup-content-wrapper { border-radius:12px; }
.leaflet-popup-content { margin:10px 13px; font-size:12px; line-height:1.5; }
@media(max-width:520px) {
    .mobility-map { height:300px; }
    .btn-map-location { padding:10px 11px; }
    .map-actions-right { gap:6px; }
    .map-status-control { min-height:36px; padding:5px 8px; }
}

.status-pill {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 11px;
    background: #e4f9f0;
    color: #138c68;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 800;
}

.status-pill::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #20c997;
}
.status-pill.sync-error{background:#fff0f0;color:#b42342}
.status-pill.sync-error::before{background:#d92d45}

.activity {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 0;
    border-bottom: 1px solid #f0f1f8;
}

.activity:last-child { border-bottom: 0; }

.activity-icon {
    width: 36px;
    height: 36px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #f0edff;
    font-size: 16px;
    flex-shrink: 0;
}

.activity strong {
    font-size: 11px;
    display: block;
}

.activity p {
    color: var(--muted);
    font-size: 10px;
    line-height: 1.6;
    margin: 5px 0 0;
}

.form-card { max-width: 720px; }

.transport-request-status {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 15px 16px;
    margin: 18px 0 20px;
    border: 1px solid #e4e7f2;
    border-radius: 14px;
    background: #f8f9ff;
    color: #555c7d;
    font-size: 12px;
    line-height: 1.65;
}
.transport-request-status-icon {
    width: 32px;
    height: 32px;
    flex: 0 0 32px;
    display: grid;
    place-items: center;
    border-radius: 10px;
    background: #e8ecff;
    color: #526ff0;
    font-size: 16px;
}
.transport-request-status strong { display:block; margin-bottom:3px; color:#282e50; }
.transport-request-status[data-state="pendiente"] { background:#fff8e8; border-color:#f2dfac; }
.transport-request-status[data-state="pendiente"] .transport-request-status-icon { background:#ffedc2; color:#946200; }
.transport-request-status[data-state="transportista"],
.transport-request-status[data-state="aprobada"] { background:#effaf4; border-color:#c5ecd5; }
.transport-request-status[data-state="transportista"] .transport-request-status-icon,
.transport-request-status[data-state="aprobada"] .transport-request-status-icon { background:#d9f5e4; color:#187b49; }
.transport-request-status[data-state="rechazada"] { background:#fff2f3; border-color:#f3d1d7; }
.transport-request-status[data-state="rechazada"] .transport-request-status-icon { background:#ffe1e6; color:#a52f49; }

.form-icon {
    width: 55px;
    height: 55px;
    display: grid;
    place-items: center;
    border-radius: 17px;
    font-size: 25px;
    background: linear-gradient(135deg, #e6eaff, #f0e5ff);
    margin-bottom: 17px;
}

.form-group { margin: 19px 0; }

.form-group label {
    display: block;
    font-size: 12px;
    font-weight: 700;
    margin-bottom: 9px;
}

.form-group input,
.form-group select {
    width: 100%;
    border: 1px solid #e0e3f0;
    border-radius: 12px;
    padding: 14px 15px;
    outline: none;
    color: var(--text);
    background: #fcfcff;
    transition: .2s;
}

.form-group input:focus,
.form-group select:focus {
    border-color: var(--blue);
    box-shadow: 0 0 0 4px #5b7cfa17;
    background: white;
}

.help-text {
    display: block;
    color: var(--muted);
    font-size: 10px;
    line-height: 1.6;
    margin-top: 8px;
}

.notice {
    padding: 15px;
    background: #f0f3ff;
    color: #505d9c;
    border: 1px solid #e2e7ff;
    border-radius: 13px;
    font-size: 11px;
    line-height: 1.8;
    margin-bottom: 20px;
}

.btn {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    gap: 9px;
    border: 0;
    border-radius: 12px;
    padding: 14px 20px;
    background: linear-gradient(110deg, #5878f6, #8a58ed);
    color: white;
    font-weight: 800;
    font-size: 12px;
    cursor: pointer;
    box-shadow: 0 8px 20px #7664ed35;
    transition: transform .2s, filter .2s;
}

.btn:hover {
    transform: translateY(-2px);
    filter: brightness(1.05);
}

.about-hero {
    background: linear-gradient(130deg, #151934, #30266a);
    color: white;
    border-radius: 23px;
    padding: 32px;
}

.about-hero .brand-logo { margin-bottom: 20px; }

.about-hero h2 {
    font-size: 26px;
    margin: 0 0 12px;
}

.about-hero p {
    max-width: 600px;
    color: #c9c9ee;
    font-size: 12px;
    line-height: 1.9;
}

.toast {
    display: none;
    position: fixed;
    z-index: 300;
    right: 22px;
    bottom: 22px;
    max-width: calc(100vw - 40px);
    padding: 16px 20px;
    color: white;
    background: #202344;
    border: 1px solid #ffffff1a;
    border-radius: 14px;
    box-shadow: 0 12px 35px #15163330;
    font-size: 12px;
    line-height: 1.6;
}

.toast.show { display: block; }

.mobile-menu { display: none; }
.overlay { display: none; }

@media (max-width: 1050px) {
    .content { padding: 25px 22px; }
    .topbar { padding: 0 22px; }
    .stats { grid-template-columns: 1fr; }
    .admin-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .dashboard-grid { grid-template-columns: 1fr; }
}

@media (max-width: 700px) {
    .sidebar {
        width: 275px;
        transform: translateX(-100%);
        transition: transform .25s ease;
    }

    .sidebar.open { transform: translateX(0); }

    .overlay.open {
        display: block;
        position: fixed;
        inset: 0;
        background: #10132b85;
        z-index: 90;
    }

    .main { margin-left: 0; }

    .topbar {
        height: 72px;
        padding: 0 14px;
    }

    .mobile-menu {
        display: grid;
        place-items: center;
        border: 1px solid var(--border);
        background: white;
        width: 38px;
        height: 38px;
        border-radius: 12px;
        font-size: 20px;
        cursor: pointer;
    }

    .topbar h1 { font-size: 17px; }
    .topbar-subtitle { font-size: 10px; }
    .top-actions { gap: 8px; }
    .profile-info { display: none; }
    .content { padding: 17px 13px 30px; }

    .hero {
        padding: 25px 21px;
        min-height: 205px;
        border-radius: 20px;
    }

    .hero h2 { font-size: 24px; }
    .hero p { font-size: 11px; }
    .hero-art { width: 78px; height: 78px; border-radius: 24px; }
    .hero-art span { font-size: 40px; }

    .stat-card { padding: 18px; }
    .admin-stats { grid-template-columns: 1fr; }
    .stat-value { font-size: 25px; }
    .card { padding: 18px; }
    .section-heading { margin-top: 24px; }
    .about-hero { padding: 24px; }
}

@media (max-width: 700px) {
    .admin-requests-heading { align-items:flex-start; flex-direction:column; }
    .admin-request-body { grid-template-columns:1fr; }
    .admin-request-fields { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .admin-request-vehicle-photo img { max-height:300px; }
    .admin-request-actions { justify-content:stretch; }
    .admin-request-actions .btn { flex:1 1 130px; }
}
@media (max-width: 420px) {
    .admin-request-card { padding:0; }
    .admin-request-fields { grid-template-columns:1fr; }
    .admin-request-pending-badge { align-self:flex-start; }
    .admin-request-card-head { align-items:center; flex-wrap:nowrap; gap:8px; padding:10px; }
    .admin-request-avatar { width:38px; height:38px; flex-basis:38px; border-radius:10px; }
    .admin-request-chevron { flex-basis:20px; width:20px; height:20px; }
    .admin-request-body { padding:0 10px 10px; }
    .admin-request-actions { margin:0 10px 10px; }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
    }
}

/* Autenticación de El Radar */
.auth-screen{position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:20px;background:radial-gradient(circle at top right,#3b347d 0,#171a3b 42%,#0e1128 100%);overflow-y:auto}
.auth-card{width:min(100%,440px);background:#fff;border-radius:25px;padding:30px;box-shadow:0 25px 80px #0005}
.auth-brand{display:flex;align-items:center;gap:13px;margin-bottom:24px}.auth-brand .brand-logo{flex-shrink:0}.auth-brand h2{margin:0;color:#171a3b;font-size:24px}.auth-brand p{margin:5px 0 0;color:#8b91aa;font-size:11px;letter-spacing:.8px}
.auth-card h3{font-size:23px;margin:0 0 8px;color:#222541}
.server-status{display:flex;align-items:flex-start;gap:10px;padding:13px 14px;margin:0 0 20px;border-radius:12px;background:#f2f4f8;color:#555d73;font-size:12px;line-height:1.5}
.server-status .status-dot{width:9px;height:9px;flex:0 0 9px;margin-top:4px;border-radius:50%;background:#9aa1b2}
.server-status.connected{background:#eaf8ef;color:#17683a}.server-status.connected .status-dot{background:#20a464}
.server-status.failed{background:#fff0f0;color:#a52335}.server-status.failed .status-dot{background:#d92d45}
.server-status.checking{background:#f2f4f8;color:#555d73}
.server-status button{display:block;margin-top:8px;padding:7px 11px;border:1px solid currentColor;border-radius:8px;background:transparent;color:inherit;font-weight:700;cursor:pointer}
#authControls[hidden]{display:none!important}.auth-intro{font-size:12px;color:#8b91aa;line-height:1.7;margin:0 0 22px}
.auth-tabs{display:grid;grid-template-columns:1fr 1fr;gap:6px;background:#f1f2fa;padding:5px;border-radius:13px;margin-bottom:20px}.auth-tabs button{border:0;padding:12px;border-radius:10px;color:#7e839d;background:transparent;font-weight:800;font-size:12px;cursor:pointer}.auth-tabs button.active{color:#fff;background:linear-gradient(110deg,#5878f6,#8a58ed);box-shadow:0 5px 14px #7664ed30}
.auth-link{display:block;margin:14px auto 0;border:0;background:transparent;color:#6277e8;font-size:12px;font-weight:800;cursor:pointer;padding:8px}.auth-form[hidden]{display:none!important}.auth-form .form-group{margin:15px 0}.photo-preview{margin-top:10px}.photo-preview img{width:76px;height:76px;border-radius:18px;object-fit:cover;border:2px solid #e2e6f5}.auth-form .btn{width:100%;margin-top:7px;min-height:46px}.auth-error{display:none;margin:0 0 14px;padding:12px;border-radius:10px;background:#fff0f2;color:#b42342;font-size:12px;line-height:1.5}.auth-error.show{display:block}.auth-error.processing{background:#eef4ff;color:#4253a2;border:1px solid #dce5ff}.auth-note{font-size:10px;color:#8b91aa;line-height:1.6;margin-top:16px;text-align:center}.auth-screen[hidden]{display:none!important}
body.auth-locked{overflow:hidden}

/* Confirmaciones propias de El Radar: nunca mostrar el dominio del sitio como título */
.radar-confirm-overlay{position:fixed;inset:0;z-index:40000;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(8,13,32,.68);backdrop-filter:blur(5px)}
.radar-confirm-overlay.show{display:flex}.radar-confirm-card{width:min(100%,420px);padding:25px;background:#fff;color:#202642;border-radius:20px;box-shadow:0 24px 80px #0006}
.radar-confirm-brand{display:flex;align-items:center;gap:10px;margin-bottom:14px;color:#26345c;font-weight:900;font-size:17px}.radar-confirm-brand span{width:38px;height:38px;display:grid;place-items:center;border-radius:12px;background:#eef2ff;font-size:21px}
.radar-confirm-card h2{margin:0 0 9px;font-size:20px}.radar-confirm-card p{margin:0;color:#687087;font-size:14px;line-height:1.6;white-space:pre-line}.radar-confirm-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:22px}.radar-confirm-actions button{border:0;border-radius:10px;padding:11px 17px;font-weight:800;cursor:pointer}.radar-confirm-cancel{background:#eef0f5;color:#454d63}.radar-confirm-accept{background:#dc3545;color:#fff}
@media(max-width:420px){.radar-confirm-card{padding:21px}.radar-confirm-actions button{flex:1}}

/* Modo sin conexión: conserva visible la copia local de la aplicación. */
body.radar-offline-lock{overflow:auto!important}
body.radar-offline-lock > :not(#serverOfflineOverlay){visibility:visible!important;pointer-events:auto!important}
#serverOfflineOverlay{display:none!important}
#offlineCacheBanner{display:none;position:fixed;z-index:29999;top:0;left:0;right:0;padding:9px 14px;background:#8b2635;color:#fff;text-align:center;font-size:12px;font-weight:800;box-shadow:0 2px 12px #0002}
body.radar-is-offline #offlineCacheBanner{display:block}
body.radar-is-offline{padding-top:34px}
.server-offline-icon{width:66px;height:66px;margin:0 auto 16px;display:grid;place-items:center;border-radius:50%;background:#fff0f0;color:#d92d45;font-size:32px}
.server-offline-card h2{margin:0 0 10px;font-size:22px}.server-offline-card p{margin:0;color:#687087;font-size:14px;line-height:1.6}
.server-offline-pulse{width:9px;height:9px;display:inline-block;margin-right:7px;border-radius:50%;background:#d92d45;animation:serverOfflinePulse 1.2s infinite}
@keyframes serverOfflinePulse{50%{opacity:.25;transform:scale(.75)}}
@media(max-width:480px){.auth-card{padding:23px 19px;border-radius:20px}.auth-brand h2{font-size:21px}}

/* Corrección del registro en móviles: tarjeta desplazable y campos accesibles */
.auth-screen{
  align-items:flex-start!important;
  justify-content:center!important;
  overflow-x:hidden!important;
  overflow-y:auto!important;
  -webkit-overflow-scrolling:touch;
  padding: max(12px, env(safe-area-inset-top)) 12px max(18px, env(safe-area-inset-bottom))!important;
  box-sizing:border-box;
}
.auth-card{
  flex:0 0 auto;
  width:min(100%,440px)!important;
  max-height:none!important;
  height:auto!important;
  min-height:0;
  margin:0 auto!important;
  overflow:visible!important;
  box-sizing:border-box;
  position:relative;
  z-index:1;
}
#registerPanel,#loginPanel{width:100%;min-width:0;}
#registerForm{display:block;width:100%;min-width:0;}
#registerForm .form-group{display:block;width:100%;min-width:0;position:relative;}
#registerForm input:not([type="checkbox"]),#registerForm select{
  display:block;width:100%;max-width:100%;min-width:0;min-height:46px;
  box-sizing:border-box;position:relative;z-index:2;
  pointer-events:auto;touch-action:manipulation;
  -webkit-appearance:none;appearance:none;
}
#registerForm input[type="file"]{min-height:44px;white-space:normal;}
#registerName{opacity:1!important;visibility:visible!important;pointer-events:auto!important;}
@media(max-width:600px){
  .auth-screen{display:block!important;padding:10px 10px max(20px,env(safe-area-inset-bottom))!important;}
  .auth-card{margin:0 auto 12px!important;padding:20px 16px!important;border-radius:18px;}
  .auth-brand{margin-bottom:17px;}
  .auth-brand .brand-logo{width:48px!important;height:48px!important;}
  #registerForm .form-group{margin:12px 0;}
  #registerForm input:not([type="checkbox"]),#registerForm select{font-size:16px!important;}
}
@media(max-height:700px){
  .auth-screen{align-items:flex-start!important;}
  .auth-card{margin-top:8px!important;margin-bottom:12px!important;}
}

/* Bienvenida animada al iniciar sesión */
.welcome-screen{position:fixed;inset:0;z-index:1200;display:grid;place-items:center;padding:24px;background:radial-gradient(circle at 50% 35%,#333778 0,#191b40 42%,#0b0d22 100%);color:#fff;overflow:hidden;opacity:0}
.welcome-screen[hidden]{display:none!important}
.welcome-screen.show{animation:welcomeFadeIn .45s ease-out forwards}
.welcome-content{position:relative;z-index:2;width:min(100%,480px);text-align:center;padding:30px 18px;opacity:0;transform:translateY(20px) scale(.97)}
.welcome-screen.show .welcome-content{animation:welcomeContentIn .65s .1s cubic-bezier(.2,.75,.2,1) forwards}
.welcome-orbit{width:174px;height:174px;display:grid;place-items:center;margin:0 auto 28px;border-radius:50%;border:1px solid #a9b7ff80;box-shadow:0 0 0 12px #ffffff08,0 0 55px #7a72ff6b;position:relative}
.welcome-orbit:before,.welcome-orbit:after{content:"";position:absolute;inset:-9px;border-radius:50%;border:2px solid transparent;border-top-color:#71e4ff;border-right-color:#9a7bff;animation:welcomeSpin 3s linear infinite}
.welcome-orbit:after{inset:-18px;border-top-color:#ffffff50;border-right-color:transparent;border-bottom-color:#ffffff30;animation-duration:5s;animation-direction:reverse}
.welcome-avatar{width:142px;height:142px;display:grid;place-items:center;overflow:hidden;border-radius:50%;border:4px solid #fff;background:linear-gradient(140deg,#718bff,#a75af0);color:#fff;font-size:54px;font-weight:800;box-shadow:0 12px 40px #0005}
.welcome-avatar img{width:100%;height:100%;object-fit:cover}
.welcome-kicker{font-size:10px;letter-spacing:3px;font-weight:800;color:#9deaff;margin-bottom:14px}
.welcome-content h2{font-size:clamp(26px,5vw,39px);line-height:1.17;margin:0 auto 12px;letter-spacing:-.8px}
.welcome-name{font-size:clamp(20px,4vw,27px);font-weight:800;margin:0;color:#b9b5ff;overflow-wrap:anywhere}
.welcome-message{max-width:340px;margin:13px auto 26px;color:#d2d5f2;font-size:13px;line-height:1.8}
.welcome-continue{border:1px solid #ffffff40;border-radius:13px;padding:13px 19px;background:linear-gradient(110deg,#647cff,#995bfb);color:white;font-size:12px;font-weight:800;box-shadow:0 10px 27px #6557ed45}
.welcome-continue span{padding-left:8px}
.welcome-close{position:absolute;top:22px;right:22px;z-index:3;width:42px;height:42px;border-radius:50%;border:1px solid #ffffff45;color:#fff;background:#ffffff12;font-size:27px;line-height:1}
.welcome-progress{width:min(210px,70%);height:3px;margin:30px auto 0;background:#ffffff26;border-radius:9px;overflow:hidden}
.welcome-progress span{display:block;width:100%;height:100%;background:#a8f0ff;transform:scaleX(0);transform-origin:left}
.welcome-screen.show .welcome-progress span{animation:welcomeProgress 4.2s linear forwards}
.welcome-sparkles{position:absolute;inset:0;pointer-events:none;color:#c3c7ff}
.welcome-sparkles span{position:absolute;opacity:0;animation:welcomeTwinkle 2.4s ease-in-out infinite}
.welcome-sparkles span:nth-child(1){top:12%;left:12%;font-size:22px;animation-delay:.2s}
.welcome-sparkles span:nth-child(2){top:20%;right:11%;font-size:31px;animation-delay:.6s}
.welcome-sparkles span:nth-child(3){bottom:28%;left:8%;font-size:18px;animation-delay:1s}
.welcome-sparkles span:nth-child(4){bottom:23%;right:9%;font-size:25px;animation-delay:1.3s}
.welcome-sparkles span:nth-child(5){top:48%;right:7%;font-size:16px;animation-delay:1.6s}
@keyframes welcomeFadeIn{to{opacity:1}}
@keyframes welcomeContentIn{to{opacity:1;transform:translateY(0) scale(1)}}
@keyframes welcomeSpin{to{transform:rotate(360deg)}}
@keyframes welcomeProgress{to{transform:scaleX(1)}}
@keyframes welcomeTwinkle{0%,100%{opacity:0;transform:scale(.6) rotate(-15deg)}50%{opacity:1;transform:scale(1.15) rotate(15deg)}}
@media(prefers-reduced-motion:reduce){.welcome-screen.show,.welcome-screen.show .welcome-content,.welcome-orbit:before,.welcome-orbit:after,.welcome-sparkles span,.welcome-screen.show .welcome-progress span{animation:none!important}.welcome-screen.show,.welcome-screen.show .welcome-content{opacity:1;transform:none}.welcome-screen.show .welcome-progress span{transform:scaleX(1)}}


/* Aviso web del periodo de prueba, mostrado al terminar la bienvenida */
.trial-notice-overlay{position:fixed;inset:0;z-index:1250;display:grid;place-items:center;padding:20px;background:rgba(12,18,38,.68);backdrop-filter:blur(8px);opacity:0;animation:trialOverlayIn .25s ease forwards}
.trial-notice-overlay[hidden]{display:none!important}
.trial-notice-card{position:relative;box-sizing:border-box;width:min(100%,470px);overflow:hidden;border:1px solid #e6eaff;border-radius:26px;background:#fff;color:#172033;box-shadow:0 28px 90px #090e2b55;transform:translateY(16px) scale(.98);animation:trialCardIn .38s cubic-bezier(.2,.8,.2,1) forwards}
.trial-notice-hero{position:relative;padding:27px 26px 24px;text-align:center;color:#fff;background:radial-gradient(circle at 80% 0,#9b8cff 0,transparent 35%),linear-gradient(135deg,#435cf5,#7747d8 62%,#a04ee8)}
.trial-notice-icon{display:grid;place-items:center;width:66px;height:66px;margin:0 auto 13px;border:1px solid #ffffff80;border-radius:21px;background:#ffffff20;box-shadow:0 8px 25px #201a6c35;font-size:32px}
.trial-notice-eyebrow{font-size:10px;letter-spacing:2px;font-weight:850;opacity:.86}
.trial-notice-hero h2{font-size:clamp(24px,5vw,31px);line-height:1.15;margin:8px 0 8px;letter-spacing:-.7px}
.trial-notice-hero p{max-width:350px;margin:0 auto;color:#f0efff;font-size:13px;line-height:1.65}
.trial-notice-body{padding:22px 26px 25px}
.trial-notice-summary{display:flex;align-items:center;gap:14px;padding:15px;border:1px solid #e4e8ff;border-radius:16px;background:linear-gradient(135deg,#f7f8ff,#f4f0ff)}
.trial-notice-days{display:grid;place-items:center;flex:0 0 68px;width:68px;height:68px;border-radius:17px;background:#fff;color:#5746d8;box-shadow:0 4px 14px #4338ca15}
.trial-notice-days strong{font-size:28px;line-height:1;font-weight:900}.trial-notice-days span{font-size:9px;letter-spacing:1px;font-weight:850;text-transform:uppercase;margin-top:4px}
.trial-notice-summary-copy{min-width:0}.trial-notice-summary-copy strong{display:block;font-size:14px;color:#20294a;margin-bottom:5px}.trial-notice-summary-copy span{display:block;font-size:12px;line-height:1.55;color:#65708b}
.trial-notice-date{display:flex;align-items:center;gap:8px;margin:15px 2px 0;color:#59647c;font-size:12px}.trial-notice-date strong{color:#29334d}
.trial-notice-footnote{margin:14px 2px 18px;font-size:11px;line-height:1.6;color:#7b8498}
.trial-notice-actions{display:flex;gap:10px}.trial-notice-primary,.trial-notice-secondary{min-height:47px;border-radius:13px;padding:12px 16px;font-size:13px;font-weight:850;cursor:pointer}
.trial-notice-primary{flex:1;border:0;color:#fff;background:linear-gradient(110deg,#4f63ef,#8b4de0);box-shadow:0 8px 18px #6653dd35}.trial-notice-secondary{border:1px solid #e0e5ef;background:#fff;color:#4f5b72}
.trial-notice-expired .trial-notice-hero{background:radial-gradient(circle at 80% 0,#ffb5b5 0,transparent 36%),linear-gradient(135deg,#b8324a,#e15a43)}
@keyframes trialOverlayIn{to{opacity:1}}@keyframes trialCardIn{to{transform:translateY(0) scale(1)}}
@media(max-width:480px){.trial-notice-overlay{padding:12px}.trial-notice-card{border-radius:21px}.trial-notice-hero{padding:23px 18px 21px}.trial-notice-body{padding:18px}.trial-notice-summary{padding:12px;gap:11px}.trial-notice-days{flex-basis:60px;width:60px;height:60px}.trial-notice-days strong{font-size:25px}.trial-notice-actions{flex-direction:column}.trial-notice-primary,.trial-notice-secondary{width:100%}}

/* Cuadro con los datos del usuario, abierto al pulsar la foto de perfil. */
.profile-avatar-clickable{cursor:pointer;transition:transform .18s,box-shadow .18s;outline:none}
.profile-avatar-clickable:hover{transform:translateY(-1px);box-shadow:0 0 0 3px #5b7cfa24}
.profile-avatar-clickable:focus-visible{box-shadow:0 0 0 3px #5b7cfa70}
.recharge-result-overlay{position:fixed;inset:0;z-index:1600;display:grid;place-items:center;padding:18px;overflow-y:auto}.recharge-result-overlay[hidden]{display:none!important}.recharge-result-backdrop{position:fixed;inset:0;background:rgba(13,16,42,.72);backdrop-filter:blur(7px)}.recharge-result-dialog{position:relative;z-index:1;width:min(100%,490px);padding:28px;background:#fff;border-radius:24px;box-shadow:0 25px 90px #05071c60;text-align:center}.recharge-result-overlay.is-animated .recharge-result-backdrop{animation:rechargeBackdropIn .28s ease both}.recharge-result-overlay.is-animated .recharge-result-dialog{animation:rechargeDialogIn .48s cubic-bezier(.18,.82,.25,1) both}.recharge-result-overlay.is-animated .recharge-result-icon{animation:rechargeIconPop .55s .12s cubic-bezier(.2,.9,.25,1.4) both}.recharge-result-overlay.is-animated .recharge-result-dialog h2,.recharge-result-overlay.is-animated .recharge-result-intro{animation:rechargeTextIn .38s .18s ease both}.recharge-result-overlay.is-animated .recharge-result-grid>div{animation:rechargeTextIn .4s ease both}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(1){animation-delay:.20s}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(2){animation-delay:.28s}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(3){animation-delay:.36s}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(4){animation-delay:.44s}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(5){animation-delay:.52s}.recharge-result-overlay.is-animated .recharge-result-grid>div:nth-child(6){animation-delay:.60s}.recharge-result-overlay.is-animated .recharge-result-formula{animation:rechargeTextIn .4s .64s ease both}.recharge-result-overlay.is-animated .recharge-result-button{animation:rechargeTextIn .4s .7s ease both}@keyframes rechargeBackdropIn{from{opacity:0}to{opacity:1}}@keyframes rechargeDialogIn{from{opacity:0;transform:translateY(30px) scale(.94)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes rechargeIconPop{0%{opacity:0;transform:scale(.35) rotate(-18deg)}70%{opacity:1;transform:scale(1.13) rotate(5deg)}100%{transform:scale(1) rotate(0)}}@keyframes rechargeTextIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:translateY(0)}}@media(prefers-reduced-motion:reduce){.recharge-result-overlay.is-animated *{animation:none!important}}.recharge-result-icon{width:58px;height:58px;display:grid;place-items:center;margin:0 auto 14px;border-radius:20px;background:#e8f8ef;color:#16834c;font-size:32px;font-weight:900}.recharge-result-dialog h2{margin:0 0 8px;color:#202344;font-size:23px}.recharge-result-intro{margin:0 auto 20px;max-width:360px;color:#777f98;font-size:13px;line-height:1.6}.recharge-result-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;text-align:left}.recharge-result-grid>div{padding:14px;border:1px solid #edf0f8;border-radius:14px;background:#fbfcff;min-width:0}.recharge-result-grid span{display:block;margin-bottom:7px;color:#858ca5;font-size:11px;font-weight:700}.recharge-result-grid strong{display:block;color:#292d50;font-size:17px;overflow-wrap:anywhere}.recharge-result-grid>div:nth-child(2),.recharge-result-grid>div:nth-child(3){background:#f3f0ff;border-color:#e5e0ff}.recharge-result-grid>div:nth-child(2) strong,.recharge-result-grid>div:nth-child(3) strong{color:#5a49c7}.recharge-result-formula{margin:16px 0;color:#858ca5;font-size:11px;line-height:1.5}.recharge-result-button{width:100%;justify-content:center}@media(max-width:480px){.recharge-result-dialog{padding:22px 16px}.recharge-result-grid{grid-template-columns:1fr 1fr}.recharge-result-grid strong{font-size:15px}}
.user-details-overlay{position:fixed;inset:0;z-index:1400;display:grid;place-items:center;padding:20px;overflow-y:auto}
.user-details-overlay[hidden]{display:none!important}
.user-details-backdrop{position:fixed;inset:0;background:rgba(13,16,42,.68);backdrop-filter:blur(7px);animation:detailsFade .18s ease-out}
.user-details-dialog{position:relative;z-index:1;width:min(100%,650px);max-height:calc(100vh - 40px);overflow-y:auto;padding:26px;background:#fff;border:1px solid #ffffff8a;border-radius:24px;box-shadow:0 25px 90px #05071c60;animation:detailsRise .24s cubic-bezier(.2,.75,.2,1)}
.user-details-header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding-bottom:20px;border-bottom:1px solid #eceefa}
.user-details-eyebrow{font-size:9px;font-weight:900;letter-spacing:1.8px;color:#7665e9}
.user-details-header h2{margin:7px 0 0;font-size:23px;color:#202344}
.user-details-close{flex:0 0 40px;width:40px;height:40px;border:1px solid #e7e9f4;border-radius:13px;background:#f8f8fd;color:#4b5070;font-size:26px;line-height:1}
.user-details-close:hover{background:#f0efff;color:#6456d9}
.user-details-identity{display:flex;align-items:center;gap:16px;padding:22px 0}
.user-details-main-photo{width:82px;height:82px;flex:0 0 82px;display:grid;place-items:center;overflow:hidden;border-radius:25px;background:linear-gradient(135deg,#d9e4ff,#eedfff);color:#6557c9;font-size:30px;font-weight:900;box-shadow:0 8px 22px #5b67b51a}
.user-details-main-photo img{width:100%;height:100%;object-fit:cover}
.user-details-name-wrap{min-width:0}
.user-details-name-wrap h3{margin:0;color:#222541;font-size:20px;overflow-wrap:anywhere}
.user-details-name-wrap p{margin:6px 0 9px;color:#8b91aa;font-size:12px;overflow-wrap:anywhere}
.user-details-state{display:inline-flex;align-items:center;border-radius:20px;padding:5px 10px;background:#e8f8ef;color:#137d48;font-size:10px;font-weight:800;text-transform:capitalize}
.user-details-state.inactive{background:#fff0f0;color:#b42342}

.user-profile-tabs{display:flex;gap:8px;margin:18px 0 16px;padding:5px;border:1px solid #e8eaf5;border-radius:14px;background:#f7f8fc}
.user-profile-tab{flex:1;min-width:0;min-height:43px;padding:10px 12px;border:0;border-radius:10px;background:transparent;color:#737b96;font-size:12px;font-weight:800;cursor:pointer;transition:background .18s,color .18s,box-shadow .18s}
.user-profile-tab.active{background:#fff;color:#5748c8;box-shadow:0 2px 9px #252b5018}
.user-profile-tab:focus-visible{outline:2px solid #5b7cfa;outline-offset:2px}
.user-profile-tab-panel[hidden]{display:none!important}
.user-profile-tab-panel{animation:detailsFade .16s ease-out}
@media(max-width:380px){.user-profile-tab{font-size:11px;padding:9px 6px}}
.user-details-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
.user-detail-item{min-width:0;padding:14px 15px;border:1px solid #edf0f8;border-radius:14px;background:#fbfcff}
.user-detail-item>span{display:block;margin-bottom:7px;color:#8b91aa;font-size:10px;font-weight:700}
.user-detail-item>strong{display:block;color:#262a4a;font-size:12px;line-height:1.55;overflow-wrap:anywhere;word-break:break-word}
.user-detail-item>label{display:block;margin:0 0 7px;color:#8b91aa;font-size:10px;font-weight:700}
.user-detail-item>input,.user-detail-item>select{display:block;width:100%;min-width:0;box-sizing:border-box;border:1px solid #dfe4f3;border-radius:10px;background:#fff;color:#262a4a;padding:11px 10px;font:500 12px inherit;outline:none}
.user-detail-item>input:focus,.user-detail-item>select:focus{border-color:#5b7cfa;box-shadow:0 0 0 3px #5b7cfa20}
.user-profile-save-item{display:flex;flex-direction:column;align-items:flex-start;justify-content:center;gap:8px}
.user-profile-save-item button{width:100%;min-height:42px}
#profileDataFeedback{font-size:11px;line-height:1.45;color:#777e99}
#profileDataFeedback.error{color:#b42342}
#profileDataFeedback.success{color:#137d48}
.account-dates-item{background:linear-gradient(135deg,#f8f9ff,#f5f1ff);border-color:#e4e5fb}.account-dates-item>strong{color:#5147b8}
.user-status-editor{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.user-status-editor select{min-width:0;flex:1;border:1px solid #dfe4f3;border-radius:10px;background:#fff;color:#262a4a;padding:10px 9px;font-size:12px;font-weight:700;outline:none}
.user-status-editor select:focus{border-color:#5b7cfa;box-shadow:0 0 0 3px #5b7cfa20}
.user-status-save{padding:10px 12px!important;font-size:11px!important;white-space:nowrap}
.user-status-save:disabled{opacity:.55;cursor:not-allowed}
.user-status-feedback{display:block;margin-top:8px;color:#777e99;font-size:10px;line-height:1.5}
.user-status-feedback.error{color:#b42342}
.user-status-feedback.success{color:#137d48}
.user-detail-photo-item #detailsPhotoValue{display:flex;align-items:center;gap:10px;color:#777e99;font-size:11px}
.user-detail-photo-item img{width:48px;height:48px;border:1px solid #e4e7f3;border-radius:12px;object-fit:cover;background:#f0f2fa}
.user-details-footer{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-top:20px;padding-top:18px;border-top:1px solid #eceefa}
.user-details-footer>span{color:#8b91aa;font-size:10px;line-height:1.6}
.user-details-footer .btn{flex-shrink:0;padding:12px 18px}
.user-details-footer-actions{display:flex;align-items:center;gap:9px;flex-wrap:wrap}
.btn-delete-outline{background:#fff;color:#b42342;border:1px solid #f0c5ce;box-shadow:none}
.btn-delete-outline:hover{background:#fff0f2;filter:none}
.delete-account-panel{margin-top:18px;padding:18px;border:1px solid #f0c5ce;background:linear-gradient(135deg,#fff7f8,#fff);border-radius:16px;display:grid;grid-template-columns:34px minmax(0,1fr);gap:12px}
.delete-account-panel[hidden]{display:none!important}
.delete-account-warning-icon{width:32px;height:32px;display:grid;place-items:center;border-radius:11px;background:#ffe5e9;color:#b42342;font-weight:900;font-size:17px}
.delete-account-copy h3{margin:2px 0 6px;color:#9f1d37;font-size:14px}
.delete-account-copy p{margin:0;color:#7c5360;font-size:11px;line-height:1.7}
.delete-account-form,.delete-account-error,.delete-account-actions{grid-column:1/-1}
.delete-account-form label{display:block;margin-bottom:8px;color:#4d3140;font-size:11px;font-weight:800}
.delete-account-form input{width:100%;border:1px solid #e7cbd0;border-radius:11px;padding:12px 13px;background:#fff;color:#24243e;outline:none}
.delete-account-form input:focus{border-color:#d34d68;box-shadow:0 0 0 3px #d34d681b}
.delete-account-error{padding:10px 12px;border-radius:10px;background:#fff0f2;color:#b42342;font-size:11px;line-height:1.5}
.delete-account-error[hidden]{display:none!important}
.delete-account-actions{display:flex;justify-content:flex-end;gap:9px;flex-wrap:wrap}
.btn-delete-cancel{background:#f2f3f9;color:#4e5470;box-shadow:none}
.btn-delete-confirm{background:linear-gradient(110deg,#c93251,#a71737);box-shadow:0 7px 18px #c9325126}
.btn-delete-confirm:hover{filter:brightness(1.04)}
.auth-error.success{background:#eaf8ef;color:#17683a}
@keyframes detailsFade{from{opacity:0}to{opacity:1}}
@keyframes detailsRise{from{opacity:0;transform:translateY(10px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
body.user-details-open{overflow:hidden}
@media(max-width:520px){.user-details-overlay{padding:10px}.user-details-dialog{padding:19px;border-radius:20px;max-height:calc(100vh - 20px)}.user-details-header h2{font-size:20px}.user-details-grid{grid-template-columns:1fr}.user-details-identity{gap:13px}.user-details-main-photo{width:68px;height:68px;flex-basis:68px;border-radius:21px}.user-details-name-wrap h3{font-size:17px}.user-details-footer{align-items:flex-start;flex-direction:column}.user-details-footer-actions{width:100%;flex-direction:column;align-items:stretch}.user-details-footer .btn{width:100%}.delete-account-actions{flex-direction:column-reverse}.delete-account-actions .btn{width:100%}}
@media(prefers-reduced-motion:reduce){.user-details-backdrop,.user-details-dialog{animation:none!important}}


/* Ficha emergente del transportista: adaptable a móvil y escritorio */
.transporter-dialog-backdrop{position:fixed;inset:0;z-index:10050;background:rgba(12,20,38,.72);display:none;align-items:center;justify-content:center;padding:12px;box-sizing:border-box;backdrop-filter:blur(4px);overscroll-behavior:contain}
.transporter-dialog-backdrop.open{display:flex}
.transporter-dialog{box-sizing:border-box;width:min(100%,440px);max-height:calc(100dvh - 24px);overflow-x:hidden;overflow-y:auto;background:#fff;border-radius:20px;box-shadow:0 24px 80px #0004;position:relative;color:#1f2937;animation:transporterDialogIn .18s ease-out;overscroll-behavior:contain;-webkit-overflow-scrolling:touch;isolation:isolate}
@keyframes transporterDialogIn{from{opacity:0;transform:translateY(8px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
.transporter-dialog-close{position:absolute;right:10px;top:10px;z-index:20;width:40px;height:40px;border:0;border-radius:50%;background:#ffffffed;color:#1f2937;font-size:26px;line-height:1;cursor:pointer;box-shadow:0 2px 8px #0002;display:flex;align-items:center;justify-content:center;touch-action:manipulation}
.transporter-dialog-hero{height:clamp(160px,38vw,220px);position:relative;background:#e8edf4;border-radius:20px 20px 0 0;overflow:visible;}
.transporter-dialog-vehicle[hidden],.transporter-dialog-vehicle-fallback[hidden]{display:none!important}
.transporter-dialog-vehicle{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;object-position:center;border-radius:20px 20px 0 0;display:block}
.transporter-dialog-vehicle-fallback{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:18px;box-sizing:border-box;text-align:center;font-size:13px;font-weight:700;color:#536174;background:linear-gradient(135deg,#e8edf4,#dbe4ef);border-radius:20px 20px 0 0}
.transporter-dialog-driver{position:absolute;left:16px;right:auto;bottom:-34px;z-index:3;width:76px;height:76px;box-sizing:border-box;border:4px solid #fff;border-radius:50%;overflow:hidden;background:#eef2ff;box-shadow:0 4px 14px #0002;display:flex;align-items:center;justify-content:center;font-size:28px;color:#5364c8}
.transporter-dialog-driver img{width:100%;height:100%;object-fit:cover;display:block}
.transporter-dialog-content{box-sizing:border-box;padding:43px 20px 18px;min-width:0}
.transporter-dialog-name{font-size:clamp(19px,5vw,22px);line-height:1.25;font-weight:850;letter-spacing:-.4px;margin:0 0 5px;color:#172033;overflow-wrap:anywhere}
.transporter-dialog-subtitle{font-size:12px;color:#138a68;font-weight:750;margin:0 0 14px;display:flex;align-items:center;gap:6px}
.transporter-dialog-subtitle:before{content:"";width:7px;height:7px;flex:0 0 7px;border-radius:50%;background:#19b47a;display:inline-block}
.transporter-dialog-section-title{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:#7b8497;font-weight:850;margin:14px 0 8px}
.transporter-dialog-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
.transporter-dialog-field{box-sizing:border-box;min-width:0;border:1px solid #e8ebf2;background:#f8faff;border-radius:12px;padding:10px}
.transporter-dialog-field.wide{grid-column:1/-1}
.transporter-dialog-field-label{font-size:11px;color:#778198;font-weight:750;margin-bottom:5px;overflow-wrap:anywhere;line-height:1.35}
.transporter-dialog-field-value{font-size:13px;color:#202a40;font-weight:800;overflow-wrap:anywhere;word-break:break-word;line-height:1.4}
.transporter-dialog-footer{display:flex;gap:10px;margin-top:16px}
.transporter-dialog-request{box-sizing:border-box;display:flex;align-items:center;justify-content:center;text-align:center;flex:1 1 100%;width:100%;min-width:0;min-height:48px;padding:12px 14px;border-radius:12px;background:#2563eb;color:#fff;font-weight:850;text-decoration:none;font-size:14px;box-shadow:0 5px 14px #2563eb33;overflow-wrap:anywhere;touch-action:manipulation}
.transporter-dialog-request.disabled{background:#e9edf3;color:#8a93a5;pointer-events:none;box-shadow:none}
@media(max-width:520px){.transporter-dialog-backdrop{padding:8px;padding-top:max(8px,env(safe-area-inset-top));padding-bottom:max(8px,env(safe-area-inset-bottom));align-items:center}.transporter-dialog{width:100%;max-width:420px;max-height:calc(100dvh - 16px);border-radius:18px}.transporter-dialog-hero{height:clamp(145px,27vh,190px);border-radius:18px 18px 0 0}.transporter-dialog-vehicle,.transporter-dialog-vehicle-fallback{border-radius:18px 18px 0 0}.transporter-dialog-driver{left:12px;bottom:-29px;width:64px;height:64px}.transporter-dialog-content{padding:37px 13px 14px}.transporter-dialog-name{font-size:19px}.transporter-dialog-subtitle{margin-bottom:11px}.transporter-dialog-section-title{margin:12px 0 7px}.transporter-dialog-grid{gap:7px}.transporter-dialog-field{padding:9px}.transporter-dialog-field-label{font-size:10px}.transporter-dialog-field-value{font-size:12px}.transporter-dialog-footer{margin-top:12px}.transporter-dialog-request{min-height:46px;padding:11px;font-size:13px}}
@media(max-width:350px){.transporter-dialog-grid{grid-template-columns:minmax(0,1fr)}.transporter-dialog-field.wide{grid-column:auto}.transporter-dialog-content{padding-left:11px;padding-right:11px}}
@media(max-height:620px) and (max-width:520px){.transporter-dialog-hero{height:115px}.transporter-dialog-driver{width:56px;height:56px;bottom:-24px}.transporter-dialog-content{padding-top:31px}.transporter-dialog-section-title{margin-top:9px}.transporter-dialog-field{padding:7px}}



/* Instalación PWA: la autenticación siempre permanece visible. */
#radarInstallScreen{display:none !important}
body.radar-permission-pending > :not(#radarPermissionGate):not(script){display:none !important}
#radarPermissionGate{display:none;position:fixed;inset:0;z-index:5000;background:linear-gradient(135deg,#101a38,#263b70);align-items:center;justify-content:center;padding:22px;color:#fff}
body.radar-permission-pending #radarPermissionGate{display:flex !important}
#radarPermissionGate .radar-permission-card{width:min(100%,430px);background:#fff;color:#202a40;border-radius:22px;padding:28px;text-align:center;box-shadow:0 24px 70px #0005}
#radarPermissionGate h2{margin:0 0 10px;font-size:24px}
#radarPermissionGate p{line-height:1.55;color:#5b6578}
#radarPermissionGate button{border:0;border-radius:12px;background:#315eea;color:#fff;padding:15px 20px;font-weight:800;font-size:15px;width:100%;cursor:pointer}
#radarPermissionStatus{font-size:13px;min-height:20px;margin-top:12px}
#radarInstallButton{position:fixed;right:16px;bottom:18px;z-index:1200;border:0;border-radius:999px;padding:13px 18px;background:linear-gradient(135deg,#5b7cfa,#8b5cf6);color:#fff;font-weight:800;box-shadow:0 10px 28px #17194340;cursor:pointer;display:none}
#radarPushButton{position:fixed;right:16px;bottom:76px;z-index:1200;border:1px solid #dfe3ff;border-radius:999px;padding:11px 15px;background:#fff;color:#252056;font-weight:700;box-shadow:0 8px 24px #17194320;cursor:pointer;display:none}
@media(max-width:700px){#radarInstallButton{right:12px;bottom:12px}#radarPushButton{right:12px;bottom:70px}}

.trip-request-backdrop{position:fixed;inset:0;z-index:1300;background:rgba(10,15,35,.68);display:none;align-items:center;justify-content:center;padding:18px;overflow-y:auto}
.trip-request-backdrop.open{display:flex}
.trip-request-dialog{width:min(100%,520px);max-height:calc(100vh - 36px);overflow-y:auto;background:#fff;border-radius:20px;padding:24px;box-shadow:0 24px 80px #0005;color:#20243a;position:relative}
.trip-request-dialog h2{margin:0 38px 8px 0;font-size:22px}
.trip-request-dialog p{margin:0 0 18px;color:#667085;font-size:14px}
.trip-request-close{position:absolute;right:15px;top:12px;border:0;background:#f1f3f9;border-radius:50%;width:36px;height:36px;font-size:24px;cursor:pointer}
.trip-request-form{display:grid;gap:13px}
.trip-request-form label{display:grid;gap:6px;font-size:13px;font-weight:700;color:#3b4055}
.trip-request-form input,.trip-request-form textarea{width:100%;box-sizing:border-box;border:1px solid #d9deea;border-radius:10px;padding:12px;font:inherit;color:#20243a;background:#fff}
.trip-request-form textarea{min-height:82px;resize:vertical}
.trip-request-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:4px}
.trip-request-actions button{border:0;border-radius:10px;padding:12px 17px;font-weight:700;cursor:pointer}
.trip-request-cancel{background:#eef0f6;color:#33394d}
.trip-request-submit{background:#3157d5;color:#fff}
.trip-request-submit:disabled{opacity:.6;cursor:wait}


.trip-notification-actions{grid-column:2;display:flex;gap:8px;margin-top:9px}
.trip-notification-actions button{border:0;border-radius:9px;padding:8px 13px;font-weight:800;font-size:11px;cursor:pointer}
.trip-notification-respond{background:#176b45;color:#fff}.trip-notification-reject{background:#fff0f0;color:#b42332}
.trip-arrival-alert-overlay{position:fixed;inset:0;z-index:13000;background:rgba(5,12,25,.82);display:none;align-items:center;justify-content:center;padding:18px;backdrop-filter:blur(7px)}
.trip-arrival-alert-overlay.show{display:flex}
.trip-arrival-alert-dialog{position:relative;isolation:isolate;overflow:hidden;width:min(100%,430px);min-height:510px;padding:0 24px 25px;border-radius:28px;background:linear-gradient(155deg,#163b59,#071629);text-align:center;box-shadow:0 28px 90px #0008;animation:tripArrivalPop .25s ease-out;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:flex-end}
.trip-arrival-vehicle-background{position:absolute;inset:0 0 105px;z-index:-2;background:linear-gradient(135deg,#294e6d,#101e31);overflow:hidden}
.trip-arrival-vehicle-background img{width:100%;height:100%;object-fit:cover;object-position:center;display:block;opacity:.88}
.trip-arrival-vehicle-background img[hidden]{display:none}
.trip-arrival-vehicle-background:after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,rgba(4,13,25,.08) 15%,rgba(4,13,25,.35) 55%,#071629 100%)}
.trip-arrival-alert-icon{position:relative;z-index:2;margin:0 0 auto;padding-top:25px;font-size:36px;filter:drop-shadow(0 3px 8px #0008)}
.trip-arrival-photos{position:relative;z-index:2;width:100%;display:flex;justify-content:center;align-items:flex-end;margin:205px 0 18px;min-height:116px}
.trip-arrival-photo-card{min-width:0;display:flex;flex-direction:column;align-items:center;gap:8px;color:#fff;font-size:13px;font-weight:850;text-shadow:0 2px 7px #000b}
.trip-arrival-photo-card img{width:116px;height:116px;max-width:100%;object-fit:cover;border-radius:50%;border:4px solid #fff;box-shadow:0 8px 28px #0008;background:#e2e8f0}
.trip-arrival-photo-card img[hidden]{display:none}
.trip-arrival-alert-dialog h2{position:relative;z-index:2;margin:0 0 10px;color:#fff;font-size:25px;text-shadow:0 2px 10px #0008}
.trip-arrival-alert-dialog p{position:relative;z-index:2;margin:0 0 22px;color:#e5edf7;font-size:15px;line-height:1.5;max-width:330px}
.trip-arrival-alert-dialog button:not(.trip-arrival-alert-x){position:relative;z-index:2;border:0;border-radius:13px;background:#22a06b;color:#fff;padding:13px 30px;font-weight:850;font-size:14px;cursor:pointer;box-shadow:0 7px 20px #0004}
.trip-arrival-alert-x{position:absolute;z-index:4;right:13px;top:12px;border:1px solid #ffffff55;background:#07162966;color:#fff;border-radius:50%;width:38px;height:38px;font-size:25px;cursor:pointer}
@media(max-width:420px){.trip-arrival-alert-dialog{min-height:470px;padding-left:18px;padding-right:18px}.trip-arrival-photos{margin-top:175px}.trip-arrival-photo-card img{width:102px;height:102px}}
@keyframes tripArrivalPop{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}
.trip-response-overlay{position:fixed;inset:0;z-index:10050;background:rgba(12,18,38,.62);display:none;align-items:center;justify-content:center;padding:18px}
.trip-response-overlay.show{display:flex}.trip-response-dialog{width:min(100%,480px);max-height:90vh;overflow:auto;background:#fff;border-radius:18px;padding:22px;position:relative;box-shadow:0 20px 60px rgba(0,0,0,.25)}
.trip-response-dialog h2{margin:0 35px 14px 0;font-size:20px}.trip-response-close{position:absolute;right:14px;top:10px;border:0;background:transparent;font-size:28px;cursor:pointer}
.trip-response-details{padding:12px;background:#f5f7fb;border-radius:12px;margin-bottom:14px;font-size:13px;line-height:1.55}.trip-response-details p{margin:5px 0}.trip-response-form label{display:block;font-weight:800;font-size:13px}.trip-response-form input{width:100%;margin-top:7px;padding:12px;border:1px solid #ccd2df;border-radius:10px;font:inherit}
.trip-response-actions{display:flex;gap:9px;justify-content:flex-end;margin-top:17px}.trip-response-actions button{border:0;border-radius:10px;padding:11px 14px;font-weight:800;cursor:pointer}.trip-response-cancel{background:#edf0f5;color:#343b4b}.trip-response-submit{background:#176b45;color:#fff}

.trip-navigation-overlay{position:fixed;inset:0;z-index:12000;background:rgba(12,20,38,.76);display:none;align-items:center;justify-content:center;padding:12px;box-sizing:border-box;backdrop-filter:blur(4px)}
.trip-navigation-overlay.show{display:flex}
.trip-navigation-dialog{position:relative;box-sizing:border-box;width:min(100%,850px);max-height:calc(100dvh - 24px);overflow:auto;background:#fff;border-radius:18px;padding:20px;box-shadow:0 24px 80px #0005}
.trip-navigation-close{position:absolute;right:12px;top:10px;border:0;background:#f0f2f7;border-radius:50%;width:36px;height:36px;font-size:24px;cursor:pointer;color:#25304b}
.trip-navigation-dialog h2{margin:0 45px 10px 0;font-size:20px;color:#172033}
.trip-navigation-info{font-size:12px;line-height:1.6;color:#536078;margin:0 0 12px;overflow-wrap:anywhere}
.trip-navigation-map{height:min(58vh,520px);min-height:300px;width:100%;border-radius:13px;border:1px solid #dfe5ef;overflow:hidden;background:#e8edf4;z-index:0}
.trip-nav-div-icon{background:transparent!important;border:0!important}
.trip-nav-photo-marker{width:52px;height:52px;border:3px solid #fff;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#eef2f8;box-shadow:0 3px 12px #17243d66;color:#27334d;font-size:23px}
.trip-nav-photo-marker.vehicle{border-radius:14px;background:#e9f1ff}
.trip-nav-photo-marker.client{border-radius:50%;background:#f1eaff}
.trip-nav-photo-marker img{width:100%;height:100%;display:block;object-fit:cover}
.trip-navigation-status{margin:12px 0 0;padding:11px 13px;border-radius:11px;background:#eef5ff;color:#244b7d;font-size:12px;line-height:1.6}
.trip-navigation-actions{display:flex;justify-content:flex-end;margin-top:12px}.trip-navigation-finish{border:0;border-radius:11px;padding:12px 16px;background:#16834b;color:#fff;font-weight:850;cursor:pointer}.trip-navigation-finish:disabled{opacity:.65;cursor:wait}
.trip-navigation-done{border:0;border-radius:10px;padding:11px 18px;background:#1769e0;color:#fff;font-weight:800;cursor:pointer}
@media(max-width:520px){.trip-navigation-dialog{padding:16px;border-radius:15px}.trip-navigation-map{height:55vh;min-height:280px}.trip-navigation-dialog h2{font-size:18px}}


/* Pestañas de Recargar cuenta */
.recharge-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0 20px;padding:6px;background:#eef0fa;border:1px solid var(--border);border-radius:14px}
.recharge-tab{flex:1 1 190px;border:0;border-radius:10px;padding:13px 16px;background:transparent;color:var(--text);font:inherit;font-weight:700;cursor:pointer;transition:background .2s,color .2s,box-shadow .2s}
.recharge-tab:hover{background:rgba(255,255,255,.75)}
.recharge-tab.active{background:#fff;color:var(--blue);box-shadow:0 3px 12px rgba(35,39,85,.10)}
.recharge-tab-pane[hidden]{display:none!important}
.recharge-tab-pane{animation:rechargeTabIn .18s ease}
@keyframes rechargeTabIn{from{opacity:.65;transform:translateY(3px)}to{opacity:1;transform:translateY(0)}}
@media(max-width:640px){.recharge-tabs{display:grid;grid-template-columns:1fr}.recharge-tab{width:100%;text-align:left}}


/* Diálogo animado de difusión de novedades */
.news-broadcast-overlay{position:fixed;inset:0;z-index:14010;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(8,15,32,.78);backdrop-filter:blur(8px)}
.news-broadcast-overlay.show{display:flex;animation:bonusFadeIn .24s ease both}
.news-broadcast-dialog{position:relative;width:min(100%,480px);max-height:min(90dvh,760px);overflow:auto;padding:27px 22px 21px;border-radius:25px;background:linear-gradient(160deg,#fff 0%,#f5f8ff 100%);box-shadow:0 28px 90px #0007;animation:bonusDialogIn .5s cubic-bezier(.2,.85,.25,1.15) both}
.news-broadcast-icon{display:grid;place-items:center;width:72px;height:72px;margin:0 auto 12px;border-radius:23px;background:linear-gradient(135deg,#dbeafe,#ede9fe);font-size:37px;box-shadow:0 9px 24px #647be833;animation:bonusGiftPulse 1.8s ease-in-out infinite}
.news-broadcast-dialog h2{margin:8px 35px 6px;text-align:center;color:#172554;font-size:23px;line-height:1.25}
.news-broadcast-kicker{text-align:center;color:#667085;font-size:11px;font-weight:900;letter-spacing:1.4px;text-transform:uppercase}
.news-broadcast-category{text-align:center;color:#5365bd;font-size:12px;font-weight:800;margin:8px 0 15px}
.news-broadcast-image{display:block;width:100%;max-height:260px;object-fit:contain;border-radius:15px;margin:0 auto 15px;background:#eef2f8}
.news-broadcast-content{padding:14px 15px;border:1px solid #e4e9f3;border-radius:15px;background:#fff;color:#344054;font-size:14px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}
.news-broadcast-date{text-align:center;color:#98a2b3;font-size:11px;margin:12px 0 0}
.news-broadcast-close{position:absolute;right:12px;top:10px;width:36px;height:36px;border:0;border-radius:50%;background:#eef1f7;color:#475467;font-size:23px;cursor:pointer}
.news-broadcast-ok{display:block;width:100%;margin-top:17px;border:0;border-radius:13px;padding:14px 18px;background:#172554;color:#fff;font-weight:900;font-size:14px;cursor:pointer;box-shadow:0 8px 18px #17255430}
@media(prefers-reduced-motion:reduce){.news-broadcast-overlay,.news-broadcast-dialog,.news-broadcast-icon{animation:none;opacity:1;transform:none}}
/* Diálogo animado de difusión de bonos */
.bonus-broadcast-overlay{position:fixed;inset:0;z-index:14000;display:none;align-items:center;justify-content:center;padding:16px;background:rgba(8,15,32,.76);backdrop-filter:blur(7px);}
.bonus-broadcast-overlay.show{display:flex;animation:bonusFadeIn .24s ease both}
.bonus-broadcast-dialog{position:relative;width:min(100%,440px);max-height:min(88dvh,720px);overflow:auto;box-sizing:border-box;padding:27px 22px 21px;border-radius:24px;background:linear-gradient(160deg,#fff 0%,#f7f9ff 100%);box-shadow:0 28px 90px #0006;animation:bonusDialogIn .48s cubic-bezier(.2,.85,.25,1.15) both}
.bonus-broadcast-top{text-align:center;margin-bottom:17px}
.bonus-broadcast-gift{display:inline-grid;place-items:center;width:72px;height:72px;border-radius:24px;background:linear-gradient(135deg,#fff1c2,#ffe0a3);font-size:39px;box-shadow:0 9px 24px #f2b84c40;animation:bonusGiftPulse 1.8s ease-in-out infinite}
.bonus-broadcast-dialog h2{margin:13px 0 6px;text-align:center;color:#172554;font-size:23px;letter-spacing:-.4px}
.bonus-broadcast-subtitle{text-align:center;color:#667085;font-size:13px;line-height:1.5;margin:0}
.bonus-broadcast-amount{margin:17px 0;padding:15px;border-radius:17px;text-align:center;background:linear-gradient(120deg,#155e45,#16845a);color:#fff;box-shadow:0 9px 22px #176b4530}
.bonus-broadcast-amount small{display:block;font-size:11px;letter-spacing:1.1px;text-transform:uppercase;opacity:.85;font-weight:800}
.bonus-broadcast-amount strong{display:block;margin-top:3px;font-size:30px;letter-spacing:-.7px}
.bonus-broadcast-minimum{margin:12px 0;padding:13px 15px;border:1px solid #f0c36d;border-radius:15px;text-align:center;background:linear-gradient(120deg,#fff8e7,#fff1cc);color:#7a4b08;box-shadow:0 7px 18px #d99a2420;animation:bonusDetailIn .42s ease both}
.bonus-broadcast-minimum small{display:block;font-size:10px;letter-spacing:.8px;text-transform:uppercase;font-weight:900;line-height:1.4}
.bonus-broadcast-minimum strong{display:block;margin-top:5px;font-size:23px;font-weight:900}
.bonus-broadcast-details{display:grid;gap:9px;margin:15px 0}
.bonus-broadcast-detail{display:flex;gap:10px;align-items:flex-start;padding:11px 12px;border:1px solid #e7ebf3;border-radius:12px;background:#fff;color:#344054;font-size:13px;line-height:1.45;opacity:0;transform:translateY(10px);animation:bonusDetailIn .38s ease forwards}
.bonus-broadcast-detail b{color:#172554;min-width:112px}
.bonus-broadcast-detail span{overflow-wrap:anywhere;white-space:pre-wrap}
.bonus-broadcast-actions{display:flex;justify-content:center;margin-top:17px}
.bonus-broadcast-ok{width:100%;border:0;border-radius:13px;padding:14px 18px;background:#172554;color:#fff;font-weight:900;font-size:14px;letter-spacing:.3px;cursor:pointer;box-shadow:0 8px 18px #17255430;transition:transform .18s,filter .18s}
.bonus-broadcast-ok:active{transform:scale(.98);filter:brightness(1.12)}
.bonus-broadcast-x{position:absolute;right:12px;top:10px;width:35px;height:35px;border:0;border-radius:50%;background:#eef1f7;color:#475467;font-size:23px;cursor:pointer}
@keyframes bonusFadeIn{from{opacity:0}to{opacity:1}}@keyframes bonusDialogIn{from{opacity:0;transform:translateY(28px) scale(.94)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes bonusGiftPulse{0%,100%{transform:rotate(-4deg) scale(1)}50%{transform:rotate(4deg) scale(1.07)}}@keyframes bonusDetailIn{to{opacity:1;transform:translateY(0)}}
@media(prefers-reduced-motion:reduce){.bonus-broadcast-overlay,.bonus-broadcast-dialog,.bonus-broadcast-gift,.bonus-broadcast-detail{animation:none;opacity:1;transform:none}}
</style>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />

<style id="radar-no-copy-style">
/* Evita seleccionar texto de la interfaz y copiarlo desde el navegador. */
html, body, body * { -webkit-user-select:none !important; user-select:none !important; -webkit-touch-callout:none !important; }
input, textarea, [contenteditable="true"] { -webkit-user-select:text !important; user-select:text !important; }
</style>
<style id="admin-settings-submenu-only">
.app-settings-tabs[hidden],
.app-settings-tabs { display:none !important; }
</style>
</head>

<body class="auth-locked radar-offline-lock">
<div id="radarConfirmOverlay" class="radar-confirm-overlay" role="alertdialog" aria-modal="true" aria-labelledby="radarConfirmTitle" aria-describedby="radarConfirmMessage" hidden>
  <section class="radar-confirm-card">
    <div class="radar-confirm-brand"><span aria-hidden="true">🚕</span> El Radar</div>
    <h2 id="radarConfirmTitle">Confirmar acción</h2>
    <p id="radarConfirmMessage"></p>
    <div class="radar-confirm-actions"><button type="button" class="radar-confirm-cancel" id="radarConfirmCancel">Cancelar</button><button type="button" class="radar-confirm-accept" id="radarConfirmAccept">Confirmar</button></div>
  </section>
</div>
<div id="serverOfflineOverlay" class="show" role="alertdialog" aria-modal="true" aria-labelledby="serverOfflineTitle" aria-describedby="serverOfflineMessage">
  <section class="server-offline-card">
    <div class="server-offline-icon" aria-hidden="true">⌁</div>
    <h2 id="serverOfflineTitle">No hay conexión con el servidor</h2>
    <p id="serverOfflineMessage"><span class="server-offline-pulse" aria-hidden="true"></span>Estamos intentando restablecer la conexión. La aplicación volverá a mostrarse automáticamente cuando el servidor esté disponible.</p>
  </section>
</div>
<div id="offlineCacheBanner" role="status" aria-live="polite">SIN CONEXIÓN · Mostrando la última versión guardada en este dispositivo. Las operaciones se sincronizarán al recuperar Internet.</div>
<script>
(function(){
  const installed = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  if (installed()) document.body.classList.add('radar-installed');
})();
</script>
<section class="auth-screen" id="authScreen" aria-label="Acceso a El Radar">
  <div class="auth-card">
    <div class="auth-brand"><img class="brand-logo" src="./icon-512.png" alt="El Radar" width="512" height="512"><div><h2>El Radar</h2><p>MOVILIDAD INTELIGENTE</p></div></div>
    <div class="server-status checking" id="serverStatus" role="status" aria-live="polite">
      <span class="status-dot" aria-hidden="true"></span>
      <div><strong id="serverStatusTitle">Comprobando conexión…</strong><div id="serverStatusMessage">Estamos verificando el servidor y la base de datos antes de mostrar el acceso.</div></div>
    </div>
    <div id="authControls" hidden>
    <div class="auth-tabs"><button type="button" class="active" id="loginTab">Iniciar sesión</button><button type="button" id="registerTab">Crear cuenta</button></div>
    <div id="loginPanel">
      <h3>¡Qué bueno verte!</h3><p class="auth-intro">Inicia sesión para acceder a tu espacio de movilidad.</p>
      <div class="auth-error" id="loginError" role="alert"></div>
      <form class="auth-form" id="loginForm" autocomplete="on">
        <div class="form-group"><label for="loginUser">Usuario</label><input id="loginUser" name="usuario" type="text" autocomplete="username" maxlength="50" placeholder="Tu nombre de usuario" required></div>
        <div class="form-group"><label for="loginPass">Contraseña</label><input id="loginPass" name="contrasena" type="password" autocomplete="current-password" placeholder="Tu contraseña" required></div>
        <button class="btn" type="submit" id="loginSubmit">Entrar a El Radar →</button>
      </form>
      <button type="button" class="auth-link" id="forgotPasswordLink">¿Olvidaste tu contraseña?</button>
    </div>
    <div id="forgotPasswordPanel" hidden>
      <h3>Recuperar contraseña</h3><p class="auth-intro">Escribe el correo electrónico asociado a tu cuenta. Te enviaremos un enlace para crear una contraseña nueva.</p>
      <div class="auth-error" id="forgotPasswordError" role="alert"></div>
      <form class="auth-form" id="forgotPasswordForm"><div class="form-group"><label for="forgotPasswordEmail">Correo electrónico</label><input id="forgotPasswordEmail" type="email" maxlength="190" autocomplete="email" placeholder="tunombre@correo.com" required></div><button class="btn" type="submit" id="forgotPasswordSubmit">Enviar enlace de recuperación</button></form>
      <button type="button" class="auth-link" id="backToLoginFromForgot">Volver a iniciar sesión</button>
    </div>
    <div id="resetPasswordPanel" hidden>
      <h3>Nueva contraseña</h3><p class="auth-intro">Elige una contraseña nueva para tu cuenta.</p>
      <div class="auth-error" id="resetPasswordError" role="alert"></div>
      <form class="auth-form" id="resetPasswordForm"><div class="form-group"><label for="resetPasswordNew">Nueva contraseña</label><input id="resetPasswordNew" type="password" minlength="8" maxlength="200" autocomplete="new-password" required placeholder="Mínimo 8 caracteres"></div><div class="form-group"><label for="resetPasswordConfirm">Confirmar contraseña</label><input id="resetPasswordConfirm" type="password" minlength="8" maxlength="200" autocomplete="new-password" required placeholder="Repite la contraseña"></div><button class="btn" type="submit" id="resetPasswordSubmit">Guardar nueva contraseña</button></form>
    </div>
    <div id="registerPanel" hidden>
      <h3>Crea tu cuenta</h3><p class="auth-intro">Regístrate para empezar a utilizar El Radar.</p>
      <div class="auth-error" id="registerError" role="alert"></div>
      <form class="auth-form" id="registerForm" autocomplete="on">
        <div class="form-group"><label for="registerName">Nombre</label><input id="registerName" name="nombre" type="text" autocomplete="given-name" maxlength="100" placeholder="Tu nombre" required></div>
        <div class="form-group"><label for="registerSurnames">Apellidos</label><input id="registerSurnames" name="apellidos" type="text" autocomplete="family-name" maxlength="150" placeholder="Tus apellidos" required></div>
        <div class="form-group"><label for="registerIdentityCard">Carné de identidad</label><input id="registerIdentityCard" name="carnet_identidad" type="text" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" autocomplete="off" placeholder="11 dígitos, sin espacios ni guiones" required><span class="help-text">Introduce los 11 dígitos del carné de identidad.</span></div>
        <div class="form-group"><label for="registerPhone">Número de teléfono</label><input id="registerPhone" name="telefono" type="tel" inputmode="tel" autocomplete="tel" maxlength="25" placeholder="Ej.: +53 5XXXXXXX" required><span class="help-text">Incluye el código de país +53 si corresponde.</span></div>
        <div class="form-group"><label for="registerEmail">Correo electrónico</label><input id="registerEmail" name="correo" type="email" autocomplete="email" maxlength="190" placeholder="tunombre@correo.com" required></div>
        <div class="form-group"><label for="registerProvince">Provincia</label><select id="registerProvince" name="provincia" required><option value="">Selecciona tu provincia</option></select></div>
        <div class="form-group"><label for="registerMunicipality">Municipio</label><select id="registerMunicipality" name="municipio" required disabled><option value="">Primero selecciona una provincia</option></select></div>
        <div class="form-group"><label for="registerUser">Nombre de usuario</label><input id="registerUser" name="usuario" type="text" autocomplete="username" minlength="3" maxlength="50" pattern="[A-Za-z0-9_.-]{3,50}" placeholder="Ej.: usuario123" required><span class="help-text">Usa entre 3 y 50 caracteres: letras, números, punto, guion o guion bajo.</span></div>
        <div class="form-group"><label for="registerPhoto">Foto de perfil <span aria-hidden="true">*</span></label><input id="registerPhoto" name="foto" type="file" accept="image/*" required><span class="help-text">Obligatoria. Puedes elegir una foto de la galería del teléfono. La imagen se ajustará automáticamente para poder subirla.</span><div id="registerPhotoPreview" class="photo-preview" hidden><img id="registerPhotoPreviewImage" alt="Vista previa de la foto seleccionada"></div></div>
        <div class="form-group"><label for="registerPass">Contraseña</label><input id="registerPass" name="contrasena" type="password" autocomplete="new-password" minlength="8" placeholder="Mínimo 8 caracteres" required></div>
        <div class="form-group"><label for="registerConfirm">Confirmar contraseña</label><input id="registerConfirm" name="confirmar" type="password" autocomplete="new-password" minlength="8" placeholder="Repite la contraseña" required></div>
        <button class="btn" type="submit" id="registerSubmit">Crear cuenta →</button>
      </form>
    </div>
    </div>
    <p class="auth-note">Tu sesión se valida con el servidor. Las contraseñas se guardan protegidas, no como texto visible.</p>
  </div>
</section>

<div class="welcome-screen" id="welcomeScreen" hidden role="dialog" aria-modal="true" aria-labelledby="welcomeTitle">
  <button type="button" class="welcome-close" id="welcomeClose" aria-label="Cerrar bienvenida">×</button>
  <div class="welcome-content">
    <div class="welcome-sparkles" aria-hidden="true"><span>✦</span><span>✧</span><span>✦</span><span>✧</span><span>✦</span></div>
    <div class="welcome-orbit"><div class="welcome-avatar" id="welcomeAvatar">U</div></div>
    <div class="welcome-kicker">ACCESO CONFIRMADO</div>
    <h2 id="welcomeTitle">¡Bienvenido/a a El Radar!</h2>
    <p class="welcome-name" id="welcomeName">Usuario</p>
    <p class="welcome-message">Nos alegra tenerte aquí. Tu espacio de movilidad te espera.</p>
    <button type="button" class="welcome-continue" id="welcomeContinue">Continuar a El Radar <span aria-hidden="true">→</span></button>
    <div class="welcome-progress" aria-hidden="true"><span></span></div>
  </div>
</div>


<div class="trial-notice-overlay" id="trialNoticeOverlay" hidden role="dialog" aria-modal="true" aria-labelledby="trialNoticeTitle">
  <section class="trial-notice-card" id="trialNoticeCard" role="document">
    <header class="trial-notice-hero">
      <div class="trial-notice-icon" id="trialNoticeIcon" aria-hidden="true">🎁</div>
      <div class="trial-notice-eyebrow" id="trialNoticeEyebrow">BENEFICIO DE BIENVENIDA</div>
      <h2 id="trialNoticeTitle">Tu periodo de prueba</h2>
      <p id="trialNoticeSubtitle">Explora El Radar y descubre todo lo que puedes hacer.</p>
    </header>
    <div class="trial-notice-body">
      <div class="trial-notice-summary" id="trialNoticeSummary">
        <div class="trial-notice-days"><strong id="trialNoticeDays">0</strong><span id="trialNoticeDaysLabel">días gratis</span></div>
        <div class="trial-notice-summary-copy"><strong id="trialNoticeSummaryTitle">Prueba gratuita activa</strong><span id="trialNoticeSummaryText">Durante tu periodo de prueba no se aplica la tarifa de uso.</span></div>
      </div>
      <div class="trial-notice-date" id="trialNoticeDate">📅 <span>Tu prueba vence el <strong id="trialNoticeDateValue">—</strong></span></div>
      <p class="trial-notice-footnote" id="trialNoticeFootnote">Al finalizar el periodo de prueba, necesitarás recargar tu cuenta para continuar usando la aplicación.</p>
      <div class="trial-notice-actions"><button type="button" class="trial-notice-primary" id="trialNoticePrimary">Comenzar a usar El Radar →</button><button type="button" class="trial-notice-secondary" id="trialNoticeSecondary">Recordármelo después</button></div>
    </div>
  </section>
</div>

<div class="user-details-overlay" id="userDetailsModal" hidden role="dialog" aria-modal="true" aria-labelledby="userDetailsTitle">
  <div class="user-details-backdrop" id="userDetailsBackdrop"></div>
  <section class="user-details-dialog" role="document">
    <header class="user-details-header">
      <div>
        <span class="user-details-eyebrow">PERFIL DE LA CUENTA</span>
        <h2 id="userDetailsTitle">Datos del usuario</h2>
        <p class="user-details-subtitle">Consulta tus datos personales y la información de tu cuenta.</p>
      </div>
      <button type="button" class="user-details-close" id="userDetailsClose" aria-label="Cerrar cuadro de datos">×</button>
    </header>
    <div class="user-details-identity">
      <div class="user-details-main-photo" id="userDetailsMainPhoto">U</div>
      <div class="user-details-name-wrap">
        <h3 id="userDetailsFullName">Usuario</h3>
        <p id="userDetailsAtName">@usuario</p>
        <span class="user-details-state" id="userDetailsStateBadge">Activo</span>
      </div>
    </div>
    <div class="user-profile-tabs" role="tablist" aria-label="Secciones del perfil">
      <button type="button" class="user-profile-tab active" id="personalDataTab" role="tab" aria-selected="true" aria-controls="personalDataPanel">Datos Personales</button>
      <button type="button" class="user-profile-tab" id="accountDataTab" role="tab" aria-selected="false" aria-controls="accountDataPanel">Datos de la cuenta</button>
    </div>
    <section class="user-profile-tab-panel" id="personalDataPanel" role="tabpanel" aria-labelledby="personalDataTab">
      <div class="user-details-grid">
        <div class="user-detail-item"><span>Nombre y apellidos</span><strong id="detailsFullName">—</strong></div>
        <div class="user-detail-item"><span>Carné de identidad</span><strong id="detailsIdentityCard">—</strong></div>
        <div class="user-detail-item"><label for="profilePhone">Teléfono</label><input id="profilePhone" type="tel" inputmode="tel" autocomplete="tel" maxlength="25" placeholder="Ej.: +53 5XXXXXXX"></div>
        <div class="user-detail-item"><label for="profileEmail">Correo electrónico</label><input id="profileEmail" type="email" inputmode="email" autocomplete="email" maxlength="190" placeholder="correo@ejemplo.com"></div>
        <div class="user-detail-item"><label for="profileProvince">Provincia</label><select id="profileProvince"><option value="">Selecciona tu provincia</option></select></div>
        <div class="user-detail-item"><label for="profileMunicipality">Municipio</label><select id="profileMunicipality" disabled><option value="">Primero selecciona una provincia</option></select></div>
        <div class="user-detail-item user-profile-save-item"><button type="button" class="btn" id="saveProfileDataButton">Guardar cambios</button><small id="profileDataFeedback" role="status" aria-live="polite"></small></div>
        <div class="user-detail-item user-detail-photo-item"><span>Foto</span><div id="detailsPhotoValue">Sin foto</div></div>
      </div>
    </section>
    <section class="user-profile-tab-panel" id="accountDataPanel" role="tabpanel" aria-labelledby="accountDataTab" hidden>
      <div class="user-details-grid">
        <div class="user-detail-item"><span>Nombre de usuario</span><strong id="detailsUsername">—</strong></div>
        <div class="user-detail-item"><span>Saldo</span><strong id="detailsBalance">$0.00</strong></div>
        <div class="user-detail-item"><span>Tiempo disponible</span><strong id="detailsAvailableTime">0</strong></div>
        <div class="user-detail-item"><span>Tipo de cuenta</span><strong id="detailsAccountType">—</strong></div>
        <div class="user-detail-item account-dates-item"><span>Fecha de creación de la cuenta</span><strong id="detailsCreatedDate">No disponible</strong></div>
        <div class="user-detail-item account-dates-item"><span>Fecha de vencimiento</span><strong id="detailsExpiryDate">No aplica</strong></div>
        <div class="user-detail-item account-dates-item"><span>Días disponibles</span><strong id="detailsDaysRemaining">—</strong></div>
        <div class="user-detail-item account-dates-item" style="grid-column:1/-1">
          <label for="profileArrivalRadius">Distancia para el aviso de llegada del transporte</label>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <select id="profileArrivalRadius" style="min-width:150px">
              <option value="5">5 metros</option><option value="6">6 metros</option><option value="7">7 metros</option>
              <option value="8">8 metros</option><option value="9">9 metros</option><option value="10">10 metros</option>
              <option value="11">11 metros</option><option value="12">12 metros</option><option value="13">13 metros</option>
              <option value="14">14 metros</option><option value="15">15 metros</option><option value="16">16 metros</option>
              <option value="17">17 metros</option><option value="18">18 metros</option><option value="19">19 metros</option>
              <option value="20">20 metros</option>
            </select>
            <button type="button" class="btn" id="saveProfileArrivalRadius">Guardar distancia</button>
          </div>
          <small id="profileArrivalRadiusFeedback" role="status" aria-live="polite">Elige una distancia entre 5 y 20 metros.</small>
        </div>
      </div>
      <section class="delete-account-panel" id="deleteAccountPanel" hidden aria-labelledby="deleteAccountTitle">
      <div class="delete-account-warning-icon" aria-hidden="true">!</div>
      <div class="delete-account-copy">
        <h3 id="deleteAccountTitle">Eliminar cuenta permanentemente</h3>
        <p>Esta acción no se puede deshacer. Se eliminará tu cuenta de El Radar. Para confirmar, introduce tu contraseña.</p>
      </div>
      <div class="delete-account-form">
        <label for="deleteAccountPassword">Contraseña actual</label>
        <input id="deleteAccountPassword" type="password" autocomplete="current-password" placeholder="Introduce tu contraseña">
      </div>
      <div class="delete-account-error" id="deleteAccountError" role="alert" hidden></div>
      <div class="delete-account-actions">
        <button type="button" class="btn btn-delete-cancel" id="cancelDeleteAccount">Cancelar</button>
        <button type="button" class="btn btn-delete-confirm" id="confirmDeleteAccount">Eliminar definitivamente</button>
      </div>
      </section>
    </section>
    <footer class="user-details-footer">
      <span>🔒 Tus datos se muestran solo dentro de tu sesión.</span>
      <div class="user-details-footer-actions">
        <button type="button" class="btn btn-delete-outline" id="startDeleteAccount">Eliminar cuenta</button>
        <button type="button" class="btn" id="userDetailsDone">Cerrar</button>
      </div>
    </footer>
  </section>
</div>

<aside class="sidebar" id="sidebar">
    <div class="brand">
        <img class="brand-logo" src="./icon-512.png" alt="El Radar" width="512" height="512">
        <div>
            <h2>El Radar</h2>
            <p>MOVILIDAD INTELIGENTE</p>
        </div>
    </div>

    <div class="menu-label">ESPACIO DE TRABAJO</div>

    <nav class="nav">
        <a href="#radar" class="nav-link active" data-page="radar">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 12 18 6M12 3v2M21 12h-2M12 21v-2M3 12h2"/><circle cx="12" cy="12" r="2"/></svg></span>
            <span>El Radar</span>
        </a>

        <button class="nav-link nav-toggle" id="settingsToggle"
            aria-expanded="false" aria-controls="settingsSubmenu" type="button">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"/><path d="m19.4 15 .1.1 1.4 1.1-1.4 2.4-1.7-.7a8 8 0 0 1-1.7 1l-.3 1.8h-2.8l-.3-1.8a8 8 0 0 1-1.7-1l-1.7.7-1.4-2.4 1.4-1.1a8 8 0 0 1 0-2l-1.4-1.1 1.4-2.4 1.7.7a8 8 0 0 1 1.7-1l.3-1.8h2.8l.3 1.8a8 8 0 0 1 1.7 1l1.7-.7 1.4 2.4-1.4 1.1a8 8 0 0 1 0 2Z"/></svg></span>
            <span>Configuración</span>
            <span class="chevron">⌄</span>
        </button>

        <div class="submenu" id="settingsSubmenu">
            <a href="#recargar" data-page="recargar"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h3"/></svg></span>Recargar cuenta</a>
            <a href="#password" data-page="password"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3m-4 5v2"/></svg></span>Cambiar contraseña</a>
            <a href="#solicitar-transportista" id="transportRequestMenuLink" data-page="solicitar-transportista"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2-6h14l2 6v6h-2v-2H5v2H3zM5 13h14M7 16h.01M17 16h.01"/><path d="M7 7l1-3h8l1 3"/></svg></span>Solicitar Cuenta transportista</a>
        </div>

        <button class="nav-link nav-toggle" id="adminNavLink" aria-expanded="false" aria-controls="adminSubmenu" type="button" hidden>
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg></span>
            <span>Administración</span>
            <span class="chevron">⌄</span>
        </button>
        <div class="submenu" id="adminSubmenu">
            <a href="#administracion" data-page="administracion"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h18v18H3zM8 17v-5m4 5V7m4 10v-8"/></svg></span>Resumen general</a>
            <a href="#admin-finanzas" data-page="admin-finanzas"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v13H3zM3 10h18M7 15h4"/><path d="M16 3v6"/></svg></span>Finanzas</a>
            <a href="#admin-pagos-editores" data-page="admin-pagos-editores" data-admin-only="1"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h4m5-1 2 2 3-4"/></svg></span>Pagos a Editores</a>
            <a href="#admin-usuarios" data-page="admin-usuarios"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM20 8v6m3-3h-6"/></svg></span>Usuarios</a>
            <a href="#admin-transport-historial" data-page="admin-transport-historial" data-admin-only="1"><span class="submenu-icon">◷</span>Historial de transportistas</a>
            <a href="#admin-mis-transportistas" data-page="admin-mis-transportistas" data-editor-only="1"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2-6h14l2 6v6h-2v-2H5v2H3zM5 13h14M7 16h.01M17 16h.01"/><path d="M7 7l1-3h8l1 3"/><path d="M12 10v3m-1.5-1.5h3"/></svg></span>Mis Transportistas</a>
            <a href="#editor-estado-cuenta" data-page="editor-estado-cuenta" data-editor-only="1"><span class="submenu-icon">▤</span>Estado de cuenta</a>
            <a href="#admin-solicitudes" data-page="admin-solicitudes"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13l2-6h14l2 6v6h-2v-2H5v2H3zM5 13h14M7 16h.01M17 16h.01"/><path d="M7 7l1-3h8l1 3"/></svg></span>Solicitudes de transportista</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="tarifas"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M17 7.5c0-1.7-2.2-3-5-3S7 5.8 7 7.5s2.2 3 5 3 5 1.3 5 3-2.2 3-5 3-5-1.3-5-3"/></svg></span>Tarifas</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="transportes"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m5 11 1.5-4h11L19 11M4 11h16v7H4zM7 18v2m10-2v2M7 14h2m6 0h2"/></svg></span>Tipos de transporte</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="bases-datos"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v7c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12v7c0 1.7 3.6 3 8 3s8-1.3 8-3v-7"/></svg></span>Gestión de Bases de Datos</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="smtp"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 5h18v14H3zM3 6l9 7 9-7"/></svg></span>Correo SMTP</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="cupones"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 10h18v11H3zM2 7h20v3H2zM12 7v14M12 7H8.5a2.5 2.5 0 1 1 2.5-2.5V7Zm0 0h3.5A2.5 2.5 0 1 0 13 4.5V7Z"/></svg></span>Bonos y cupones</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="ofertas-recarga"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4zM8 12h.01M16 12h.01M12 9v6m2-4.5c0-.8-.9-1.5-2-1.5s-2 .7-2 1.5.9 1.5 2 1.5 2 .7 2 1.5-.9 1.5-2 1.5-2-.7-2-1.5"/></svg></span>Ofertas vía recarga</a>
            <a href="#admin-ajustes" data-page="admin-ajustes" data-settings-tab-nav="novedades"><span class="submenu-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/></svg></span>Novedades y promociones</a>
        </div>

        <a href="#sobre" class="nav-link" data-page="sobre">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.6 2.6 0 1 1 4.5 1.8c-1.2 1.1-2 1.5-2 3.2M12 17h.01"/></svg></span>
            <span>Sobre la app</span>
        </a>

        <button class="nav-link" id="shareApplicationLink" type="button">
            <span class="nav-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M5 13v7h14v-7"/></svg></span>
            <span>Compartir aplicación</span>
        </button>
    </nav>

    <div class="sidebar-promo">
        <div class="promo-icon"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 1.5 6.5L20 12l-6.5 1.5L12 20l-1.5-6.5L4 12l6.5-2.5L12 3Z"/></svg></div>
        <strong>Tu cuenta, a tu manera</strong>
        <p>Administra tus opciones desde un solo lugar.</p>
    </div>

    <div class="sidebar-footer">EL RADAR · VERSIÓN 1.0</div>
</aside>

<div class="overlay" id="overlay"></div>

<main class="main">
    <header class="topbar">
        <div class="topbar-left">
            <button class="mobile-menu" id="mobileMenu" aria-label="Abrir menú">☰</button>
            <div>
                <h1 id="pageTitle">El Radar</h1>
                <div class="topbar-subtitle" id="pageSubtitle">Tu centro de movilidad</div>
            </div>
        </div>

        <div class="top-actions">
            <button class="icon-button" id="notificationButton"
                aria-label="Notificaciones" title="Notificaciones" type="button">
                🔔<span class="notification-count" id="notificationCount" hidden>0</span>
            </button>
            <div class="profile">
                <div class="avatar profile-avatar-clickable" id="profileAvatar" role="button" tabindex="0" aria-label="Mostrar datos del usuario" title="Haz clic para ver tus datos">U</div>
                <div class="profile-info">
                    <strong id="profileName">Usuario</strong>
                    <small id="profileType">cliente</small>
                </div>
            </div>
            <button class="icon-button" id="logoutButton" type="button" aria-label="Cerrar sesión" title="Cerrar sesión">↪</button>
        </div>
    </header>

    <div class="content">

        <!-- PANEL PRINCIPAL -->
        <section class="page active" id="radar">
            <div class="hero">
                <div class="hero-content">
                    <div class="eyebrow">TU PANEL PRINCIPAL</div>
                    <h2>¡Muévete con<br>inteligencia! ✨</h2>
                    <p>Bienvenido a El Radar. Gestiona tu cuenta y consulta
                    la información de tu actividad desde un solo lugar.</p>
                </div>
                <div class="hero-art"><span>🧭</span></div>
            </div>

            <div class="section-heading">
                <div>
                    <h2>Resumen de tu cuenta</h2>
                    <p>Una vista rápida de tu actividad</p>
                </div>
                <span class="status-pill" id="syncStatus" title="Actualización automática desde la base de datos">Conectando con la base de datos</span>
            </div>

            <div class="stats">
                <div class="stat-card">
                    <div class="stat-top">
                        <div class="stat-icon cyan">💳</div>
                        <span class="stat-tag">CUENTA</span>
                    </div>
                    <div class="stat-label">Saldo disponible</div>
                    <div class="stat-value" id="balanceValue">$0.00</div>
                    <div class="stat-foot">Saldo actual en la base de datos</div>
                </div>
            </div>

            <div class="section-heading">
                <div>
                    <h2>Tu espacio de actividad</h2>
                    <p>Información y accesos rápidos</p>
                </div>
            </div>

            <div class="dashboard-grid">
                <div class="card">
                    <h3>📍 Área de movilidad</h3>
                    <p class="card-description">
                        Mapa en vivo centrado en la ubicación GPS de este dispositivo.
                    </p>
                    <div class="mobility-map-tools">
                        <span class="gps-status" id="gpsStatus" role="status" aria-live="polite">Esperando permiso para utilizar el GPS…</span>
                        <div class="map-actions-right">
                            <label class="map-status-control" for="mapStatusSwitch" title="Cambiar el estado de tu cuenta">
                                <span id="mapStatusLabel">Inactivo</span>
                                <span class="map-switch">
                                    <input id="mapStatusSwitch" type="checkbox" role="switch" aria-label="Estado de la cuenta: activo o inactivo" aria-checked="false">
                                    <span class="map-switch-slider" aria-hidden="true"></span>
                                </span>
                            </label>
                        </div>
                    </div>
                    <div class="map-vehicle-filter">
                        <label for="mobilityVehicleFilter">🚘 Filtrar por tipo de transporte</label>
                        <select id="mobilityVehicleFilter" aria-label="Mostrar transportistas por tipo de transporte">
                            <option value="">Todos los tipos de transporte</option>
                        </select>
                    </div>
                    <div id="mobilityMap" class="mobility-map" role="region" aria-label="Mapa con tu ubicación y transportistas activos a un kilómetro"></div>
                    <div class="nearby-transport-status" id="nearbyTransportersStatus" role="status" aria-live="polite">Buscando transportistas activos dentro de 1 km…</div>
                    <div class="gps-address-panel" aria-live="polite">
                        <span class="gps-address-icon" aria-hidden="true">📍</span>
                        <div><strong>Dirección de tu ubicación</strong><p id="gpsAddress">Esperando la ubicación GPS para consultar la dirección…</p></div>
                    </div>
                    <small class="map-privacy-note">La ubicación se guarda en UBICACION y se actualiza mientras tu cuenta esté activa. El GPS requiere permiso y conexión segura (HTTPS).</small>
                </div>

                <div class="card latest-news-card">
                    <h3>📰 Últimas novedades</h3>
                    <p class="card-description">Bonos, ofertas de recarga, promociones y noticias de El Radar.</p>
                    <div id="latestNewsList" class="latest-news-list" aria-live="polite"><div class="activity"><div class="activity-icon">⏳</div><div><strong>Cargando novedades…</strong><p>Estamos consultando las últimas publicaciones.</p></div></div></div>
                </div>
            </div>
        </section>

        <!-- RECARGAR CUENTA -->
        <section class="page" id="recargar">
            <div class="section-heading">
                <div>
                    <h2>Recargar cuenta</h2>
                    <p>Registra el ID de la transferencia para confirmar el pago</p>
                </div>
            </div>

            <div class="recharge-tabs" role="tablist" aria-label="Opciones de cuenta">
                <button type="button" class="recharge-tab active" role="tab" aria-selected="true" aria-controls="recharge-pane-add" data-recharge-tab="recharge-pane-add">Agregar saldo a tu cuenta</button>
                <button type="button" class="recharge-tab" role="tab" aria-selected="false" aria-controls="recharge-pane-transfer" data-recharge-tab="recharge-pane-transfer">Transferir saldo a otro usuario</button>
                <button type="button" class="recharge-tab" role="tab" aria-selected="false" aria-controls="recharge-pane-history" data-recharge-tab="recharge-pane-history">Mis operaciones</button>
                <button type="button" class="recharge-tab" role="tab" aria-selected="false" aria-controls="recharge-pane-coupon" data-recharge-tab="recharge-pane-coupon">🎁 Canjear cupón</button>
            </div>

            <div class="recharge-tab-pane active" id="recharge-pane-add" role="tabpanel">
            <div class="card form-card">
                <div class="form-icon">💳</div>
                <h3>Agregar saldo a tu cuenta</h3>
                <p class="card-description">
                    Introduce el ID de la transferencia que aparece en el comprobante.
                </p>

                <div class="notice">
                    <strong>Importante:</strong> introduce el ID de una transferencia que ya aparezca en el registro Confirmacion y todavía no esté asignada a otra cuenta. No necesitas aprobación de un administrador: el sistema comprobará el registro, lo asignará a tu usuario y acreditará automáticamente la recarga. Si existe una oferta activa para tu tipo de cuenta y el importe alcanza su mínimo, también añadirá la bonificación.
                </div>

                <form id="rechargeForm">
                    <div class="form-group">
                        <label for="transferId">ID de la transferencia</label>
                        <input id="transferId" type="text" maxlength="150" autocomplete="off"
                            placeholder="Introduce el ID o referencia de la transferencia" required>
                        <span class="help-text">Escribe el identificador que aparece en el comprobante bancario.</span>
                    </div>

                    <button class="btn" type="submit">＋ Confirmar transferencia</button>
                </form>
            </div>

            </div>

            <div class="recharge-tab-pane" id="recharge-pane-history" role="tabpanel" hidden>
            <div class="card form-card" id="myBalanceOperationsCard" style="margin-top:22px">
                <div class="form-icon">📒</div>
                <h3>Mis operaciones</h3>
                <p class="card-description">Historial de recargas, saldos agregados, transferencias y descuentos diarios aplicados por El Radar.</p>
                <div class="notice"><strong>Saldo actual:</strong> <span id="operationsCurrentBalance">Cargando…</span></div>
                <div id="balanceOperationsList" aria-live="polite"><p class="card-description">Cargando operaciones…</p></div>
            </div>

            </div>

            <div class="recharge-tab-pane" id="recharge-pane-coupon" role="tabpanel" hidden>
                <div class="card form-card" style="margin-top:22px;max-width:620px">
                    <div class="form-icon">🎁</div>
                    <h3>Canjear bono promocional</h3>
                    <p class="card-description">Introduce el código que recibiste para aplicar una oferta especial a tu cuenta. El sistema comprobará tu tipo de usuario, la vigencia y las condiciones del cupón.</p>
                    <form id="couponRedeemForm">
                        <div class="form-group"><label for="couponRedeemCode">Código del cupón</label><input id="couponRedeemCode" type="text" maxlength="40" autocomplete="off" placeholder="Ej. RADAR20" required style="text-transform:uppercase"></div>
                        <button class="btn" id="couponRedeemButton" type="submit">Canjear cupón</button>
                        <p id="couponRedeemStatus" role="status" aria-live="polite"></p>
                    </form>
                </div>
            </div>

            <div class="recharge-tab-pane" id="recharge-pane-transfer" role="tabpanel" hidden>
            <div class="card form-card" style="margin-top:22px">
                <div class="form-icon">↗️</div>
                <h3>Transferir saldo a otro usuario</h3>
                <p class="card-description">Envía una parte de tu saldo a otra cuenta de tipo Cliente o Transportista.</p>
                <div class="notice"><strong>Importante:</strong> la transferencia descuenta el importe de tu saldo y lo acredita al destinatario. No se permiten transferencias a tu propia cuenta, a administradores ni por encima del saldo disponible.</div>
                <form id="balanceTransferForm">
                    <div class="form-group">
                        <label for="balanceRecipient">Usuario destinatario</label>
                        <input id="balanceRecipient" type="text" maxlength="100" autocomplete="off" placeholder="Nombre de usuario del destinatario" required>
                    </div>
                    <div class="form-group">
                        <label for="balanceTransferAmount">Importe a transferir</label>
                        <input id="balanceTransferAmount" type="number" min="0.01" step="0.01" inputmode="decimal" placeholder="0.00" required>
                    </div>
                    <button class="btn" type="submit">Transferir saldo</button>
                </form>
            </div>
            </div>
        </section>

        <div class="recharge-result-overlay" id="rechargeResultModal" hidden role="dialog" aria-modal="true" aria-labelledby="rechargeResultTitle">
            <div class="recharge-result-backdrop"></div>
            <section class="recharge-result-dialog" role="document">
                <div class="recharge-result-icon">✓</div>
                <h2 id="rechargeResultTitle">¡Recarga acreditada!</h2>
                <p class="recharge-result-intro" id="rechargeResultIntro">El importe se ha aplicado a tu cuenta y se han actualizado tus días disponibles.</p>
                <div class="recharge-result-grid">
                    <div><span>Importe de la transferencia</span><strong id="rechargeResultAmount">—</strong></div>
                    <div><span>Bono promocional</span><strong id="rechargeResultBonus">—</strong></div>
                    <div><span>Saldo anterior</span><strong id="rechargeResultPreviousBalance">—</strong></div>
                    <div><span>Total acreditado (recarga + bono)</span><strong id="rechargeResultTotalCredit">—</strong></div>
                    <div><span>Nuevo saldo de tu cuenta</span><strong id="rechargeResultNewBalance">—</strong></div>
                    <div><span>Días concedidos</span><strong id="rechargeResultGrantedDays">—</strong></div>
                    <div><span>Días disponibles en total</span><strong id="rechargeResultAvailableDays">—</strong></div>
                    <div><span>Vencimiento actualizado</span><strong id="rechargeResultExpiry">—</strong></div>
                </div>
                <p class="recharge-result-formula" id="rechargeResultFormula">Días nuevos = importe transferido ÷ tarifa de tu tipo de cuenta.</p>
                <button type="button" class="btn recharge-result-button" id="rechargeResultClose">Aceptar</button>
            </section>
        </div>

        <!-- CAMBIAR CONTRASEÑA -->
        <section class="page" id="password">
            <div class="section-heading">
                <div>
                    <h2>Cambiar contraseña</h2>
                    <p>Mantén segura tu cuenta</p>
                </div>
            </div>

            <div class="card form-card">
                <div class="form-icon">🔐</div>
                <h3>Seguridad y acceso</h3>
                <p class="card-description">
                    Completa los campos para preparar el cambio de contraseña.
                </p>

                <div class="notice">
                    Por seguridad, el cambio real debe verificarse en el servidor
                    o sistema de autenticación. Este formulario no cambia todavía
                    las credenciales de la cuenta.
                </div>

                <form id="passwordForm">
                    <div class="form-group">
                        <label for="currentPassword">Contraseña actual</label>
                        <input id="currentPassword" type="password"
                            autocomplete="current-password"
                            placeholder="Introduce tu contraseña actual" required>
                    </div>

                    <div class="form-group">
                        <label for="newPassword">Nueva contraseña</label>
                        <input id="newPassword" type="password" minlength="8"
                            autocomplete="new-password"
                            placeholder="Mínimo 8 caracteres" required>
                        <span class="help-text">Utiliza al menos 8 caracteres.</span>
                    </div>

                    <div class="form-group">
                        <label for="confirmPassword">Confirmar contraseña</label>
                        <input id="confirmPassword" type="password" minlength="8"
                            autocomplete="new-password"
                            placeholder="Repite la nueva contraseña" required>
                    </div>

                    <button class="btn" type="submit">✓ Validar contraseña</button>
                </form>
            </div>
        </section>

        <!-- SOLICITAR CUENTA TRANSPORTISTA -->
        <section class="page" id="solicitar-transportista">
            <div class="section-heading">
                <div>
                    <h2>Solicitar Cuenta transportista</h2>
                    <p>Solicita que tu cuenta sea revisada para operar como transportista</p>
                </div>
            </div>

            <div class="card form-card">
                <div class="form-icon">🚕</div>
                <h3>Da el siguiente paso con El Radar</h3>
                <p class="card-description">
                    Envía tu solicitud para que sea revisada por la administración de El Radar.
                </p>

                <div class="notice">
                    <strong>Importante:</strong> enviar la solicitud no cambia automáticamente tu tipo de cuenta.
                    Tu cuenta seguirá siendo la actual hasta que la solicitud sea revisada y aprobada por la administración.
                </div>

                <div class="transport-request-status" id="transportRequestStatus" data-state="cargando" role="status" aria-live="polite">
                    <div class="transport-request-status-icon" id="transportRequestStatusIcon" aria-hidden="true">…</div>
                    <div>
                        <strong id="transportRequestStatusTitle">Consultando el estado</strong>
                        <span id="transportRequestStatusText">Estamos comprobando si ya existe una solicitud para tu cuenta.</span>
                    </div>
                </div>

                <form id="transportAccountForm" class="auth-form" novalidate>
                    <div class="form-group">
                        <label for="transportVehicleType">Tipo de vehículo *</label>
                        <select id="transportVehicleType" name="tipo_vehiculo" required>
                            <option value="">Cargando tipos de transporte…</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="transportVehiclePlate">Chapa *</label>
                        <input id="transportVehiclePlate" name="chapa" type="text" maxlength="30" autocomplete="off" placeholder="Chapa del vehículo" required>
                    </div>
                    <div class="form-group">
                        <label for="transportDrivingLicense">No. de licencia de conducción *</label>
                        <input id="transportDrivingLicense" name="licencia_conduccion" type="text" maxlength="60" autocomplete="off" placeholder="Número de licencia" required>
                    </div>
                    <div class="form-group">
                        <label for="transportPhone">Teléfono *</label>
                        <input id="transportPhone" name="telefono" type="tel" maxlength="30" inputmode="tel" autocomplete="tel" placeholder="Teléfono de contacto" required>
                    </div>
                    <div class="form-group">
                        <label for="transportVehiclePhoto">Foto del vehículo *</label>
                        <input id="transportVehiclePhoto" name="foto_vehiculo" type="file" accept="image/*" capture="environment" required>
                        <span class="help-text">Selecciona una foto de la galería o toma una con la cámara. Se reducirá automáticamente antes de enviarla.</span>
                        <div id="transportVehiclePhotoPreview" class="photo-preview" hidden><img id="transportVehiclePhotoPreviewImage" alt="Vista previa de la foto del vehículo"></div>
                    </div>
                    <button class="btn" id="sendTransportRequestButton" type="submit">🚕 Solicitar cuenta transportista</button>
                </form>
            </div>
        </section>

        <!-- ADMINISTRACIÓN: su acceso se muestra exclusivamente a cuentas de tipo Admin -->
        <section class="page" id="administracion">
            <div class="section-heading">
                <div>
                    <h2>Administración</h2>
                    <p>Resumen general de las cuentas de El Radar</p>
                </div>
                <button class="btn" id="refreshAdminSummary" type="button">↻ Actualizar datos</button>
            </div>

            <div class="stats admin-stats" aria-live="polite">
                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon purple">👥</div><span class="stat-tag">CUENTAS</span></div>
                    <div class="stat-label">Usuarios registrados</div>
                    <div class="stat-value" id="adminTotalUsers">—</div>
                    <div class="stat-foot">Total de cuentas en la base de datos</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon green">✓</div><span class="stat-tag">ESTADO</span></div>
                    <div class="stat-label">Usuarios activos</div>
                    <div class="stat-value" id="adminActiveUsers">—</div>
                    <div class="stat-foot">Cuentas marcadas como activas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon blue">Ⅱ</div><span class="stat-tag">ESTADO</span></div>
                    <div class="stat-label">Usuarios inactivos</div>
                    <div class="stat-value" id="adminInactiveUsers">—</div>
                    <div class="stat-foot">Cuentas marcadas como inactivas</div>
                </div>
                <div class="stat-card">
                    <div class="stat-top"><div class="stat-icon cyan">🚕</div><span class="stat-tag">SOLICITUDES</span></div>
                    <div class="stat-label">Solicitudes transportista pendientes</div>
                    <div class="stat-value" id="adminPendingRequests">—</div>
                    <div class="stat-foot">Solicitudes por revisar</div>
                </div>
            </div>



            <div class="card" style="margin-top:20px">
                <h3>Panel de administración</h3>
                <p class="card-description" style="margin-bottom:8px">Los datos de este panel se consultan directamente en MySQL. Solo las cuentas cuyo tipo sea <strong>Admin</strong> pueden abrir este módulo y solicitar el resumen al servidor.</p>
                <p id="adminSummaryUpdated" role="status" aria-live="polite" style="font-size:11px;color:var(--muted);margin:0">Esperando los datos de administración…</p>
            </div>
        </section>

        <!-- Finanzas: ingresos y estado de las transferencias -->
        <section class="page" id="admin-finanzas">
            <div class="section-heading">
                <div><h2>Finanzas</h2><p>Resumen de los ingresos operativos de El Radar y las transferencias recibidas.</p></div>
                <button class="btn" id="refreshAdminFinances" type="button">↻ Actualizar</button>
            </div>
            <div class="stats admin-stats" aria-live="polite">
                <div class="stat-card"><div class="stat-top"><div class="stat-icon green">$</div><span class="stat-tag">INGRESOS</span></div><div class="stat-label">Ingresos de la app</div><div class="stat-value" id="financeAppIncome">—</div><div class="stat-foot">Cobros diarios registrados como ingreso operativo</div></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon blue">✓</div><span class="stat-tag">CONFIRMADAS</span></div><div class="stat-label">Transferencias confirmadas</div><div class="stat-value" id="financeConfirmedAmount">—</div><div class="stat-foot" id="financeConfirmedCount">— transferencias</div></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon cyan">◷</div><span class="stat-tag">PENDIENTES</span></div><div class="stat-label">Transferencias por confirmar</div><div class="stat-value" id="financePendingAmount">—</div><div class="stat-foot" id="financePendingCount">— transferencias</div></div>
            </div>
            <div class="card" style="margin-top:20px">
                <h3>Movimientos de transferencia</h3>
                <p class="card-description">Las transferencias confirmadas ya fueron asignadas a una cuenta. Las pendientes todavía no han sido reclamadas. El total de transferencias no se suma a los ingresos de la app para evitar confundir recargas de usuarios con ganancias operativas.</p>
                <div class="finance-filters" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0">
                    <label style="display:grid;gap:6px;font-size:12px">Desde
                        <input id="financeFilterDateFrom" type="date" class="form-control" aria-label="Filtrar desde fecha">
                    </label>
                    <label style="display:grid;gap:6px;font-size:12px">Hasta
                        <input id="financeFilterDateTo" type="date" class="form-control" aria-label="Filtrar hasta fecha">
                    </label>
                    <label style="display:grid;gap:6px;font-size:12px">Usuario
                        <input id="financeFilterUser" type="search" class="form-control" placeholder="Nombre de usuario" aria-label="Filtrar por usuario">
                    </label>
                    <label style="display:grid;gap:6px;font-size:12px">Estado
                        <select id="financeFilterStatus" class="form-control" aria-label="Filtrar por estado">
                            <option value="">Todos los estados</option>
                            <option value="confirmada">Confirmadas</option>
                            <option value="pendiente">Por confirmar</option>
                        </select>
                    </label>
                    <label style="display:grid;gap:6px;font-size:12px">ID de transferencia
                        <input id="financeFilterId" type="search" class="form-control" placeholder="Buscar ID" aria-label="Filtrar por ID de transferencia">
                    </label>
                    <div style="display:flex;align-items:end">
                        <button class="btn btn-secondary" id="clearFinanceFilters" type="button">Limpiar filtros</button>
                    </div>
                </div>
                <p id="adminFinancesStatus" role="status" aria-live="polite" style="font-size:12px;color:var(--muted)">Cargando información financiera…</p>
                <div id="adminFinancesRows" class="finance-transfer-grid" aria-live="polite"><p class="card-description">Cargando transferencias…</p></div>
            </div>
        </section>

        <div class="finance-user-picker-overlay" id="financeUserPicker" hidden role="dialog" aria-modal="true" aria-labelledby="financeUserPickerTitle">
            <div class="finance-user-picker-dialog">
                <header class="finance-user-picker-header">
                    <div><h3 id="financeUserPickerTitle">Asignar transferencia a un usuario</h3><p class="card-description" id="financeUserPickerTransferInfo">Selecciona la cuenta que recibirá esta recarga.</p></div>
                    <button class="admin-user-editor-close" id="financeUserPickerClose" type="button" aria-label="Cerrar">×</button>
                </header>
                <label class="finance-user-picker-search-label" for="financeUserPickerSearch">Buscar usuario</label>
                <input id="financeUserPickerSearch" class="form-control" type="search" placeholder="Nombre, usuario, ID, teléfono, correo o carné…" autocomplete="off">
                <p id="financeUserPickerStatus" class="card-description" role="status" aria-live="polite">Cargando usuarios…</p>
                <div id="financeUserPickerList" class="finance-user-picker-grid"></div>
            </div>
        </div>

        <section class="page" id="admin-pagos-editores">
            <div class="section-heading"><div><h2>Pagos a Editores</h2><p>Consulta las ganancias disponibles (ya descontados los pagos anteriores) y registra el ID de cada transferencia enviada a la tarjeta del Editor.</p></div><button class="btn" id="refreshEditorPayments" type="button">↻ Actualizar</button></div>
            <div class="stats admin-stats" style="margin-bottom:16px">
                <div class="stat-card"><div class="stat-top"><div class="stat-icon green">$</div><span class="stat-tag">GANANCIAS</span></div><h3 id="editorPaymentsEarned">$0.00</h3><p class="card-description">Ganancias disponibles después de descontar los pagos</p></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon blue">✓</div><span class="stat-tag">PAGADO</span></div><h3 id="editorPaymentsPaid">$0.00</h3><p class="card-description">Total pagado a Editores</p></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon purple">⌛</div><span class="stat-tag">PENDIENTE</span></div><h3 id="editorPaymentsPending">$0.00</h3><p class="card-description">Pendiente de pago</p></div>
            </div>
            <p id="editorPaymentsStatus" class="card-description" role="status" aria-live="polite">Cargando Editores…</p>
            <div id="editorPaymentsCards" class="admin-users-grid" aria-live="polite"></div>
        </section>
        <section class="page" id="admin-transport-historial">
            <div class="section-heading"><div><h2>Historial de transportistas</h2><p>Registro de quién aprobó o revocó cada autorización.</p></div><button class="btn" id="refreshTransportAudit" type="button">↻ Actualizar</button></div>
            <div class="card" style="margin:0 0 14px;padding:14px">
                <label for="transportAuditSearch" style="display:block;font-weight:800;margin-bottom:7px">Buscar en el historial</label>
                <input id="transportAuditSearch" class="form-control" type="search" placeholder="Buscar por transportista, usuario, ID, carnet, teléfono, chapa, aprobador o acción…" autocomplete="off">
                <small style="display:block;color:var(--muted,#667085);margin-top:7px">La búsqueda revisa todos los registros cargados y sus datos.</small>
            </div>
            <p id="transportAuditStatus" class="card-description" role="status" aria-live="polite">Cargando historial…</p><div id="transportAuditCards" class="admin-requests-list"></div>
        </section>
        <section class="page" id="admin-mis-transportistas">
            <div class="section-heading"><div><h2>Mis Transportistas</h2><p>Transportistas que has aprobado o revocado. Selecciona una tarjeta para ver sus fotos, operaciones y el porcentaje vigente al confirmar cada recarga.</p></div><button class="btn" id="refreshMyTransportistas" type="button">↻ Actualizar</button></div>
            <div class="card" style="margin-bottom:16px"><strong>Porcentaje de ganancia configurado:</strong> <span id="myTransportistasPercent">—</span></div>
            <div id="myTransportistasEarningsSummary" style="margin:0 0 18px;padding:20px;border-radius:20px;color:#fff;background:linear-gradient(120deg,#142b55 0%,#2457a6 55%,#3979cf 100%);box-shadow:0 12px 28px rgba(28,73,145,.22);display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap"><div><div style="font-size:12px;letter-spacing:.09em;text-transform:uppercase;opacity:.82;font-weight:800">Ganancias acumuladas</div><div id="myTransportistasTotalEarnings" style="font-size:clamp(25px,4vw,34px);font-weight:900;margin-top:5px">$0.00</div><div style="font-size:12px;opacity:.82;margin-top:4px">Saldo disponible después de descontar los pagos recibidos</div></div><div style="text-align:right"><div style="font-size:12px;opacity:.82">Transportistas</div><strong id="myTransportistasCount" style="font-size:25px">0</strong></div></div>
            <p id="myTransportistasStatus" class="card-description" role="status">Cargando tus transportistas…</p><div id="myTransportistasCards" class="admin-requests-list"></div>
        </section>

        <section class="page" id="editor-estado-cuenta">
            <div class="section-heading"><div><h2>Estado de cuenta</h2><p>Consulta tus comisiones generadas, pagos recibidos y el saldo que queda disponible.</p></div><button class="btn" id="refreshEditorStatement" type="button">↻ Actualizar</button></div>
            <div class="stats admin-stats" style="margin-bottom:16px">
                <div class="stat-card"><div class="stat-top"><div class="stat-icon green">＋</div><span class="stat-tag">CRÉDITOS</span></div><h3 id="editorStatementCredits">$0.00</h3><p class="card-description">Comisiones generadas en el período</p></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon blue">−</div><span class="stat-tag">DÉBITOS</span></div><h3 id="editorStatementDebits">$0.00</h3><p class="card-description">Pagos recibidos en el período</p></div>
                <div class="stat-card"><div class="stat-top"><div class="stat-icon purple">$</div><span class="stat-tag">SALDO DISPONIBLE</span></div><h3 id="editorStatementBalance">$0.00</h3><p class="card-description">Saldo actual de tu cuenta</p></div>
            </div>
            <div class="card" style="margin-bottom:16px"><div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end"><div class="form-group" style="flex:1;min-width:170px;margin:0"><label for="editorStatementFrom">Desde</label><input class="form-control" type="date" id="editorStatementFrom"></div><div class="form-group" style="flex:1;min-width:170px;margin:0"><label for="editorStatementTo">Hasta</label><input class="form-control" type="date" id="editorStatementTo"></div><button class="btn btn-primary" id="filterEditorStatement" type="button">Filtrar</button><button class="btn" id="clearEditorStatementFilter" type="button">Limpiar</button></div></div>
            <p id="editorStatementStatus" class="card-description" role="status" aria-live="polite">Cargando operaciones…</p>
            <div id="editorStatementTable" class="card" style="overflow:auto"></div>
        </section>

        <!-- Solicitudes de transportista: pendientes, aprobadas y revocación -->
        <section class="page" id="admin-solicitudes">
            <div class="section-heading">
                <div>
                    <h2>Solicitudes de transportista</h2>
                    <p>Consulta las solicitudes pendientes y las autorizaciones aprobadas.</p>
                </div>
                <button class="btn" id="refreshAdminRequests" type="button">↻ Actualizar</button>
            </div>
            <section class="admin-requests-panel" aria-labelledby="adminTransportRequestsTitle">
                <div class="admin-requests-heading">
                    <div>
                        <h3 id="adminTransportRequestsTitle">Solicitudes de transportista pendientes</h3>
                        <p id="adminTransportRequestsHelp">Revisa los datos del solicitante, su cuenta y la información del vehículo antes de decidir.</p>
                    </div>
                </div>
                <div class="admin-request-tabs" role="tablist" aria-label="Estado de las solicitudes">
                    <button class="admin-request-tab active" id="adminPendingTab" type="button" role="tab" aria-selected="true" data-request-state="pendiente">Pendientes</button>
                    <button class="admin-request-tab" id="adminApprovedTab" type="button" role="tab" aria-selected="false" data-request-state="aprobada">Aprobadas</button>
                </div>
                <p class="admin-requests-status" id="adminTransportRequestsStatus" role="status" aria-live="polite">Cargando solicitudes pendientes…</p>
                <div class="admin-requests-list" id="adminTransportRequestsList" aria-live="polite"></div>
            </section>
        </section>

        <!-- ADMINISTRACIÓN: gestión de usuarios -->
        <section class="page" id="admin-usuarios">
            <div class="section-heading">
                <div><h2>Usuarios</h2><p>Consulta y modifica los datos de cualquier cuenta registrada.</p></div>
                <button class="btn" id="refreshAdminUsers" type="button">↻ Actualizar lista</button>
            </div>
            <div class="card">
                <div class="form-group"><label for="adminUserSearch">Buscar usuario</label><input id="adminUserSearch" type="search" placeholder="Usuario, nombre, apellidos o carné"></div>
                <p id="adminUsersStatus" role="status" aria-live="polite">Cargando usuarios…</p>
                <div id="adminUsersList" class="admin-requests-list" aria-live="polite"></div>
            </div>
            <div class="admin-user-editor-overlay" id="adminUserEditor" hidden role="dialog" aria-modal="true" aria-labelledby="adminUserEditorTitle">
                <section class="admin-user-editor-dialog">
                    <header class="admin-user-editor-header"><div><h3 id="adminUserEditorTitle">Modificar usuario</h3><p class="card-description">Solo puedes cambiar el tipo de cuenta y la contraseña. Los demás datos se muestran como solo lectura.</p></div><button class="admin-user-editor-close" id="adminUserEditorClose" type="button" aria-label="Cerrar editor">×</button></header>
                    <div class="admin-user-editor-identity"><img id="adminEditPhoto" class="admin-user-editor-photo" alt="Foto del usuario"><div><h4 id="adminEditDisplayName">Usuario</h4><p id="adminEditDisplayUsername">@usuario</p></div></div>
                    <form id="adminUserForm">
                        <input id="adminEditId" type="hidden">
                        <div id="adminEditReadOnly" class="admin-user-readonly"></div>
                        <div class="form-group"><label for="adminEditTipo">Tipo de cuenta</label><select id="adminEditTipo" required><option value="cliente">Cliente</option><option value="transportista">Transportista</option><option value="admin">Administrador</option></select></div>
                        <div class="form-group"><label style="display:flex;align-items:center;gap:10px"><input id="adminEditEditor" type="checkbox" style="width:18px;height:18px"> Editor</label><small>Si está habilitado, este usuario podrá acceder a Solicitudes de transportista, aprobarlas, desaprobarlas y revocar autorizaciones.</small></div>
                        <div class="form-group"><label for="adminEditPassword">Nueva contraseña (opcional)</label><input id="adminEditPassword" type="password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres"><small>Déjala vacía para conservar la contraseña actual.</small></div>
                        <button class="btn" type="submit">✓ Guardar cambios</button> <button class="btn btn-secondary" id="adminCancelEdit" type="button">Cancelar</button>
                        <p id="adminUserFormStatus" role="status" aria-live="polite"></p>
                    </form>
                </section>
            </div>
        </section>

        <!-- ADMINISTRACIÓN: ajustes generales de la aplicación -->
        <section class="page" id="admin-ajustes">
            <div class="section-heading">
                <div>
                    <h2>Ajustes de la aplicación</h2>
                    <p>Administración de la configuración general de El Radar.</p>
                </div>
            </div>
            <div class="card">
                <div class="stat-top"><div class="stat-icon purple">⚙️</div><span class="stat-tag">ADMINISTRACIÓN</span></div>
                <h3>Configuración general</h3>
                <p class="card-description">Administra los parámetros generales de El Radar desde los submenús de Administración.</p>
                <div class="admin-request-tabs app-settings-tabs" role="tablist" aria-label="Secciones de administración" hidden style="display:none!important">
                    <button type="button" class="admin-request-tab active" id="settingsTabTarifas" role="tab" aria-selected="true" aria-controls="settingsPanelTarifas" data-settings-tab="tarifas">💲 Tarifas</button>
                    <button type="button" class="admin-request-tab" id="settingsTabTransportes" role="tab" aria-selected="false" aria-controls="settingsPanelTransportes" data-settings-tab="transportes">🚘 Tipos de transporte</button>
                    <button type="button" class="admin-request-tab" id="settingsTabDatabase" role="tab" aria-selected="false" aria-controls="settingsPanelDatabase" data-settings-tab="bases-datos">🗄️ Gestión de Bases de Datos</button>
                    <button type="button" class="admin-request-tab" id="settingsTabSmtp" role="tab" aria-selected="false" aria-controls="settingsPanelSmtp" data-settings-tab="smtp">✉️ Correo SMTP</button>
                    <button type="button" class="admin-request-tab" id="settingsTabCoupons" role="tab" aria-selected="false" aria-controls="settingsPanelCoupons" data-settings-tab="cupones">🎁 Bonos y cupones</button>
                    <button type="button" class="admin-request-tab" id="settingsTabRechargeOffers" role="tab" aria-selected="false" aria-controls="settingsPanelRechargeOffers" data-settings-tab="ofertas-recarga">💸 Ofertas vía recarga</button>
                    <button type="button" class="admin-request-tab" id="settingsTabNews" role="tab" aria-selected="false" aria-controls="settingsPanelNews" data-settings-tab="novedades">📰 Novedades y promociones</button>
                </div>
                <div id="settingsPanelTarifas" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabTarifas">
                    <h3>Tarifas</h3>
                    <p class="card-description">Define el importe que se cobrará a cada tipo de cuenta por usar la aplicación durante un periodo determinado.</p>
                    <form id="adminTariffsForm">
                        <div class="admin-tariff-grid">
                            <section class="admin-tariff-card">
                                <h4>👤 Usuarios tipo Cliente</h4>
                                <div class="form-group"><label for="tariffClientAmount">Importe de la tarifa</label><input id="tariffClientAmount" type="number" min="0" step="0.01" value="0.00" required><small>Importe que corresponde al periodo seleccionado.</small></div>
                                <div class="form-group"><label for="tariffClientPeriod">Duración del periodo</label><div class="admin-tariff-period"><input id="tariffClientPeriod" type="number" min="1" max="3650" value="30" required><select id="tariffClientUnit" aria-label="Unidad del periodo para clientes"><option value="dias">Días</option><option value="semanas">Semanas</option><option value="meses">Meses</option></select></div></div>
                            </section>
                            <section class="admin-tariff-card">
                                <h4>🚚 Usuarios tipo Transportista</h4>
                                <div class="form-group"><label for="tariffTransportAmount">Importe de la tarifa</label><input id="tariffTransportAmount" type="number" min="0" step="0.01" value="0.00" required><small>Importe que corresponde al periodo seleccionado.</small></div>
                                <div class="form-group"><label for="tariffTransportPeriod">Duración del periodo</label><div class="admin-tariff-period"><input id="tariffTransportPeriod" type="number" min="1" max="3650" value="30" required><select id="tariffTransportUnit" aria-label="Unidad del periodo para transportistas"><option value="dias">Días</option><option value="semanas">Semanas</option><option value="meses">Meses</option></select></div></div>
                            </section>
                        </div>
                        <section class="admin-tariff-card" style="margin:16px 0;max-width:620px">
                            <h4>🎁 Periodo de prueba gratuito</h4>
                            <div class="form-group"><label for="tariffTrialDays">Duración del periodo de prueba (días)</label><input id="tariffTrialDays" type="number" min="0" max="3650" step="1" value="0" required><small>Durante estos días no se aplica la tarifa de uso. Usa 0 para desactivar el periodo de prueba.</small></div>
                            <div class="form-group"><label for="tariffShareReward">Importe por compartir la aplicación</label><input id="tariffShareReward" type="number" min="0" max="1000000" step="0.01" value="0.20" required><small>Saldo que recibe quien invita cuando una persona se registra correctamente con su enlace.</small></div>
                            <div class="form-group"><label for="tariffEditorPercent">Porcentaje de ganancia del Editor (%)</label><input id="tariffEditorPercent" type="number" min="0" max="100" step="0.01" value="0" required><small>Se aplica al importe de cada recarga confirmada de los transportistas que el editor haya aprobado. Solo el administrador puede modificarlo.</small></div>
                        </section>
                        <p class="card-description">Guarda las tarifas, sus periodos de cobro y los días de prueba gratuita. El periodo de prueba se inicia al crear una cuenta nueva de Cliente.</p>
                        <button class="btn" id="saveAdminTariffs" type="submit">✓ Guardar tarifas y periodo de prueba</button>
                        <p id="adminTariffsStatus" role="status" aria-live="polite">Cargando tarifas guardadas…</p>
                    </form>
                </div>
                <div id="settingsPanelTransportes" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabTransportes" hidden>
                    <h3>Tipos de transporte</h3>
                    <p class="card-description">Define los vehículos disponibles para que los usuarios tipo Transportista los seleccionen al solicitar su registro como transportistas.</p>
                    <form id="adminTransportTypeForm" class="auth-form" style="max-width:620px">
                        <input id="adminTransportTypeId" type="hidden" value="">
                        <div class="form-group"><label for="adminTransportTypeName">Nombre del tipo de transporte</label><input id="adminTransportTypeName" type="text" maxlength="60" placeholder="Ej. Motocicleta, automóvil, triciclo…" required></div>
                        <div class="form-group"><label for="adminTransportTypeOrder">Orden de visualización</label><input id="adminTransportTypeOrder" type="number" min="0" max="9999" value="0" required></div>
                        <label class="checkbox-row" style="display:flex;align-items:center;gap:8px;margin:10px 0"><input id="adminTransportTypeActive" type="checkbox" checked> Tipo activo y disponible para nuevos registros</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="saveAdminTransportType" type="submit">✓ Guardar tipo</button><button class="btn btn-secondary" id="cancelAdminTransportType" type="button">Limpiar</button></div>
                        <p id="adminTransportTypeStatus" role="status" aria-live="polite"></p>
                    </form>
                    <div style="overflow-x:auto;margin-top:18px"><table class="data-table" style="width:100%"><thead><tr><th>Tipo de transporte</th><th>Orden</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="adminTransportTypesBody"><tr><td colspan="4">Cargando tipos de transporte…</td></tr></tbody></table></div>
                </div>
                <div id="settingsPanelCoupons" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabCoupons" hidden>
                    <h3>Bonos y cupones promocionales</h3>
                    <p class="card-description">Crea códigos de oferta, define a qué tipo de usuario se dirigen, el importe del bono, la vigencia, el límite de canjes y las condiciones para utilizarlos.</p>
                    <form id="adminCouponForm" class="auth-form" style="max-width:760px">
                        <input id="adminCouponId" type="hidden" value="">
                        <div class="admin-tariff-grid">
                            <div class="form-group"><label for="adminCouponCode">Código del cupón</label><input id="adminCouponCode" type="text" maxlength="40" placeholder="Ej. RADAR20" required style="text-transform:uppercase"></div>
                            <div class="form-group"><label for="adminCouponBonus">Bono que se acredita</label><input id="adminCouponBonus" type="number" min="0.01" step="0.01" placeholder="0.20" required></div>
                            <div class="form-group"><label for="adminCouponUserType">Tipo de usuario permitido</label><select id="adminCouponUserType"><option value="todos">Todos (Cliente y Transportista)</option><option value="cliente">Solo Cliente</option><option value="transportista">Solo Transportista</option></select></div>
                            <div class="form-group"><label for="adminCouponMinBalance">Saldo mínimo requerido</label><input id="adminCouponMinBalance" type="number" min="0" step="0.01" value="0.00"><small>0 significa que no se exige saldo mínimo.</small></div>
                            <div class="form-group"><label for="adminCouponMaxUses">Máximo de canjes globales</label><input id="adminCouponMaxUses" type="number" min="0" step="1" value="0"><small>0 significa ilimitado. Cada usuario solo puede canjearlo una vez.</small></div>
                            <div class="form-group"><label for="adminCouponDescription">Descripción de la oferta</label><input id="adminCouponDescription" type="text" maxlength="180" placeholder="Ej. Bono especial de bienvenida"></div>
                            <div class="form-group"><label for="adminCouponStart">Válido desde</label><input id="adminCouponStart" type="datetime-local"></div>
                            <div class="form-group"><label for="adminCouponEnd">Válido hasta</label><input id="adminCouponEnd" type="datetime-local"></div>
                        </div>
                        <div class="form-group"><label for="adminCouponCondition">Condiciones de la oferta</label><textarea id="adminCouponCondition" rows="3" maxlength="500" placeholder="Describe las condiciones que debe cumplir el usuario. Si estableces saldo mínimo, el sistema también lo verificará automáticamente."></textarea></div>
                        <label class="checkbox-row" style="display:flex;align-items:center;gap:8px;margin:10px 0"><input id="adminCouponActive" type="checkbox" checked> Cupón activo y disponible para canjear</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="saveAdminCoupon" type="submit">✓ Guardar cupón</button><button class="btn btn-secondary" id="cancelAdminCoupon" type="button">Limpiar</button></div>
                        <p id="adminCouponStatus" role="status" aria-live="polite"></p>
                    </form>
                    <div style="overflow-x:auto;margin-top:18px"><table class="data-table" style="width:100%"><thead><tr><th>Código / Oferta</th><th>Usuarios</th><th>Bono</th><th>Canjes</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="adminCouponsBody"><tr><td colspan="7">Cargando cupones…</td></tr></tbody></table></div>
                </div>
                <div id="settingsPanelRechargeOffers" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabRechargeOffers" hidden>
                    <h3>💸 Ofertas vía recarga</h3>
                    <p class="card-description">Configura promociones automáticas: cuando el cliente confirme desde Agregar saldo una transferencia registrada y disponible, el sistema aplicará la oferta cuyo importe mínimo sea alcanzado, siempre que coincida el tipo de cuenta. Si hay varias ofertas válidas, se aplica únicamente la que otorgue el mayor bono.</p>
                    <form id="adminRechargeOfferForm" class="card" style="margin-top:14px">
                        <input id="adminRechargeOfferId" type="hidden">
                        <div class="admin-tariff-grid">
                            <div class="form-group"><label for="adminRechargeOfferAmount">Importe mínimo de recarga (CUP)</label><input id="adminRechargeOfferAmount" type="number" min="0.01" step="0.01" placeholder="Ej. 10.00" required><small>Se compara con el importe real de la transferencia registrada y confirmada; se aplica si es igual o superior.</small></div>
                            <div class="form-group"><label for="adminRechargeOfferPercent">Porcentaje de bonificación (%)</label><input id="adminRechargeOfferPercent" type="number" min="0.01" max="500" step="0.01" placeholder="Ej. 20" required></div>
                            <div class="form-group"><label for="adminRechargeOfferType">Tipo de cuenta</label><select id="adminRechargeOfferType"><option value="todos">Todos (clientes y transportistas)</option><option value="cliente">Solo clientes</option><option value="transportista">Solo transportistas</option></select></div>
                            <div class="form-group"><label for="adminRechargeOfferStart">Válida desde (opcional)</label><input id="adminRechargeOfferStart" type="datetime-local"></div>
                            <div class="form-group"><label for="adminRechargeOfferEnd">Válida hasta (opcional)</label><input id="adminRechargeOfferEnd" type="datetime-local"></div>
                        </div>
                        <label class="checkbox-row" style="display:flex;align-items:center;gap:8px;margin:10px 0"><input id="adminRechargeOfferActive" type="checkbox" checked> Oferta activa</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="saveAdminRechargeOffer" type="submit">✓ Guardar oferta</button><button class="btn btn-secondary" id="cancelAdminRechargeOffer" type="button">Limpiar</button></div>
                        <p id="adminRechargeOfferStatus" role="status" aria-live="polite"></p>
                    </form>
                    <div style="overflow-x:auto;margin-top:18px"><table class="data-table" style="width:100%"><thead><tr><th>Recarga</th><th>Bonificación</th><th>Tipo</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="adminRechargeOffersBody"><tr><td colspan="6">Cargando ofertas…</td></tr></tbody></table></div>
                </div>
                <div id="settingsPanelNews" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabNews" hidden>
                    <h3>📰 Novedades, promociones e información</h3>
                    <p class="card-description">Publica comunicaciones que aparecerán en Últimas novedades para los usuarios. Los bonos y las ofertas automáticas de recarga activas también se mostrarán allí.</p>
                    <form id="adminNewsForm" class="card" style="margin-top:14px">
                        <input id="adminNewsId" type="hidden">
                        <div class="admin-tariff-grid">
                            <div class="form-group"><label for="adminNewsTitle">Título</label><input id="adminNewsTitle" maxlength="150" required placeholder="Ej. Nueva promoción de El Radar"></div>
                            <div class="form-group"><label for="adminNewsCategory">Tipo de información</label><select id="adminNewsCategory"><option value="informacion">Información de la app</option><option value="promocion">Promoción</option><option value="aviso">Aviso importante</option><option value="mantenimiento">Mantenimiento</option><option value="bono">Bono</option><option value="recarga">Oferta de recarga</option></select></div>
                            <div class="form-group"><label for="adminNewsAudience">Dirigido a</label><select id="adminNewsAudience"><option value="todos">Todos los usuarios</option><option value="cliente">Solo clientes</option><option value="transportista">Solo transportistas</option></select></div>
                            <div class="form-group"><label for="adminNewsStart">Mostrar desde (opcional)</label><input id="adminNewsStart" type="datetime-local"></div>
                            <div class="form-group"><label for="adminNewsEnd">Mostrar hasta (opcional)</label><input id="adminNewsEnd" type="datetime-local"></div>
                        </div>
                        <div class="form-group"><label for="adminNewsContent">Contenido</label><textarea id="adminNewsContent" rows="5" maxlength="5000" required placeholder="Escribe los detalles de la promoción o información…"></textarea></div>
                        <div class="form-group"><label for="adminNewsImage">Imagen de la novedad (opcional)</label><input id="adminNewsImage" type="file" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG o WEBP. La imagen se optimiza antes de guardarse.</small><div id="adminNewsImagePreview" style="margin-top:10px"></div><label class="checkbox-row" style="display:flex;align-items:center;gap:8px;margin-top:8px"><input id="adminNewsRemoveImage" type="checkbox"> Quitar imagen de esta publicación</label></div>
                        <label class="checkbox-row" style="display:flex;align-items:center;gap:8px;margin:10px 0"><input id="adminNewsActive" type="checkbox" checked> Publicación activa</label>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" id="saveAdminNews" type="submit">✓ Publicar novedad</button><button class="btn btn-secondary" id="cancelAdminNews" type="button">Limpiar</button></div>
                        <p id="adminNewsStatus" role="status" aria-live="polite"></p>
                    </form>
                    <div style="overflow-x:auto;margin-top:18px"><table class="data-table" style="width:100%"><thead><tr><th>Título</th><th>Categoría</th><th>Público</th><th>Vigencia</th><th>Estado</th><th>Acciones</th></tr></thead><tbody id="adminNewsBody"><tr><td colspan="6">Cargando publicaciones…</td></tr></tbody></table></div>
                </div>
                <div id="settingsPanelSmtp" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabSmtp" hidden>
                    <h3>Correo electrónico y servidor SMTP</h3>
                    <p class="card-description">Configura el correo que El Radar utilizará para enviar enlaces de recuperación de contraseña.</p>
                    <form id="adminSmtpForm" class="auth-form" style="max-width:680px">
                        <div class="admin-tariff-grid">
                            <div class="form-group"><label for="adminSmtpHost">Servidor SMTP</label><input id="adminSmtpHost" maxlength="255" placeholder="smtp.tudominio.com" required></div>
                            <div class="form-group"><label for="adminSmtpPort">Puerto</label><input id="adminSmtpPort" type="number" min="1" max="65535" value="587" required></div>
                            <div class="form-group"><label for="adminSmtpEncryption">Cifrado</label><select id="adminSmtpEncryption"><option value="tls">STARTTLS (recomendado, 587)</option><option value="ssl">SSL/TLS (465)</option><option value="none">Sin cifrado</option></select></div>
                            <div class="form-group"><label for="adminSmtpUser">Usuario SMTP</label><input id="adminSmtpUser" autocomplete="username" placeholder="correo@tudominio.com"></div>
                            <div class="form-group"><label for="adminSmtpPassword">Contraseña SMTP</label><input id="adminSmtpPassword" type="password" autocomplete="new-password" placeholder="Dejar vacío para conservar la guardada"><small>La contraseña se guarda en un archivo privado del servidor y nunca se muestra.</small></div>
                            <div class="form-group"><label for="adminSmtpEmail">Correo remitente</label><input id="adminSmtpEmail" type="email" maxlength="190" placeholder="no-reply@tudominio.com" required></div>
                            <div class="form-group"><label for="adminSmtpName">Nombre del remitente</label><input id="adminSmtpName" maxlength="100" value="El Radar" required></div>
                        </div>
                        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn" type="submit" id="saveAdminSmtp">✓ Guardar SMTP</button><button class="btn btn-secondary" type="button" id="testAdminSmtp">Enviar correo de prueba</button></div>
                        <p id="adminSmtpStatus" role="status" aria-live="polite"></p>
                    </form>
                </div>
                <div id="settingsPanelDatabase" class="app-settings-panel" role="tabpanel" aria-labelledby="settingsTabDatabase" hidden>
                    <h3>Gestión de Bases de Datos</h3>
                    <p class="card-description">Configura la conexión MySQL y administra los respaldos de El Radar.</p>
            <div class="card" style="margin-bottom:16px">
                <h3>Conexión al servidor MySQL</h3>
                <p class="card-description">Introduce los datos de conexión. La contraseña se guarda en un archivo PHP protegido y nunca se vuelve a mostrar. Deja la contraseña vacía para conservar la actual.</p>
                <form id="adminDbConnectionForm" autocomplete="off">
                    <div class="admin-tariff-grid">
                        <div class="form-group"><label for="adminDbHost">Servidor / Host</label><input id="adminDbHost" name="host" type="text" required maxlength="255" placeholder="localhost"></div>
                        <div class="form-group"><label for="adminDbPort">Puerto</label><input id="adminDbPort" name="port" type="number" min="1" max="65535" value="3306" required></div>
                        <div class="form-group"><label for="adminDbName">Nombre de la base de datos</label><input id="adminDbName" name="database" type="text" required maxlength="128"></div>
                        <div class="form-group"><label for="adminDbUser">Usuario</label><input id="adminDbUser" name="user" type="text" required maxlength="128" autocomplete="username"></div>
                        <div class="form-group"><label for="adminDbPassword">Contraseña</label><input id="adminDbPassword" name="password" type="password" maxlength="255" autocomplete="new-password" placeholder="Dejar vacío para conservar la actual"></div>
                    </div>
                    <button class="btn" id="adminDbConnectionSave" type="submit">Probar y guardar conexión</button>
                    <p id="adminDbConnectionStatus" role="status" aria-live="polite"></p>
                </form>
            </div>
            <div class="card" style="margin-bottom:16px">
                <h3>📦 Exportar base de datos</h3>
                <p class="card-description">Descarga un respaldo completo en formato JSON con los registros de todas las tablas. Guarda el archivo en un lugar seguro.</p>
                <button class="btn" id="adminDbExport" type="button">⬇ Exportar todas las tablas</button>
                <p id="adminDbExportStatus" role="status" aria-live="polite"></p>
            </div>
            <div class="card" style="margin-bottom:16px">
                <h3>📥 Importar base de datos</h3>
                <p class="card-description">Restaura un respaldo JSON creado desde esta misma sección y compatible con la estructura actual de El Radar. La importación reemplaza los datos actuales.</p>
                <div class="form-group"><label for="adminDbImportFile">Archivo de respaldo (.json)</label><input id="adminDbImportFile" type="file" accept=".json,application/json"></div>
                <button class="btn" id="adminDbImport" type="button">↥ Importar respaldo</button>
                <p id="adminDbImportStatus" role="status" aria-live="polite"></p>
            </div>
            <div class="card" style="border:1px solid #d97757">
                <h3>⚠️ Vaciar todas las tablas</h3>
                <p class="card-description">Elimina los registros de todas las tablas. Para evitar bloquear el acceso, se conservará únicamente tu cuenta administradora actual. Esta acción no se puede deshacer; exporta un respaldo antes de continuar.</p>
                <label for="adminDbClearConfirm">Escribe VACIAR BASE DE DATOS para confirmar</label>
                <input id="adminDbClearConfirm" type="text" autocomplete="off" placeholder="VACIAR BASE DE DATOS">
                <button class="btn" id="adminDbClear" type="button" style="background:#b42318">🗑 Vaciar todas las tablas</button>
                <p id="adminDbClearStatus" role="status" aria-live="polite"></p>
            </div>
                </div>
            </div>
        </section>

        <!-- SOBRE LA APP -->
        <section class="page" id="sobre">
            <div class="section-heading">
                <div>
                    <h2>Sobre la aplicación</h2>
                    <p>Conoce la plataforma El Radar</p>
                </div>
            </div>

            <div class="about-hero">
                <img class="brand-logo" src="./icon-512.png" alt="El Radar" width="512" height="512">
                <div class="eyebrow">MOVILIDAD INTELIGENTE</div>
                <h2>El Radar</h2>
                <p>
                    Una plataforma pensada para reunir las herramientas de
                    gestión de transporte en una experiencia clara, moderna
                    y fácil de utilizar.
                </p>
                <p>Versión 1.0 · Interfaz adaptable para móviles y escritorio.</p>
            </div>
        </section>

    </div>
</main>

<div class="notifications-overlay" id="notificationsOverlay" hidden role="dialog" aria-modal="true" aria-labelledby="notificationsTitle">
  <div class="notifications-backdrop" id="notificationsBackdrop"></div>
  <section class="notifications-dialog" role="document">
    <header class="notifications-header">
      <div><h2 id="notificationsTitle">🔔 Notificaciones</h2><p>Avisos de tu cuenta mientras El Radar está abierto.</p></div>
      <div class="notifications-header-actions">
        <button class="notifications-mark-all" id="markAllNotificationsRead" type="button">Marcar todas como leídas</button>
        <button class="notifications-close" id="notificationsClose" type="button" aria-label="Cerrar notificaciones">×</button>
      </div>
    </header>
    <div class="notifications-summary" id="notificationsSummary">Consultando notificaciones…</div>
    <div class="notifications-list" id="notificationsList" aria-live="polite"><div class="notifications-empty">Cargando…</div></div>
  </section>
</div>


<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>

<script>
const pageInfo = {
    radar: {
        title: "El Radar",
        subtitle: "Tu centro de movilidad"
    },
    recargar: {
        title: "Recargar cuenta",
        subtitle: "Gestiona tu saldo"
    },
    password: {
        title: "Cambiar contraseña",
        subtitle: "Seguridad y acceso"
    },
    "solicitar-transportista": {
        title: "Solicitar Cuenta transportista",
        subtitle: "Solicitud de cambio de tipo de cuenta"
    },
    administracion: {
        title: "Administración",
        subtitle: "Gestión general de El Radar"
    },
    "admin-finanzas": {
        title: "Finanzas",
        subtitle: "Ingresos de la app y transferencias"
    },
    "admin-pagos-editores": { title: "Pagos a Editores", subtitle: "Ganancias acumuladas y registro de pagos" },
    "admin-usuarios": {
        title: "Usuarios",
        subtitle: "Consultar y modificar cuentas de usuario"
    },
    "admin-transport-historial": { title: "Historial de transportistas", subtitle: "Auditoría de aprobaciones y revocaciones" },
    "admin-mis-transportistas": { title: "Mis Transportistas", subtitle: "Transportistas gestionados y ganancias del editor" },
    "editor-estado-cuenta": { title: "Estado de cuenta", subtitle: "Historial de comisiones, pagos y saldo disponible" },
    "admin-solicitudes": {
        title: "Solicitudes de transportista",
        subtitle: "Solicitudes pendientes, aprobadas y revocación de autorizaciones"
    },
    "admin-ajustes": {
        title: "Administración",
        subtitle: "Configuración y gestión de El Radar"
    },
    sobre: {
        title: "Sobre la app",
        subtitle: "Conoce El Radar"
    }
};

const sidebar = document.getElementById("sidebar");
const overlay = document.getElementById("overlay");
const settingsToggle = document.getElementById("settingsToggle");
const settingsSubmenu = document.getElementById("settingsSubmenu");
const adminNavLink = document.getElementById("adminNavLink");
// Carga y guarda tarifas de Cliente/Transportista y el periodo de prueba gratuito.
async function loadAdminTariffs() {
    const form = document.getElementById("adminTariffsForm");
    const status = document.getElementById("adminTariffsStatus");
    if (!form || !status) return;
    status.textContent = "Cargando tarifas guardadas…";
    try {
        const result = await apiRequest("admin_tariffs_get", {});
        const t = result.tarifas || {};
        document.getElementById("tariffClientAmount").value = Number(t.CLIENTE_IMPORTE || 0).toFixed(2);
        document.getElementById("tariffClientPeriod").value = Number(t.CLIENTE_PERIODO_CANTIDAD || 30);
        document.getElementById("tariffClientUnit").value = t.CLIENTE_PERIODO_UNIDAD || "dias";
        document.getElementById("tariffTransportAmount").value = Number(t.TRANSPORTISTA_IMPORTE || 0).toFixed(2);
        document.getElementById("tariffTransportPeriod").value = Number(t.TRANSPORTISTA_PERIODO_CANTIDAD || 30);
        document.getElementById("tariffTransportUnit").value = t.TRANSPORTISTA_PERIODO_UNIDAD || "dias";
        document.getElementById("tariffTrialDays").value = Number(t.PRUEBA_DIAS || 0);
        document.getElementById("tariffShareReward").value = Number(t.RECOMPENSA_COMPARTIR ?? 0.20).toFixed(2);
        document.getElementById("tariffEditorPercent").value = Number(t.PORCENTAJE_EDITOR || 0).toFixed(2);
        status.textContent = "Tarifas y periodo de prueba cargados. Última actualización: " + (t.ACTUALIZADO_EN || "sin registro");
    } catch (error) { status.textContent = error.message || "No se pudieron cargar las tarifas."; }
}
// Exportación, restauración y limpieza de las tablas (solo administrador; autorización también en PHP).
async function loadAdminDbConnectionInfo() {
    const status = document.getElementById("adminDbConnectionStatus");
    try {
        const result = await apiRequest("admin_db_connection_info", {});
        const c = result.connection || {};
        document.getElementById("adminDbHost").value = c.host || "";
        document.getElementById("adminDbPort").value = c.port || "3306";
        document.getElementById("adminDbName").value = c.database || "";
        document.getElementById("adminDbUser").value = c.user || "";
        document.getElementById("adminDbPassword").value = "";
        if (status) status.textContent = "Conexión actual cargada. La contraseña no se muestra por seguridad.";
    } catch (error) {
        if (status) status.textContent = error.message || "No se pudo cargar la configuración.";
    }
}
document.getElementById("adminDbConnectionForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const button = document.getElementById("adminDbConnectionSave");
    const status = document.getElementById("adminDbConnectionStatus");
    const payload = {
        host: document.getElementById("adminDbHost").value.trim(),
        port: document.getElementById("adminDbPort").value.trim(),
        database: document.getElementById("adminDbName").value.trim(),
        user: document.getElementById("adminDbUser").value.trim(),
        password: document.getElementById("adminDbPassword").value
    };
    if (button) button.disabled = true;
    if (status) status.textContent = "Probando conexión con el servidor…";
    try {
        const result = await apiRequest("admin_db_connection_save", payload);
        if (status) status.textContent = result.message || "Conexión guardada.";
        document.getElementById("adminDbPassword").value = "";
        showToast(result.message || "Conexión guardada.");
    } catch (error) {
        if (status) status.textContent = error.message || "No se pudo guardar la conexión.";
        showToast(error.message || "No se pudo guardar la conexión.");
    } finally {
        if (button) button.disabled = false;
    }
});
document.getElementById("adminDbExport")?.addEventListener("click", async () => {
    const status = document.getElementById("adminDbExportStatus");
    const button = document.getElementById("adminDbExport");
    button.disabled = true; status.textContent = "Preparando respaldo de todas las tablas…";
    try {
        const result = await apiRequest("admin_db_export", {});
        const blob = new Blob([JSON.stringify(result.backup, null, 2)], {type:"application/json;charset=utf-8"});
        const url = URL.createObjectURL(blob); const a = document.createElement("a");
        a.href = url; a.download = result.filename || "el-radar-respaldo.json"; document.body.appendChild(a); a.click(); a.remove(); URL.revokeObjectURL(url);
        status.textContent = "Respaldo descargado. Tablas incluidas: " + Number(result.table_count || 0) + ".";
    } catch (error) { status.textContent = error.message || "No se pudo exportar la base de datos."; }
    finally { button.disabled = false; }
});
document.getElementById("adminDbImport")?.addEventListener("click", async () => {
    const input = document.getElementById("adminDbImportFile"); const status = document.getElementById("adminDbImportStatus"); const button = document.getElementById("adminDbImport");
    const file = input?.files?.[0];
    if (!file) { status.textContent = "Selecciona primero un archivo de respaldo JSON."; return; }
    if (file.size > 50 * 1024 * 1024) { status.textContent = "El archivo supera el límite de 50 MB."; return; }
    if (!await radarConfirm("La importación reemplazará los datos actuales de las tablas por los del respaldo. Se recomienda exportar primero una copia de seguridad. ¿Deseas continuar?", {title:"Confirmar importación", confirmText:"Importar y reemplazar", danger:true})) return;
    button.disabled = true; status.textContent = "Leyendo y validando el respaldo…";
    try {
        const backup = JSON.parse(await file.text());
        if (!backup || backup.format !== "el-radar-db-backup" || Number(backup.version) !== 1 || !backup.tables || typeof backup.tables !== "object") throw new Error("El archivo seleccionado no es un respaldo válido de El Radar.");
        status.textContent = "Restaurando los datos…";
        const result = await apiRequest("admin_db_import", {backup});
        status.textContent = result.message || "Respaldo importado correctamente.";
        showToast(result.message || "Base de datos restaurada.");
        input.value = "";
    } catch (error) { status.textContent = error.message || "No se pudo importar el respaldo."; }
    finally { button.disabled = false; }
});
document.getElementById("adminDbClear")?.addEventListener("click", async () => {
    const confirmInput = document.getElementById("adminDbClearConfirm"); const status = document.getElementById("adminDbClearStatus"); const button = document.getElementById("adminDbClear");
    if ((confirmInput?.value || "").trim() !== "VACIAR BASE DE DATOS") { status.textContent = "La frase de confirmación no coincide."; return; }
    if (!await radarConfirm("Se eliminarán los registros de todas las tablas. Solo se conservará tu cuenta administradora actual para que puedas seguir entrando. Esta operación es irreversible. ¿Continuar?", {title:"Vaciar base de datos", confirmText:"Sí, vaciar tablas", danger:true})) return;
    button.disabled = true; status.textContent = "Vaciando tablas…";
    try {
        const result = await apiRequest("admin_db_clear", {confirm:confirmInput.value.trim()});
        status.textContent = result.message || "Tablas vaciadas."; confirmInput.value = ""; showToast(result.message || "Base de datos vaciada.");
    } catch (error) { status.textContent = error.message || "No se pudieron vaciar las tablas."; }
    finally { button.disabled = false; }
});

document.getElementById("adminTariffsForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const status = document.getElementById("adminTariffsStatus");
    const button = document.getElementById("saveAdminTariffs");
    status.textContent = "Guardando configuración…";
    if (button) button.disabled = true;
    try {
        const result = await apiRequest("admin_tariffs_save", {
            cliente_importe: document.getElementById("tariffClientAmount").value,
            cliente_periodo_cantidad: document.getElementById("tariffClientPeriod").value,
            cliente_periodo_unidad: document.getElementById("tariffClientUnit").value,
            transportista_importe: document.getElementById("tariffTransportAmount").value,
            transportista_periodo_cantidad: document.getElementById("tariffTransportPeriod").value,
            transportista_periodo_unidad: document.getElementById("tariffTransportUnit").value,
            prueba_dias: document.getElementById("tariffTrialDays").value,
            recompensa_compartir: document.getElementById("tariffShareReward").value,
            porcentaje_editor: document.getElementById("tariffEditorPercent").value
        });
        status.textContent = result.message || "Configuración guardada correctamente.";
    } catch (error) { status.textContent = error.message || "No se pudo guardar la configuración."; }
    finally { if (button) button.disabled = false; }
});

// Administración de bonos y cupones promocionales.
function resetAdminCouponForm() {
    const form = document.getElementById('adminCouponForm'); if (!form) return;
    form.reset(); document.getElementById('adminCouponId').value='';
    document.getElementById('adminCouponBonus').value=''; document.getElementById('adminCouponMinBalance').value='0.00';
    document.getElementById('adminCouponMaxUses').value='0'; document.getElementById('adminCouponActive').checked=true;
    document.getElementById('saveAdminCoupon').textContent='✓ Guardar cupón';
}
async function loadAdminCoupons() {
    const body=document.getElementById('adminCouponsBody'); if (!body) return;
    body.innerHTML='<tr><td colspan="7">Cargando cupones…</td></tr>';
    try {
        const result=await apiRequest('admin_coupons_list',{}); const coupons=Array.isArray(result.cupones)?result.cupones:[];
        body.innerHTML='';
        if (!coupons.length) { body.innerHTML='<tr><td colspan="7">Todavía no hay cupones creados.</td></tr>'; return; }
        coupons.forEach(c=>{
            const tr=document.createElement('tr');
            const cell=(value)=>{const td=document.createElement('td');td.textContent=value;return td;};
            tr.append(cell(c.CODIGO+' · '+(c.DESCRIPCION||'Sin descripción')),cell(c.TIPO_USUARIO==='todos'?'Todos':(c.TIPO_USUARIO==='cliente'?'Cliente':'Transportista')),cell(new Intl.NumberFormat('es-ES',{style:'currency',currency:'CUP'}).format(Number(c.BONO_IMPORTE||0))),cell(String(c.USOS)+' / '+(Number(c.MAX_USOS)>0?c.MAX_USOS:'∞')),cell((c.FECHA_INICIO||'Sin inicio')+' → '+(c.FECHA_FIN||'Sin vencimiento')),cell(Number(c.ACTIVO)===1?'Activo':'Inactivo'));
            const actions=cell('');
            const edit=document.createElement('button'); edit.type='button'; edit.className='btn btn-secondary'; edit.textContent='Editar'; edit.style.marginRight='6px';
            edit.addEventListener('click',()=>{
                document.getElementById('adminCouponId').value=c.ID; document.getElementById('adminCouponCode').value=c.CODIGO;
                document.getElementById('adminCouponBonus').value=Number(c.BONO_IMPORTE).toFixed(2); document.getElementById('adminCouponUserType').value=c.TIPO_USUARIO;
                document.getElementById('adminCouponMinBalance').value=Number(c.SALDO_MINIMO||0).toFixed(2); document.getElementById('adminCouponMaxUses').value=c.MAX_USOS;
                document.getElementById('adminCouponDescription').value=c.DESCRIPCION||''; document.getElementById('adminCouponCondition').value=c.CONDICION_TEXTO||'';
                document.getElementById('adminCouponStart').value=c.FECHA_INICIO?String(c.FECHA_INICIO).replace(' ','T').slice(0,16):'';
                document.getElementById('adminCouponEnd').value=c.FECHA_FIN?String(c.FECHA_FIN).replace(' ','T').slice(0,16):'';
                document.getElementById('adminCouponActive').checked=Number(c.ACTIVO)===1; document.getElementById('saveAdminCoupon').textContent='✓ Actualizar cupón';
                document.getElementById('adminCouponCode').focus();
            });
            const broadcast=document.createElement('button'); broadcast.type='button'; broadcast.className='btn btn-primary'; broadcast.textContent='📣 Difundir'; broadcast.style.marginRight='6px';
            broadcast.addEventListener('click',async()=>{
                const audience=c.TIPO_USUARIO==='cliente'?'clientes y administradores':(c.TIPO_USUARIO==='transportista'?'transportistas y administradores':'clientes, transportistas y administradores');
                const minBalance=new Intl.NumberFormat('es-MX',{style:'currency',currency:'CUP'}).format(Number(c.SALDO_MINIMO||0));
                const detail='Se enviará un aviso de '+c.CODIGO+' a '+audience+', pero los clientes/transportistas solo lo recibirán si su saldo actual es igual o superior a '+minBalance+'. Los administradores recibirán una copia informativa. ¿Deseas continuar?';
                if(!await radarConfirm(detail,{title:'Difundir bono promocional',confirmText:'Sí, difundir',danger:false})) return;
                broadcast.disabled=true; broadcast.textContent='Enviando…';
                try {
                    const r=await apiRequest('admin_coupons_broadcast',{id:c.ID});
                    const st=document.getElementById('adminCouponStatus');
                    if(st) st.textContent=r.message||'Bono difundido correctamente.';
                    showToast(r.message||'Bono difundido correctamente.');
                } catch(e) {
                    const st=document.getElementById('adminCouponStatus');
                    if(st) st.textContent=e.message||'No se pudo difundir el bono.';
                } finally { broadcast.disabled=false; broadcast.textContent='📣 Difundir'; }
            });
            const del=document.createElement('button'); del.type='button'; del.className='btn btn-danger'; del.textContent='Eliminar / desactivar';
            del.addEventListener('click',async()=>{if(!await radarConfirm('¿Eliminar o desactivar el cupón '+c.CODIGO+'?',{title:'Gestionar cupón',confirmText:'Continuar',danger:true}))return;try{const r=await apiRequest('admin_coupons_delete',{id:c.ID});document.getElementById('adminCouponStatus').textContent=r.message||'Cupón actualizado.';await loadAdminCoupons();}catch(e){document.getElementById('adminCouponStatus').textContent=e.message||'No se pudo modificar el cupón.';}});
            actions.append(broadcast,edit,del); tr.append(actions); body.appendChild(tr);
        });
    } catch(e) { body.innerHTML='<tr><td colspan="7">No se pudieron cargar los cupones.</td></tr>'; const st=document.getElementById('adminCouponStatus'); if(st)st.textContent=e.message||'Error al cargar cupones.'; }
}
document.getElementById('adminCouponForm')?.addEventListener('submit',async event=>{
    event.preventDefault(); const status=document.getElementById('adminCouponStatus'); const button=document.getElementById('saveAdminCoupon'); button.disabled=true; status.textContent='Guardando cupón…';
    try {
        const r=await apiRequest('admin_coupons_save',{id:document.getElementById('adminCouponId').value||0,codigo:document.getElementById('adminCouponCode').value.trim().toUpperCase(),bono_importe:document.getElementById('adminCouponBonus').value,tipo_usuario:document.getElementById('adminCouponUserType').value,saldo_minimo:document.getElementById('adminCouponMinBalance').value,max_usos:document.getElementById('adminCouponMaxUses').value,descripcion:document.getElementById('adminCouponDescription').value.trim(),condicion_texto:document.getElementById('adminCouponCondition').value.trim(),fecha_inicio:document.getElementById('adminCouponStart').value,fecha_fin:document.getElementById('adminCouponEnd').value,activo:document.getElementById('adminCouponActive').checked?1:0});
        status.textContent=r.message||'Cupón guardado.'; resetAdminCouponForm(); await loadAdminCoupons(); await loadLatestNews();
    } catch(e) { status.textContent=e.message||'No se pudo guardar el cupón.'; } finally { button.disabled=false; }
});
document.getElementById('cancelAdminCoupon')?.addEventListener('click',resetAdminCouponForm);
document.getElementById('couponRedeemForm')?.addEventListener('submit',async event=>{
    event.preventDefault(); const button=document.getElementById('couponRedeemButton'); const status=document.getElementById('couponRedeemStatus'); button.disabled=true; status.textContent='Verificando cupón…';
    try { const r=await apiRequest('coupon_redeem',{codigo:document.getElementById('couponRedeemCode').value.trim().toUpperCase()}); status.textContent=r.message||'Cupón canjeado.'; document.getElementById('couponRedeemCode').value=''; if(currentRadarUser)currentRadarUser.saldo=Number(r.saldo_actual||currentRadarUser.saldo||0); const balance=document.getElementById('operationsCurrentBalance'); if(balance)balance.textContent=new Intl.NumberFormat('es-ES',{style:'currency',currency:'CUP'}).format(Number(r.saldo_actual||0)); showToast(r.message||'Bono acreditado.'); await loadBalanceOperations(); }
    catch(e) { status.textContent=e.message||'No se pudo canjear el cupón.'; } finally { button.disabled=false; }
});

// Gestión de ofertas automáticas por recarga.
function resetAdminRechargeOfferForm(){
    const f=document.getElementById('adminRechargeOfferForm'); if(!f)return; f.reset();
    document.getElementById('adminRechargeOfferId').value='';
    document.getElementById('adminRechargeOfferActive').checked=true;
    document.getElementById('saveAdminRechargeOffer').textContent='✓ Guardar oferta';
}
async function loadAdminRechargeOffers(){
    const body=document.getElementById('adminRechargeOffersBody'); if(!body)return;
    try{
        const r=await apiRequest('admin_recharge_offers_list',{}); const rows=Array.isArray(r.ofertas)?r.ofertas:[]; body.innerHTML='';
        if(!rows.length){body.innerHTML='<tr><td colspan="6">Todavía no hay ofertas configuradas.</td></tr>';return;}
        const money=v=>new Intl.NumberFormat('es-MX',{style:'currency',currency:'CUP'}).format(Number(v||0));
        rows.forEach(o=>{
            const tr=document.createElement('tr');
            const type=o.TIPO_USUARIO==='cliente'?'Clientes':(o.TIPO_USUARIO==='transportista'?'Transportistas':'Todos');
            const dates=(o.FECHA_INICIO?String(o.FECHA_INICIO):'Desde ahora')+' — '+(o.FECHA_FIN?String(o.FECHA_FIN):'Sin vencimiento');
            [money(o.IMPORTE_RECARGA),Number(o.PORCENTAJE).toLocaleString('es-MX')+'%',type,dates,Number(o.ACTIVO)===1?'Activa':'Inactiva'].forEach(v=>{const td=document.createElement('td');td.textContent=v;tr.appendChild(td);});
            const td=document.createElement('td');
            const edit=document.createElement('button');edit.type='button';edit.className='btn btn-secondary';edit.textContent='Editar';edit.addEventListener('click',()=>{
                document.getElementById('adminRechargeOfferId').value=o.ID;
                document.getElementById('adminRechargeOfferAmount').value=Number(o.IMPORTE_RECARGA).toFixed(2);
                document.getElementById('adminRechargeOfferPercent').value=Number(o.PORCENTAJE).toFixed(2);
                document.getElementById('adminRechargeOfferType').value=o.TIPO_USUARIO;
                document.getElementById('adminRechargeOfferStart').value=o.FECHA_INICIO?String(o.FECHA_INICIO).replace(' ','T').slice(0,16):'';
                document.getElementById('adminRechargeOfferEnd').value=o.FECHA_FIN?String(o.FECHA_FIN).replace(' ','T').slice(0,16):'';
                document.getElementById('adminRechargeOfferActive').checked=Number(o.ACTIVO)===1;
                document.getElementById('saveAdminRechargeOffer').textContent='✓ Actualizar oferta';
                document.getElementById('adminRechargeOfferAmount').focus();
            });
            const del=document.createElement('button');del.type='button';del.className='btn btn-danger';del.style.marginLeft='6px';del.textContent='Eliminar';del.addEventListener('click',async()=>{
                if(!await radarConfirm('¿Eliminar esta oferta de recarga?',{title:'Eliminar oferta',confirmText:'Sí, eliminar',danger:true}))return;
                try{const x=await apiRequest('admin_recharge_offers_delete',{id:o.ID});document.getElementById('adminRechargeOfferStatus').textContent=x.message||'Oferta eliminada.';await loadAdminRechargeOffers();}catch(e){document.getElementById('adminRechargeOfferStatus').textContent=e.message||'No se pudo eliminar.';}
            });
            td.append(edit,del);tr.appendChild(td);body.appendChild(tr);
        });
    }catch(e){body.innerHTML='<tr><td colspan="6">No se pudieron cargar las ofertas.</td></tr>';const st=document.getElementById('adminRechargeOfferStatus');if(st)st.textContent=e.message||'Error al cargar ofertas.';}
}
document.getElementById('adminRechargeOfferForm')?.addEventListener('submit',async event=>{
    event.preventDefault();const b=document.getElementById('saveAdminRechargeOffer'),st=document.getElementById('adminRechargeOfferStatus');b.disabled=true;st.textContent='Guardando oferta…';
    try{const r=await apiRequest('admin_recharge_offers_save',{id:document.getElementById('adminRechargeOfferId').value||0,importe_recarga:document.getElementById('adminRechargeOfferAmount').value,porcentaje:document.getElementById('adminRechargeOfferPercent').value,tipo_usuario:document.getElementById('adminRechargeOfferType').value,fecha_inicio:document.getElementById('adminRechargeOfferStart').value,fecha_fin:document.getElementById('adminRechargeOfferEnd').value,activo:document.getElementById('adminRechargeOfferActive').checked?1:0});st.textContent=r.message||'Oferta guardada.';resetAdminRechargeOfferForm();await loadAdminRechargeOffers();await loadLatestNews();}
    catch(e){st.textContent=e.message||'No se pudo guardar la oferta.';}finally{b.disabled=false;}
});
document.getElementById('cancelAdminRechargeOffer')?.addEventListener('click',resetAdminRechargeOfferForm);

// Novedades visibles en el panel principal y editor de publicaciones para Administración.
function escapeNewsText(value){return String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));}
async function loadLatestNews(){
    const box=document.getElementById('latestNewsList');if(!box)return;
    try{
        const r=await apiRequest('latest_news_list',{});const items=Array.isArray(r.novedades)?r.novedades:[];box.innerHTML='';
        if(!items.length){box.innerHTML='<div class="activity"><div class="activity-icon">✨</div><div><strong>Bienvenido a El Radar</strong><p>Cuando haya bonos, ofertas o nuevas informaciones, aparecerán aquí.</p></div></div>';return;}
        const icons={bono:'🎁',recarga:'💸',promocion:'🎉',aviso:'📢',mantenimiento:'🛠️',informacion:'ℹ️'};
        items.forEach(item=>{const article=document.createElement('article');article.className='activity';article.style.alignItems='flex-start';const icon=document.createElement('div');icon.className='activity-icon';icon.textContent=icons[item.CATEGORIA]||'📰';const body=document.createElement('div');body.style.minWidth='0';body.style.flex='1';const title=document.createElement('strong');title.textContent=item.TITULO||'Novedad';const p=document.createElement('p');p.textContent=item.CONTENIDO||'';body.append(title,p);if(item.IMAGEN&&/^data:image\/(jpeg|png|webp);base64,/.test(item.IMAGEN)){const img=document.createElement('img');img.src=item.IMAGEN;img.alt=item.TITULO||'Imagen de la novedad';img.loading='lazy';img.style.cssText='display:block;width:100%;max-width:520px;max-height:300px;object-fit:cover;border-radius:12px;margin:10px 0';body.append(img);}if(item.CREADO_EN){const small=document.createElement('small');small.textContent=new Date(String(item.CREADO_EN).replace(' ','T')).toLocaleString('es-ES');body.append(small);}article.append(icon,body);box.append(article);});
    }catch(e){box.innerHTML='<div class="activity"><div class="activity-icon">⚠️</div><div><strong>No se pudieron cargar las novedades</strong><p>'+escapeNewsText(e.message||'Inténtalo nuevamente.')+'</p></div></div>';}
}
function resetAdminNewsForm(){const f=document.getElementById('adminNewsForm');if(!f)return;f.reset();document.getElementById('adminNewsId').value='';document.getElementById('adminNewsActive').checked=true;document.getElementById('adminNewsImagePreview').innerHTML='';document.getElementById('adminNewsRemoveImage').checked=false;document.getElementById('saveAdminNews').textContent='✓ Publicar novedad';}
async function loadAdminNews(){
 const body=document.getElementById('adminNewsBody');if(!body)return;
 try{const r=await apiRequest('admin_news_list',{});const rows=Array.isArray(r.novedades)?r.novedades:[];body.innerHTML='';if(!rows.length){body.innerHTML='<tr><td colspan="6">Todavía no hay publicaciones.</td></tr>';return;}
 rows.forEach(n=>{const tr=document.createElement('tr');const audience=n.TIPO_USUARIO==='cliente'?'Clientes':(n.TIPO_USUARIO==='transportista'?'Transportistas':'Todos');const dates=(n.FECHA_INICIO||'Desde ahora')+' — '+(n.FECHA_FIN||'Sin vencimiento');[n.TITULO,n.CATEGORIA,audience,dates,Number(n.ACTIVO)===1?'Activa':'Inactiva'].forEach(v=>{const td=document.createElement('td');td.textContent=v||'';tr.append(td);});const td=document.createElement('td');const edit=document.createElement('button');edit.type='button';edit.className='btn btn-secondary';edit.textContent='Editar';edit.addEventListener('click',()=>{document.getElementById('adminNewsId').value=n.ID;document.getElementById('adminNewsTitle').value=n.TITULO;document.getElementById('adminNewsCategory').value=n.CATEGORIA;document.getElementById('adminNewsAudience').value=n.TIPO_USUARIO;document.getElementById('adminNewsContent').value=n.CONTENIDO;document.getElementById('adminNewsImage').value='';document.getElementById('adminNewsRemoveImage').checked=false;const preview=document.getElementById('adminNewsImagePreview');preview.innerHTML=n.IMAGEN?'<img src=\"'+n.IMAGEN+'\" alt=\"Imagen actual\" style=\"max-width:220px;max-height:130px;border-radius:10px;object-fit:cover\">':'<small>Esta publicación no tiene imagen.</small>';document.getElementById('adminNewsStart').value=n.FECHA_INICIO?String(n.FECHA_INICIO).replace(' ','T').slice(0,16):'';document.getElementById('adminNewsEnd').value=n.FECHA_FIN?String(n.FECHA_FIN).replace(' ','T').slice(0,16):'';document.getElementById('adminNewsActive').checked=Number(n.ACTIVO)===1;document.getElementById('saveAdminNews').textContent='✓ Actualizar publicación';document.getElementById('adminNewsTitle').focus();});const del=document.createElement('button');del.type='button';del.className='btn btn-danger';del.style.marginLeft='6px';del.textContent='Eliminar';del.addEventListener('click',async()=>{if(!await radarConfirm('¿Eliminar esta publicación?',{title:'Eliminar novedad',confirmText:'Sí, eliminar',danger:true}))return;try{const x=await apiRequest('admin_news_delete',{id:n.ID});document.getElementById('adminNewsStatus').textContent=x.message||'Publicación eliminada.';await loadAdminNews();}catch(e){document.getElementById('adminNewsStatus').textContent=e.message||'No se pudo eliminar.';}});td.append(edit,del);tr.append(td);body.append(tr);});
 }catch(e){body.innerHTML='<tr><td colspan="6">No se pudieron cargar las publicaciones.</td></tr>';const st=document.getElementById('adminNewsStatus');if(st)st.textContent=e.message||'Error al cargar novedades.';}
}
async function compressNewsImage(file){
 if(!file)return null;
 if(file.size>12*1024*1024)throw new Error('La imagen original supera 12 MB. Elige una imagen más pequeña.');
 if(file.type&&!/^image\/(jpeg|png|webp)$/.test(file.type))throw new Error('Selecciona una imagen JPG, PNG o WEBP.');
 const dataUrl=await new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(reader.result);reader.onerror=()=>reject(new Error('No se pudo leer la imagen seleccionada.'));reader.readAsDataURL(file);});
 const img=await new Promise((resolve,reject)=>{const image=new Image();image.onload=()=>resolve(image);image.onerror=()=>reject(new Error('La imagen no se pudo abrir. Elige una foto JPG o PNG.'));image.src=dataUrl;});
 const max=1200,scale=Math.min(1,max/Math.max(img.width,img.height));
 const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(img.width*scale));canvas.height=Math.max(1,Math.round(img.height*scale));
 const ctx=canvas.getContext('2d');if(!ctx)throw new Error('No se pudo preparar la imagen en este dispositivo.');ctx.drawImage(img,0,0,canvas.width,canvas.height);
 let result=canvas.toDataURL('image/jpeg',0.72);
 if(result.length>1150000)result=canvas.toDataURL('image/jpeg',0.58);
 if(result.length>1450000)throw new Error('No se pudo reducir la imagen lo suficiente. Selecciona otra imagen de menor tamaño.');
 return result;
}
document.getElementById('adminNewsImage')?.addEventListener('change',async event=>{const file=event.target.files?.[0],preview=document.getElementById('adminNewsImagePreview');if(!file){preview.innerHTML='';return;}try{const data=await compressNewsImage(file);preview.innerHTML='';const img=document.createElement('img');img.src=data;img.alt='Vista previa';img.style.cssText='max-width:220px;max-height:130px;border-radius:10px;object-fit:cover';preview.append(img);document.getElementById('adminNewsRemoveImage').checked=false;}catch(e){preview.textContent=e.message;event.target.value='';}});
document.getElementById('adminNewsForm')?.addEventListener('submit',async event=>{event.preventDefault();const b=document.getElementById('saveAdminNews'),st=document.getElementById('adminNewsStatus');b.disabled=true;st.textContent='Guardando publicación…';try{const file=document.getElementById('adminNewsImage').files?.[0]||null;const image=await compressNewsImage(file);const r=await apiRequest('admin_news_save',{id:document.getElementById('adminNewsId').value||0,titulo:document.getElementById('adminNewsTitle').value.trim(),categoria:document.getElementById('adminNewsCategory').value,tipo_usuario:document.getElementById('adminNewsAudience').value,contenido:document.getElementById('adminNewsContent').value.trim(),fecha_inicio:document.getElementById('adminNewsStart').value,fecha_fin:document.getElementById('adminNewsEnd').value,activo:document.getElementById('adminNewsActive').checked?1:0,imagen:image,quitar_imagen:document.getElementById('adminNewsRemoveImage').checked?1:0});st.textContent=r.message||'Publicación guardada.';resetAdminNewsForm();await loadAdminNews();await loadLatestNews();}catch(e){st.textContent=e.message||'No se pudo guardar la publicación.';}finally{b.disabled=false;}});
document.getElementById('cancelAdminNews')?.addEventListener('click',resetAdminNewsForm);

// Pestañas internas de Ajustes de la aplicación
function activateAppSettingsTab(tabName) {
    document.querySelectorAll("[data-settings-tab]").forEach(tab => {
        const active = tab.dataset.settingsTab === tabName;
        tab.classList.toggle("active", active);
        tab.setAttribute("aria-selected", active ? "true" : "false");
    });
    const panelId = tabName === "transportes" ? "settingsPanelTransportes" : (tabName === "bases-datos" ? "settingsPanelDatabase" : (tabName === "smtp" ? "settingsPanelSmtp" : (tabName === "cupones" ? "settingsPanelCoupons" : (tabName === "ofertas-recarga" ? "settingsPanelRechargeOffers" : (tabName === "novedades" ? "settingsPanelNews" : "settingsPanelTarifas")))));
    document.querySelectorAll(".app-settings-panel").forEach(panel => {
        panel.hidden = panel.id !== panelId;
    });
    if (tabName === "bases-datos") loadAdminDbConnectionInfo();
    if (tabName === "smtp") loadAdminSmtpInfo();
    if (tabName === "transportes") loadAdminTransportTypes();
    if (tabName === "cupones") loadAdminCoupons();
    if (tabName === "ofertas-recarga") loadAdminRechargeOffers();
    if (tabName === "novedades") loadAdminNews();
}
document.querySelectorAll("[data-settings-tab]").forEach(tab => {
    tab.addEventListener("click", () => activateAppSettingsTab(tab.dataset.settingsTab));
});
// Cada sección de Ajustes se abre ahora desde su propio submenú de Administración.
document.querySelectorAll("[data-settings-tab-nav]").forEach(link => {
    link.addEventListener("click", () => {
        activateAppSettingsTab(link.dataset.settingsTabNav);
        document.querySelectorAll("[data-settings-tab-nav]").forEach(item => {
            item.classList.toggle("active", item === link);
            if (item === link) item.setAttribute("aria-current", "page");
            else item.removeAttribute("aria-current");
        });
    });
});

async function loadAdminSmtpInfo() {
    const status=document.getElementById("adminSmtpStatus"); if(!status) return;
    try { const r=await apiRequest("admin_smtp_info",{}); const c=r.smtp||{};
        document.getElementById("adminSmtpHost").value=c.host||""; document.getElementById("adminSmtpPort").value=c.port||587;
        document.getElementById("adminSmtpEncryption").value=c.encryption||"tls"; document.getElementById("adminSmtpUser").value=c.user||"";
        document.getElementById("adminSmtpEmail").value=c.email||""; document.getElementById("adminSmtpName").value=c.name||"El Radar";
        document.getElementById("adminSmtpPassword").value=""; status.textContent=c.configured?"Configuración SMTP cargada. La contraseña está guardada de forma segura.":"Aún no hay una contraseña SMTP guardada.";
    } catch(e) { status.textContent=e.message||"No se pudo cargar la configuración SMTP."; }
}
document.getElementById("adminSmtpForm")?.addEventListener("submit",async e=>{e.preventDefault();const b=document.getElementById("saveAdminSmtp"),st=document.getElementById("adminSmtpStatus");b.disabled=true;st.textContent="Guardando configuración…";try{const r=await apiRequest("admin_smtp_save",{host:document.getElementById("adminSmtpHost").value.trim(),port:document.getElementById("adminSmtpPort").value,encryption:document.getElementById("adminSmtpEncryption").value,user:document.getElementById("adminSmtpUser").value.trim(),password:document.getElementById("adminSmtpPassword").value,email:document.getElementById("adminSmtpEmail").value.trim(),name:document.getElementById("adminSmtpName").value.trim()});document.getElementById("adminSmtpPassword").value="";st.textContent=r.message||"Configuración guardada.";}catch(err){st.textContent=err.message||"No se pudo guardar.";}finally{b.disabled=false;}});
document.getElementById("testAdminSmtp")?.addEventListener("click",async()=>{const b=document.getElementById("testAdminSmtp"),st=document.getElementById("adminSmtpStatus");b.disabled=true;st.textContent="Enviando correo de prueba…";try{const r=await apiRequest("admin_smtp_test",{email:document.getElementById("adminSmtpEmail").value.trim()});st.textContent=r.message||"Correo de prueba enviado.";}catch(e){st.textContent=e.message||"No se pudo enviar el correo de prueba.";}finally{b.disabled=false;}});

async function loadTransportTypeOptions() {
    const select = document.getElementById("transportVehicleType");
    if (!select) return;
    const previous = select.value;
    try {
        const result = await apiRequest("transport_types_list", {});
        const types = Array.isArray(result.tipos) ? result.tipos : [];
        select.innerHTML = "";
        const placeholder = document.createElement("option");
        placeholder.value = ""; placeholder.textContent = types.length ? "Selecciona el tipo de transporte" : "No hay tipos de transporte disponibles";
        select.appendChild(placeholder);
        types.forEach(type => { const option = document.createElement("option"); option.value = type.NOMBRE; option.textContent = type.NOMBRE; select.appendChild(option); });
        if (types.some(type => type.NOMBRE === previous)) select.value = previous;
        select.disabled = types.length === 0;
    } catch (error) {
        select.innerHTML = "<option value=\"\">No se pudieron cargar los tipos</option>";
        select.disabled = true;
    }
}

function resetAdminTransportTypeForm() {
    const form = document.getElementById("adminTransportTypeForm");
    if (!form) return;
    form.reset();
    document.getElementById("adminTransportTypeId").value = "";
    document.getElementById("adminTransportTypeOrder").value = "0";
    document.getElementById("adminTransportTypeActive").checked = true;
    document.getElementById("saveAdminTransportType").textContent = "✓ Guardar tipo";
}

async function loadAdminTransportTypes() {
    const body = document.getElementById("adminTransportTypesBody");
    const status = document.getElementById("adminTransportTypeStatus");
    if (!body) return;
    body.innerHTML = '<tr><td colspan="4">Cargando tipos de transporte…</td></tr>';
    try {
        const result = await apiRequest("admin_transport_types_list", {});
        const types = Array.isArray(result.tipos) ? result.tipos : [];
        body.innerHTML = "";
        if (!types.length) { body.innerHTML = '<tr><td colspan="4">No hay tipos de transporte registrados.</td></tr>'; return; }
        types.forEach(type => {
            const tr = document.createElement("tr");
            const name = document.createElement("td"); name.textContent = type.NOMBRE;
            const order = document.createElement("td"); order.textContent = String(type.ORDEN);
            const state = document.createElement("td"); state.textContent = Number(type.ACTIVO) === 1 ? "Activo" : "Inactivo";
            const actions = document.createElement("td");
            const edit = document.createElement("button"); edit.type = "button"; edit.className = "btn btn-secondary"; edit.textContent = "Editar"; edit.style.marginRight = "6px";
            edit.addEventListener("click", () => {
                document.getElementById("adminTransportTypeId").value = type.ID;
                document.getElementById("adminTransportTypeName").value = type.NOMBRE;
                document.getElementById("adminTransportTypeOrder").value = type.ORDEN;
                document.getElementById("adminTransportTypeActive").checked = Number(type.ACTIVO) === 1;
                document.getElementById("saveAdminTransportType").textContent = "✓ Actualizar tipo";
                document.getElementById("adminTransportTypeName").focus();
            });
            const del = document.createElement("button"); del.type = "button"; del.className = "btn btn-danger"; del.textContent = "Eliminar";
            del.addEventListener("click", async () => {
                if (!await radarConfirm('¿Eliminar o desactivar el tipo «' + type.NOMBRE + '»?', {title:"Eliminar tipo de transporte", confirmText:"Continuar", danger:true})) return;
                try { const response = await apiRequest("admin_transport_types_delete", {id:type.ID}); status.textContent = response.message || "Tipo eliminado."; await loadAdminTransportTypes(); await loadTransportTypeOptions(); }
                catch (error) { status.textContent = error.message || "No se pudo eliminar el tipo."; }
            });
            actions.append(edit, del); tr.append(name, order, state, actions); body.appendChild(tr);
        });
    } catch (error) { body.innerHTML = '<tr><td colspan="4">No se pudieron cargar los tipos de transporte.</td></tr>'; if (status) status.textContent = error.message || "Error al cargar."; }
}

document.getElementById("adminTransportTypeForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const status = document.getElementById("adminTransportTypeStatus");
    const button = document.getElementById("saveAdminTransportType");
    button.disabled = true; status.textContent = "Guardando tipo de transporte…";
    try {
        const result = await apiRequest("admin_transport_types_save", {
            id: document.getElementById("adminTransportTypeId").value || 0,
            nombre: document.getElementById("adminTransportTypeName").value.trim(),
            orden: document.getElementById("adminTransportTypeOrder").value,
            activo: document.getElementById("adminTransportTypeActive").checked ? 1 : 0
        });
        status.textContent = result.message || "Tipo guardado correctamente.";
        resetAdminTransportTypeForm(); await loadAdminTransportTypes(); await loadTransportTypeOptions();
    } catch (error) { status.textContent = error.message || "No se pudo guardar el tipo."; }
    finally { button.disabled = false; }
});
document.getElementById("cancelAdminTransportType")?.addEventListener("click", resetAdminTransportTypeForm);

const adminSubmenu = document.getElementById("adminSubmenu");
const toast = document.getElementById("toast");
let toastTimer;

async function loadTransportAudit(){
 const status=document.getElementById('transportAuditStatus'),list=document.getElementById('transportAuditCards');
 if(!status||!list)return;status.textContent='Consultando registros…';list.replaceChildren();
 try{
  const r=await apiRequest('admin_transport_audit',{}),rows=Array.isArray(r.registros)?r.registros:[];
  status.textContent=rows.length?`${rows.length} eventos registrados.`:'Todavía no hay aprobaciones o revocaciones registradas.';
  const esc=v=>String(v??'').trim()||'No registrado';
  const photo=(src,alt)=>{const img=document.createElement('img');img.alt=alt;img.loading='lazy';img.style.cssText='width:100%;height:150px;object-fit:cover;border-radius:12px;background:#e8edf5;';if(isEmbeddedRadarImage(src))img.src=src;else img.src='data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 120 100"%3E%3Crect width="120" height="100" fill="%23e8edf5"/%3E%3Ccircle cx="60" cy="35" r="18" fill="%23aab7ca"/%3E%3Cpath d="M20 100c4-25 18-38 40-38s36 13 40 38" fill="%23aab7ca"/%3E%3C/svg%3E';return img;};
  const field=(label,value)=>{const box=document.createElement('div');box.style.cssText='padding:10px 12px;border:1px solid var(--border,#e3e8f0);border-radius:10px;background:var(--surface-2,#f7f9fc);min-width:0;';const k=document.createElement('div');k.textContent=label;k.style.cssText='font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;color:var(--muted,#667085);margin-bottom:4px;';const v=document.createElement('div');v.textContent=esc(value);v.style.cssText='font-size:13px;font-weight:650;overflow-wrap:anywhere;color:var(--text,#24324a);';box.append(k,v);return box;};
  const renderCards=visibleRows=>{
   list.replaceChildren();
   if(!visibleRows.length){const empty=document.createElement('p');empty.className='card-description';empty.style.cssText='padding:22px;text-align:center';empty.textContent=rows.length?'No se encontraron registros que coincidan con la búsqueda.':'Todavía no hay aprobaciones o revocaciones registradas.';list.append(empty);return;}
   visibleRows.forEach(x=>{
   const c=document.createElement('article');c.className='admin-request-card';c.style.cssText='padding:18px;margin-bottom:16px;';
   const head=document.createElement('div');head.style.cssText='display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;';
   const h=document.createElement('h3');h.textContent=`Historial #${x.ID} · ${esc(x.ACCION)}`;h.style.margin='0';
   const dt=document.createElement('div');dt.textContent=new Date(String(x.FECHA_APROBACION||'').replace(' ','T')).toLocaleString('es-MX');dt.style.cssText='font-size:12px;color:var(--muted,#667085);font-weight:700;';head.append(h,dt);
   const paired=document.createElement('section');paired.style.cssText='margin-top:16px;padding:14px;border:1px solid var(--border,#dce3ed);border-radius:16px;background:var(--surface-2,#f7f9fc);';
   const pairTitle=document.createElement('h4');pairTitle.textContent='Transportista y transporte';pairTitle.style.cssText='margin:0 0 10px;font-size:15px;';
   const pairImages=document.createElement('div');pairImages.style.cssText='display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;';
   [['Foto del transportista',x.FOTO_TRANSPORTISTA],['Foto del transporte',x.FOTO_VEHICULO]].forEach(([label,src])=>{const wrap=document.createElement('div');wrap.style.minWidth='0';wrap.append(photo(src,label));const cap=document.createElement('div');cap.textContent=label;cap.style.cssText='font-size:12px;font-weight:800;margin-top:7px;text-align:center;';wrap.append(cap);pairImages.append(wrap);});
   const tname=`${x.NOMBRE||''} ${x.APELLIDOS||''}`.trim()||x.TRANSPORTISTA_USUARIO;
   const tgrid=document.createElement('div');tgrid.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;margin-top:12px;';
   [['Nombre completo',tname],['Usuario',`@${esc(x.TRANSPORTISTA_USUARIO)}`],['ID transportista',x.TRANSPORTISTA_ID],['Solicitud',`#${x.SOLICITUD_ID}`],['Carnet de identidad',x.CARNET_IDENTIDAD],['Tipo de vehículo',x.TIPO_VEHICULO],['Chapa',x.CHAPA],['Licencia de conducción',x.LICENCIA_CONDUCCION],['Teléfono',x.TELEFONO],['Estado de solicitud',x.ESTADO_SOLICITUD],['Fecha de solicitud',x.SOLICITUD_CREADA_EN?new Date(String(x.SOLICITUD_CREADA_EN).replace(' ','T')).toLocaleString('es-MX'):null]].forEach(([k,v])=>tgrid.append(field(k,v)));
   paired.append(pairTitle,pairImages,tgrid);
   const approver=document.createElement('section');approver.style.cssText='display:grid;grid-template-columns:minmax(100px,150px) minmax(0,1fr);gap:14px;align-items:start;margin-top:14px;padding:14px;border:1px solid var(--border,#dce3ed);border-radius:16px;';
   const approverPhoto=document.createElement('div');approverPhoto.append(photo(x.FOTO_APROBADOR,'Foto de quien realizó la acción'));const cap=document.createElement('div');cap.textContent='Quién realizó la acción';cap.style.cssText='font-size:12px;font-weight:800;margin-top:7px;text-align:center;';approverPhoto.append(cap);
   const ad=document.createElement('div');const ah=document.createElement('h4');ah.textContent='Datos del aprobador / responsable';ah.style.cssText='margin:0 0 10px;font-size:15px;';const ag=document.createElement('div');ag.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:8px;';
   const aname=`${x.APROBADOR_NOMBRE||''} ${x.APROBADOR_APELLIDOS||''}`.trim()||x.APROBADO_POR_USUARIO;
   [['Nombre completo',aname],['Usuario',`@${esc(x.APROBADO_POR_USUARIO)}`],['ID de usuario',x.APROBADO_POR_ID],['Tipo de cuenta',x.APROBADOR_TIPO],['Acción realizada',x.ACCION],['Fecha y hora',dt.textContent]].forEach(([k,v])=>ag.append(field(k,v)));ad.append(ah,ag);approver.append(approverPhoto,ad);
   c.append(head,paired,approver);list.append(c);
   });
  };
  const search=document.getElementById('transportAuditSearch');
  const applySearch=()=>{
   const term=(search?.value||'').trim().toLocaleLowerCase('es-MX');
   const filtered=term?rows.filter(x=>Object.entries(x).filter(([key])=>!/(FOTO|IMAGEN|BASE64)/i.test(key)).some(([,value])=>String(value??'').toLocaleLowerCase('es-MX').includes(term))):rows;
   renderCards(filtered);
   status.textContent=term?`Mostrando ${filtered.length} de ${rows.length} registros encontrados.`:`${rows.length} eventos registrados.`;
  };
  if(search)search.oninput=applySearch;
  applySearch();
 }catch(e){status.textContent=e.message||'No se pudo cargar el historial.';}
}
function showEditorTransportistaDetails(t){
 const overlay=document.createElement('div');overlay.style.cssText='position:fixed;inset:0;z-index:12000;background:rgba(9,16,32,.72);backdrop-filter:blur(5px);display:flex;align-items:center;justify-content:center;padding:12px;';
 const dialog=document.createElement('div');dialog.style.cssText='width:min(100%,760px);max-height:92vh;overflow:auto;background:#fff;border-radius:22px;box-shadow:0 24px 80px #0006;color:#1d2939;';
 const hero=document.createElement('div');hero.style.cssText='height:190px;position:relative;background:linear-gradient(135deg,#e8eef7,#cfd9e8);border-radius:22px 22px 0 0;';
 if(isEmbeddedRadarImage(t.FOTO_VEHICULO)){const vehicle=document.createElement('img');vehicle.src=t.FOTO_VEHICULO;vehicle.alt='Foto del transporte';vehicle.style.cssText='width:100%;height:100%;object-fit:cover;border-radius:22px 22px 0 0;';hero.append(vehicle);}else{hero.textContent='Foto del transporte no disponible';hero.style.cssText+='display:flex;align-items:center;justify-content:center;color:#667085;font-weight:700;';}
 const close=document.createElement('button');close.type='button';close.textContent='×';close.setAttribute('aria-label','Cerrar');close.style.cssText='position:absolute;right:12px;top:12px;width:40px;height:40px;border:0;border-radius:50%;background:#fff;color:#344054;font-size:27px;cursor:pointer;';close.addEventListener('click',()=>overlay.remove());hero.append(close);
 const content=document.createElement('div');content.style.cssText='padding:18px 20px 22px;';
 const identity=document.createElement('div');identity.style.cssText='display:flex;align-items:center;gap:14px;margin-top:-54px;position:relative;';
 const avatar=document.createElement('div');avatar.style.cssText='width:86px;height:86px;flex:0 0 86px;border:4px solid #fff;border-radius:50%;overflow:hidden;background:#edf2f7;box-shadow:0 4px 15px #0002;';
 if(isEmbeddedRadarImage(t.FOTO)){const pic=document.createElement('img');pic.src=t.FOTO;pic.alt='Foto de perfil';pic.style.cssText='width:100%;height:100%;object-fit:cover;';avatar.append(pic);}else{avatar.textContent='👤';avatar.style.cssText+='display:flex;align-items:center;justify-content:center;font-size:34px;';}
 const heading=document.createElement('div');heading.style.cssText='padding-top:45px;min-width:0;';const name=document.createElement('h2');name.textContent=(`${t.NOMBRE||''} ${t.APELLIDOS||''}`).trim()||t.TRANSPORTISTA_USUARIO;name.style.cssText='margin:0 0 4px;font-size:21px;';const username=document.createElement('p');username.textContent=`@${t.TRANSPORTISTA_USUARIO} · ${t.estado_actual}`;username.style.cssText='margin:0;color:#667085;font-size:13px;';heading.append(name,username);identity.append(avatar,heading);content.append(identity);
 const stats=document.createElement('div');stats.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:18px 0;';
 [["Total de recargas",formatFinanceMoney(t.total_recargas)],["Porcentaje del editor",Number(t.porcentaje_editor||0).toLocaleString('es-MX',{maximumFractionDigits:2})+'%'],["Ganancia del editor",formatFinanceMoney(t.ganancia_editor)]].forEach(([label,value])=>{const box=document.createElement('div');box.style.cssText='padding:13px;border:1px solid #e7ebf2;border-radius:13px;background:#f8faff;';const l=document.createElement('small');l.textContent=label;l.style.cssText='display:block;color:#667085;margin-bottom:6px;';const v=document.createElement('strong');v.textContent=value;v.style.cssText='font-size:17px;color:#243b65;';box.append(l,v);stats.append(box);});content.append(stats);
 const title=document.createElement('h3');title.textContent='Transferencias confirmadas';title.style.cssText='font-size:16px;margin:18px 0 10px;';content.append(title);
 const operations=Array.isArray(t.operaciones)?t.operaciones:[];if(!operations.length){const empty=document.createElement('p');empty.textContent='Este transportista todavía no tiene transferencias confirmadas.';empty.style.color='#667085';content.append(empty);}else{const tableWrap=document.createElement('div');tableWrap.style.cssText='overflow:auto;border:1px solid #e7ebf2;border-radius:13px;';const table=document.createElement('table');table.style.cssText='width:100%;border-collapse:collapse;min-width:560px;font-size:13px;';const thead=document.createElement('thead');const trh=document.createElement('tr');['Fecha','Transferencia confirmada','Importe','Porcentaje aplicado','Ganancia editor','ID de transferencia'].forEach(label=>{const th=document.createElement('th');th.textContent=label;th.style.cssText='text-align:left;padding:11px;background:#f3f6fb;color:#475467;';trh.append(th);});thead.append(trh);table.append(thead);const tbody=document.createElement('tbody');operations.forEach(m=>{const tr=document.createElement('tr');[m.CREADO_EN?new Date(String(m.CREADO_EN).replace(' ','T')).toLocaleString('es-MX'):'—',m.DESCRIPCION||m.TIPO||'Operación',formatFinanceMoney(m.IMPORTE),(m.PORCENTAJE_APLICADO===null||m.PORCENTAJE_APLICADO===undefined?'No registrado':Number(m.PORCENTAJE_APLICADO).toLocaleString('es-MX',{minimumFractionDigits:2,maximumFractionDigits:2})+' %'),formatFinanceMoney(m.GANANCIA_EDITOR),m.REFERENCIA||'—'].forEach(value=>{const td=document.createElement('td');td.textContent=value;td.style.cssText='padding:11px;border-top:1px solid #edf0f5;vertical-align:top;';tr.append(td);});tbody.append(tr);});table.append(tbody);tableWrap.append(table);content.append(tableWrap);}
 dialog.append(hero,content);overlay.append(dialog);overlay.addEventListener('click',e=>{if(e.target===overlay)overlay.remove();});const esc=e=>{if(e.key==='Escape'){overlay.remove();document.removeEventListener('keydown',esc);}};document.addEventListener('keydown',esc);document.body.append(overlay);
}
async function loadEditorAccountStatement(){
 const status=document.getElementById('editorStatementStatus'); const host=document.getElementById('editorStatementTable');
 if(!status||!host)return; status.textContent='Consultando operaciones…'; host.replaceChildren();
 try{
  const desde=document.getElementById('editorStatementFrom')?.value||''; const hasta=document.getElementById('editorStatementTo')?.value||'';
  const r=await apiRequest('editor_account_statement',{desde,hasta});
  const money=v=>formatFinanceMoney(Number(v||0));
  document.getElementById('editorStatementCredits').textContent=money(r.creditos_periodo);
  document.getElementById('editorStatementDebits').textContent=money(r.debitos_periodo);
  document.getElementById('editorStatementBalance').textContent=money(r.saldo_disponible);
  const rows=Array.isArray(r.operaciones)?r.operaciones:[];
  status.textContent=rows.length?`${rows.length} operaciones encontradas.`:'No hay operaciones para el período seleccionado.';
  if(!rows.length){const empty=document.createElement('p');empty.textContent='No se encontraron operaciones en las fechas seleccionadas.';empty.style.cssText='margin:0;padding:24px;text-align:center;color:#667085;';host.append(empty);return;}
  const wrap=document.createElement('div');wrap.style.cssText='overflow:auto;';const table=document.createElement('table');table.style.cssText='width:100%;border-collapse:collapse;min-width:720px;font-size:13px;';
  const thead=document.createElement('thead');const hr=document.createElement('tr');['Fecha','Operación','Descripción','Referencia','Importe','Saldo después'].forEach(label=>{const th=document.createElement('th');th.textContent=label;th.style.cssText='padding:12px;text-align:left;background:#f3f6fb;color:#475467;white-space:nowrap;';hr.append(th);});thead.append(hr);table.append(thead);
  const tbody=document.createElement('tbody');rows.forEach(m=>{const tr=document.createElement('tr');const vals=[m.fecha?new Date(String(m.fecha).replace(' ','T')).toLocaleString('es-MX'):'—',m.tipo==='credito'?'Crédito':'Débito',m.descripcion||'—',m.referencia||'—',(m.tipo==='credito'?'+ ':'− ')+money(m.importe),money(m.saldo)];vals.forEach((v,i)=>{const td=document.createElement('td');td.textContent=v;td.style.cssText='padding:12px;border-top:1px solid #edf0f5;vertical-align:top;';if(i===4){td.style.fontWeight='850';td.style.color=m.tipo==='credito'?'#087443':'#b42318';}tr.append(td);});tbody.append(tr);});table.append(tbody);wrap.append(table);host.append(wrap);
 }catch(e){status.textContent=e.message||'No se pudo cargar el estado de cuenta.';}
}
document.getElementById('refreshEditorStatement')?.addEventListener('click',loadEditorAccountStatement);
document.getElementById('filterEditorStatement')?.addEventListener('click',loadEditorAccountStatement);
document.getElementById('clearEditorStatementFilter')?.addEventListener('click',()=>{document.getElementById('editorStatementFrom').value='';document.getElementById('editorStatementTo').value='';loadEditorAccountStatement();});

async function loadMyTransportistas(){
 const status=document.getElementById('myTransportistasStatus');
 const list=document.getElementById('myTransportistasCards');
 if(!status||!list)return;
 status.textContent='Consultando tus transportistas…';
 list.replaceChildren();
 try{
  const r=await apiRequest('editor_my_transportistas',{});
  const items=Array.isArray(r.transportistas)?r.transportistas:[];
  const percent=Number(r.porcentaje_editor||0);
  // Mostrar lo que queda disponible en la cuenta después de descontar pagos.
  const totalEarnings=Number.isFinite(Number(r.ganancias_acumuladas))
   ? Math.max(0,Number(r.ganancias_acumuladas))
   : items.reduce((sum,t)=>sum+Math.max(0,Number(t.ganancia_editor)||0),0);
  const percentNode=document.getElementById('myTransportistasPercent');
  if(percentNode)percentNode.textContent=percent.toLocaleString('es-MX',{maximumFractionDigits:2})+'%';
  const earningsNode=document.getElementById('myTransportistasTotalEarnings');
  if(earningsNode)earningsNode.textContent=formatFinanceMoney(totalEarnings);
  const countNode=document.getElementById('myTransportistasCount');
  if(countNode)countNode.textContent=String(items.length);
  status.textContent=items.length?`${items.length} transportistas en tu historial. Selecciona una tarjeta para consultar sus transferencias confirmadas y tus ganancias.`:'Aún no has aprobado ni revocado transportistas.';
  list.style.cssText='display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:16px;';
  items.forEach(t=>{
   const c=document.createElement('button');c.type='button';c.className='admin-request-card';
   c.style.cssText='display:flex;flex-direction:column;align-items:stretch;width:100%;min-width:0;text-align:left;cursor:pointer;padding:0;border:1px solid #dce5f2;border-radius:20px;overflow:hidden;background:#fff;box-shadow:0 7px 22px rgba(25,45,85,.09);transition:transform .18s ease,box-shadow .18s ease;';
   c.addEventListener('mouseenter',()=>{c.style.transform='translateY(-3px)';c.style.boxShadow='0 14px 30px rgba(25,45,85,.16)';});
   c.addEventListener('mouseleave',()=>{c.style.transform='none';c.style.boxShadow='0 7px 22px rgba(25,45,85,.09)';});
   const banner=document.createElement('div');banner.style.cssText='height:118px;position:relative;background:linear-gradient(135deg,#e8effa,#cbd9ef);overflow:hidden;';
   const vehicle=document.createElement('img');vehicle.alt='Foto del transporte';vehicle.src=isEmbeddedRadarImage(t.FOTO_VEHICULO)?t.FOTO_VEHICULO:'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 140"%3E%3Crect width="400" height="140" fill="%23e8effa"/%3E%3Cpath d="M90 90h220l-25-45H130zM105 90v22h190V90" fill="%2397a9c3"/%3E%3Ccircle cx="145" cy="112" r="13" fill="%234b5e78"/%3E%3Ccircle cx="260" cy="112" r="13" fill="%234b5e78"/%3E%3C/svg%3E';
   vehicle.style.cssText='width:100%;height:100%;object-fit:cover;';banner.append(vehicle);
   const state=document.createElement('span');const approved=String(t.estado_actual||'').toUpperCase()==='APROBADO';state.textContent=approved?'● APROBADO':'● REVOCADO';state.style.cssText=`position:absolute;right:12px;top:12px;padding:7px 11px;border-radius:30px;background:${approved?'#e7f8ef':'#fff0ed'};color:${approved?'#087443':'#b42318'};font-size:10px;font-weight:900;letter-spacing:.04em;box-shadow:0 2px 8px #0001;`;banner.append(state);
   const body=document.createElement('div');body.style.cssText='padding:0 16px 16px;';
   const identity=document.createElement('div');identity.style.cssText='display:flex;align-items:center;gap:12px;margin-top:-29px;position:relative;';
   const avatar=document.createElement('img');avatar.alt='Foto de perfil';avatar.src=isEmbeddedRadarImage(t.FOTO)?t.FOTO:'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"%3E%3Crect width="64" height="64" rx="32" fill="%23dce6f4"/%3E%3Ccircle cx="32" cy="23" r="12" fill="%23889bb8"/%3E%3Cpath d="M9 60c2-14 10-21 23-21s21 7 23 21" fill="%23889bb8"/%3E%3C/svg%3E';avatar.style.cssText='width:66px;height:66px;flex:0 0 66px;border:4px solid #fff;border-radius:50%;object-fit:cover;background:#fff;box-shadow:0 4px 14px #152b4c30;';
   const identityText=document.createElement('div');identityText.style.cssText='min-width:0;padding-top:26px;';
   const h=document.createElement('h3');h.textContent=(`${t.NOMBRE||''} ${t.APELLIDOS||''}`).trim()||t.TRANSPORTISTA_USUARIO;h.style.cssText='margin:0 0 3px;font-size:17px;font-weight:850;color:#172b4d;overflow-wrap:anywhere;';
   const p=document.createElement('p');p.textContent='@'+(t.TRANSPORTISTA_USUARIO||'');p.style.cssText='margin:0;color:#718096;font-size:12px;';identityText.append(h,p);identity.append(avatar,identityText);body.append(identity);
   const metric=document.createElement('div');metric.style.cssText='margin-top:15px;padding:14px 15px;border-radius:15px;background:linear-gradient(110deg,#eff8f3,#e4f5ec);border:1px solid #d1ebdc;';
   const metricLabel=document.createElement('div');metricLabel.textContent='TU GANANCIA ACUMULADA';metricLabel.style.cssText='font-size:10px;letter-spacing:.08em;font-weight:900;color:#24734d;';
   const metricValue=document.createElement('div');metricValue.textContent=formatFinanceMoney(t.ganancia_editor);metricValue.style.cssText='font-size:25px;font-weight:900;color:#087443;margin-top:3px;';metric.append(metricLabel,metricValue);body.append(metric);
   const footer=document.createElement('div');footer.style.cssText='display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:13px;padding-top:12px;border-top:1px solid #edf1f6;';
   const recargas=document.createElement('div');recargas.style.cssText='min-width:0;';const recargasLabel=document.createElement('small');recargasLabel.textContent='Recargas confirmadas';recargasLabel.style.cssText='display:block;color:#7b8798;font-size:10px;';const recargasValue=document.createElement('strong');recargasValue.textContent=formatFinanceMoney(t.total_recargas);recargasValue.style.cssText='display:block;color:#263b5b;font-size:14px;margin-top:2px;';recargas.append(recargasLabel,recargasValue);
   const action=document.createElement('span');action.textContent='Ver historial  ›';action.style.cssText='color:#2457a6;font-size:12px;font-weight:850;white-space:nowrap;';footer.append(recargas,action);body.append(footer);
   c.append(banner,body);c.addEventListener('click',()=>showEditorTransportistaDetails(t));list.append(c);
  });
 }catch(e){status.textContent=e.message||'No se pudieron cargar tus transportistas.';}
}
document.getElementById('refreshTransportAudit')?.addEventListener('click',loadTransportAudit);document.getElementById('refreshMyTransportistas')?.addEventListener('click',loadMyTransportistas);
// Actualización silenciosa del saldo: consulta el servidor sin reconstruir
// las tarjetas ni recargar/navegar la página. Solo cambia el importe visible.
let myTransportistasBalanceInProgress = false;
async function refreshMyTransportistasBalance() {
 const page = document.getElementById('admin-mis-transportistas');
 const earningsNode = document.getElementById('myTransportistasTotalEarnings');
 if (document.visibilityState !== 'visible' || !page || !page.classList.contains('active') || !earningsNode || myTransportistasBalanceInProgress) return;
 myTransportistasBalanceInProgress = true;
 try {
  const r = await apiRequest('editor_my_transportistas', {});
  if (!r || r.ok === false) return;
  const balance = Number(r.ganancias_acumuladas);
  if (Number.isFinite(balance)) {
   const nextText = formatFinanceMoney(Math.max(0, balance));
   if (earningsNode.textContent !== nextText) {
    earningsNode.textContent = nextText;
    earningsNode.setAttribute('aria-live', 'polite');
   }
  }
 } catch (e) {
  // Se conserva el último saldo mostrado si hay un corte momentáneo de conexión.
 } finally {
  myTransportistasBalanceInProgress = false;
 }
}
// Consulta periódica ligera para mantener el saldo actualizado sin refrescar la vista.
setInterval(refreshMyTransportistasBalance, 2000);

function isAdminUser(user) {
    return String(user && user.tipo || "").trim().toLocaleLowerCase("es-MX") === "admin";
}
function isTransportEditor(user) {
    return Number(user && user.es_editor || 0) === 1;
}
function canReviewTransportRequests(user) {
    return isAdminUser(user) || isTransportEditor(user);
}

let editorPaymentCardPromptOpen = false;
let editorPaymentCardPromptLoading = false;
async function ensureEditorPaymentCardPrompt(user) {
    if (!user || Number(user.es_editor || 0) !== 1 || editorPaymentCardPromptOpen || editorPaymentCardPromptLoading) return;
    editorPaymentCardPromptLoading = true;
    try {
        const state = await apiRequest('editor_payment_card_get', {});
        if (state.tarjeta_configurada) return;
        editorPaymentCardPromptOpen = true;
        const overlay = document.createElement('div');
        overlay.id = 'editorPaymentCardRequiredOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:25000;background:rgba(7,15,32,.78);backdrop-filter:blur(7px);display:flex;align-items:center;justify-content:center;padding:16px;';
        const dialog = document.createElement('section');
        dialog.setAttribute('role','dialog'); dialog.setAttribute('aria-modal','true'); dialog.setAttribute('aria-labelledby','editorPaymentCardTitle');
        dialog.style.cssText = 'width:min(100%,480px);padding:25px;border-radius:22px;background:#fff;box-shadow:0 25px 80px #0005;';
        const icon = document.createElement('div'); icon.textContent='💳'; icon.style.cssText='width:58px;height:58px;display:grid;place-items:center;border-radius:17px;background:#eaf1ff;font-size:29px;margin-bottom:15px;';
        const title = document.createElement('h2'); title.id='editorPaymentCardTitle'; title.textContent='Configura tu tarjeta de cobro'; title.style.cssText='margin:0 0 9px;color:#172b4d;font-size:22px;';
        const info = document.createElement('p'); info.textContent='Tu permiso de Editor está activado. Indica el número de la tarjeta en la que deseas recibir tus pagos. Hasta que guardes este dato no podrás aprobar ni revocar solicitudes de transportista.'; info.style.cssText='color:#596579;line-height:1.55;margin:0 0 18px;';
        const label = document.createElement('label'); label.textContent='Número de tarjeta'; label.htmlFor='editorPaymentCardInput'; label.style.cssText='display:block;font-weight:800;color:#263b5b;margin-bottom:7px;';
        const input = document.createElement('input'); input.id='editorPaymentCardInput'; input.type='text'; input.inputMode='numeric'; input.autocomplete='cc-number'; input.maxLength=30; input.placeholder='Introduce el número de tarjeta'; input.style.cssText='width:100%;box-sizing:border-box;padding:14px;border:1px solid #cbd5e1;border-radius:12px;font-size:17px;letter-spacing:.06em;outline:none;';
        const help = document.createElement('small'); help.textContent='Solo se guardarán los dígitos del número de tarjeta.'; help.style.cssText='display:block;color:#788397;margin-top:7px;';
        const status = document.createElement('p'); status.setAttribute('aria-live','polite'); status.style.cssText='min-height:20px;color:#b42318;font-size:13px;margin:12px 0 0;';
        const save = document.createElement('button'); save.type='button'; save.textContent='Guardar tarjeta y continuar'; save.style.cssText='width:100%;margin-top:17px;padding:14px;border:0;border-radius:12px;background:linear-gradient(120deg,#2457a6,#477be0);color:#fff;font-weight:850;font-size:15px;cursor:pointer;';
        save.addEventListener('click', async()=>{
            const digits=input.value.replace(/\D/g,'');
            if(digits.length<8||digits.length>30){status.textContent='Introduce un número de tarjeta válido (entre 8 y 30 dígitos).';input.focus();return;}
            save.disabled=true;save.textContent='Guardando…';status.style.color='#596579';status.textContent='Registrando tu tarjeta de cobro…';
            try{const result=await apiRequest('editor_payment_card_save',{tarjeta:digits});overlay.remove();editorPaymentCardPromptOpen=false;showToast(result.message||'Tarjeta registrada.');}
            catch(error){status.style.color='#b42318';status.textContent=error.message||'No se pudo guardar la tarjeta.';}
            finally{save.disabled=false;save.textContent='Guardar tarjeta y continuar';}
        });
        dialog.append(icon,title,info,label,input,help,status,save);overlay.append(dialog);document.body.append(overlay);input.focus();
    } catch(error) { console.warn('No se pudo comprobar la tarjeta de cobro del editor:',error); }
    finally { editorPaymentCardPromptLoading=false; }
}

function syncAdminModuleVisibility(user) {
    const admin = isAdminUser(user);
    const editor = isTransportEditor(user);
    const allowed = admin || editor;
    if (editor) setTimeout(() => ensureEditorPaymentCardPrompt(user), 250);
    if (adminNavLink) adminNavLink.hidden = !allowed;
    document.querySelectorAll("#adminSubmenu a[data-page]").forEach(link => {
        link.hidden = link.dataset.adminOnly === "1" ? !admin : (link.dataset.editorOnly === "1" ? !editor : (!admin && !(editor && link.dataset.page === "admin-solicitudes")));
    });
    if (!allowed) {
        if (adminSubmenu) adminSubmenu.classList.remove("open");
        if (adminNavLink) adminNavLink.setAttribute("aria-expanded", "false");
        if (["administracion","admin-solicitudes","admin-usuarios","admin-ajustes","admin-transport-historial","admin-mis-transportistas","editor-estado-cuenta"].some(id => document.getElementById(id)?.classList.contains("active"))) showPage("radar");
    } else if (!admin && ["administracion","admin-finanzas","admin-pagos-editores","admin-usuarios","admin-ajustes","admin-transport-historial"].some(id => document.getElementById(id)?.classList.contains("active"))) {
        showPage("admin-solicitudes");
    }
}

function showToast(message) {
    toast.textContent = message;
    toast.classList.add("show");
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => toast.classList.remove("show"), 3800);
}

const notificationsOverlay = document.getElementById("notificationsOverlay");
const notificationsList = document.getElementById("notificationsList");
const notificationsSummary = document.getElementById("notificationsSummary");
const notificationCount = document.getElementById("notificationCount");
let notificationSyncTimer = null;
let notificationsLoading = false;
let notificationSoundContext = null;
let notificationSoundUnlocked = false;
let pendingBellSound = false;
let pendingTripHornSound = false;
let notificationBaselineReady = false;
let latestKnownNotificationId = 0;

// Los navegadores pueden bloquear el audio hasta una interacción explícita.
// Si llega una notificación antes de desbloquearlo, se guarda el aviso sonoro
// y se reproduce en cuanto el usuario toque o pulse una tecla en la página.
async function unlockNotificationSound() {
    try {
        const AudioContextClass = window.AudioContext || window.webkitAudioContext;
        if (!AudioContextClass) return;
        if (!notificationSoundContext) notificationSoundContext = new AudioContextClass();
        if (notificationSoundContext.state === "suspended") {
            await notificationSoundContext.resume();
        }
        notificationSoundUnlocked = notificationSoundContext.state === "running";
        if (notificationSoundUnlocked && pendingBellSound) {
            pendingBellSound = false;
            playSmallBellNotificationSound();
        }
        if (notificationSoundUnlocked && pendingTripHornSound) {
            pendingTripHornSound = false;
            playTripArrivalHornSound();
        }
    } catch (error) {
        notificationSoundUnlocked = false;
    }
}
["pointerdown", "touchstart", "keydown"].forEach(eventName => {
    document.addEventListener(eventName, () => { unlockNotificationSound(); }, { passive: true });
});
function playSmallBellNotificationSound() {
    if (!notificationSoundContext || !notificationSoundUnlocked || notificationSoundContext.state !== "running") {
        pendingBellSound = true;
        unlockNotificationSound();
        return;
    }
    try {
        const ctx = notificationSoundContext;
        const startAt = ctx.currentTime + 0.025;
        // Campana pequeña: dos tonos breves con volumen moderado y caída suave.
        [[1320, 0.00, 0.34], [1760, 0.07, 0.28]].forEach(([frequency, delay, duration]) => {
            const oscillator = ctx.createOscillator();
            const envelope = ctx.createGain();
            oscillator.type = "sine";
            oscillator.frequency.setValueAtTime(frequency, startAt + delay);
            envelope.gain.setValueAtTime(0.0001, startAt + delay);
            envelope.gain.linearRampToValueAtTime(0.32, startAt + delay + 0.012);
            envelope.gain.exponentialRampToValueAtTime(0.0001, startAt + delay + duration);
            oscillator.connect(envelope);
            envelope.connect(ctx.destination);
            oscillator.start(startAt + delay);
            oscillator.stop(startAt + delay + duration + 0.02);
        });
    } catch (error) {
        pendingBellSound = true;
    }
}
function updateNotificationBadge(count) {
    const safeCount = Math.max(0, Number(count) || 0);
    if (notificationCount) {
        notificationCount.textContent = safeCount > 99 ? "99+" : String(safeCount);
        notificationCount.hidden = safeCount === 0;
    }
    const button = document.getElementById("notificationButton");
    if (button) button.setAttribute("aria-label", safeCount ? `Notificaciones, ${safeCount} sin leer` : "Notificaciones");
}
function notificationTypeIcon(type) {
    return ({success:"✓", warning:"⚠", error:"!", info:"🔔"})[String(type || "info")] || "🔔";
}
function formatNotificationDate(value) {
    if (!value) return "Fecha no disponible";
    const normalized = String(value).includes("T") ? String(value) : String(value).replace(" ", "T");
    const date = new Date(normalized);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString("es-MX", {dateStyle:"short", timeStyle:"short"});
}
function playTripArrivalHornSound() {
    if (!notificationSoundContext || !notificationSoundUnlocked || notificationSoundContext.state !== "running") {
        pendingTripHornSound = true;
        unlockNotificationSound();
        return;
    }
    try {
        const ctx = notificationSoundContext;
        const startAt = ctx.currentTime + 0.03;
        // Tres bocinazos claramente separados.
        [0, 0.72, 1.44].forEach(delay => {
            [350, 440].forEach(frequency => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.type = "sawtooth";
                oscillator.frequency.setValueAtTime(frequency, startAt + delay);
                gain.gain.setValueAtTime(0.0001, startAt + delay);
                gain.gain.linearRampToValueAtTime(0.18, startAt + delay + 0.035);
                gain.gain.setValueAtTime(0.18, startAt + delay + 0.34);
                gain.gain.exponentialRampToValueAtTime(0.0001, startAt + delay + 0.48);
                oscillator.connect(gain); gain.connect(ctx.destination);
                oscillator.start(startAt + delay); oscillator.stop(startAt + delay + 0.5);
            });
        });
    } catch (_) {}
}
function showTripArrivalAlert(notification) {
    const overlay = document.getElementById("tripArrivalAlertOverlay");
    const vehiclePhoto = document.getElementById("tripArrivalVehiclePhoto");
    const driverPhoto = document.getElementById("tripArrivalDriverPhoto");
    const driverName = document.getElementById("tripArrivalDriverName");
    const setPhoto = (img, value) => {
        if (!img) return;
        const src = typeof value === "string" ? value.trim() : "";
        if (src && (src.startsWith("data:image/") || /^https?:\/\//i.test(src) || src.startsWith("/"))) {
            img.src = src; img.hidden = false;
        } else { img.removeAttribute("src"); img.hidden = true; }
    };
    const item = notification && typeof notification === "object" ? notification : {};
    setPhoto(vehiclePhoto, item.FOTO_VEHICULO);
    setPhoto(driverPhoto, item.FOTO_CONTACTO);
    if (driverName) driverName.textContent = [item.TRANSPORTISTA_NOMBRE, item.TRANSPORTISTA_APELLIDOS].filter(Boolean).join(" ").trim() || "Transportista";
    if (overlay) { overlay.classList.add("show"); overlay.setAttribute("aria-hidden", "false"); }
    playTripArrivalHornSound();
    if (item.ID) apiRequest("notification_mark_read", {id:Number(item.ID)}).catch(() => {});
}
document.addEventListener("click", event => {
    if (event.target.closest("#tripArrivalAlertClose, #tripArrivalAlertOk")) {
        const overlay = document.getElementById("tripArrivalAlertOverlay");
        if (overlay) { overlay.classList.remove("show"); overlay.setAttribute("aria-hidden", "true"); }
    }
});

let newsBroadcastLoading = false;
async function showNewsBroadcastDialog(notification) {
    const overlay=document.getElementById('newsBroadcastOverlay');
    if(!overlay || !notification || newsBroadcastLoading)return;
    const newsId=Number(notification.REFERENCIA_ID||0);
    if(!newsId)return;
    newsBroadcastLoading=true;
    try{
        const result=await apiRequest('latest_news_detail',{id:newsId});
        const item=result.novedad||{};
        const icons={bono:'🎁',recarga:'💸',promocion:'🎉',aviso:'📢',mantenimiento:'🛠️',informacion:'ℹ️'};
        document.getElementById('newsBroadcastIcon').textContent=icons[item.CATEGORIA]||'📰';
        document.getElementById('newsBroadcastTitle').textContent=item.TITULO||'Nueva novedad';
        document.getElementById('newsBroadcastCategory').textContent=String(item.CATEGORIA||'informacion').replace(/_/g,' ').toUpperCase();
        document.getElementById('newsBroadcastContent').textContent=item.CONTENIDO||'Consulta esta novedad en El Radar.';
        const image=document.getElementById('newsBroadcastImage');
        if(item.IMAGEN && /^data:image\/(jpeg|png|webp);base64,/.test(item.IMAGEN)){image.src=item.IMAGEN;image.hidden=false;}else{image.removeAttribute('src');image.hidden=true;}
        const date=document.getElementById('newsBroadcastDate');
        date.textContent=item.CREADO_EN?new Date(String(item.CREADO_EN).replace(' ','T')).toLocaleString('es-ES'):'';
        overlay.dataset.notificationId=String(notification.ID||'');
        overlay.classList.add('show');overlay.setAttribute('aria-hidden','false');
        loadLatestNews();
    }catch(error){
        showToast(error.message||'No se pudo abrir la novedad.');
    }finally{newsBroadcastLoading=false;}
}
function closeNewsBroadcastDialog(){
    const overlay=document.getElementById('newsBroadcastOverlay');
    if(!overlay)return;
    const notificationId=Number(overlay.dataset.notificationId||0);
    overlay.classList.remove('show');overlay.setAttribute('aria-hidden','true');
    if(notificationId)apiRequest('notification_mark_read',{id:notificationId}).then(()=>loadNotifications(false)).catch(()=>{});
}
document.addEventListener('click',event=>{
    if(event.target.closest('#newsBroadcastClose,#newsBroadcastOk'))closeNewsBroadcastDialog();
    const overlay=document.getElementById('newsBroadcastOverlay');
    if(overlay&&event.target===overlay)closeNewsBroadcastDialog();
});

function showBonusBroadcastDialog(item) {
    const overlay=document.getElementById("bonusBroadcastOverlay");
    const details=document.getElementById("bonusBroadcastDetails");
    const amountBox=document.getElementById("bonusBroadcastAmount");
    if(!overlay || !details || !item) return;
    const isRechargeOffer=String(item.REFERENCIA_TIPO||'')==='oferta_recarga' || String(item.TITULO||'').startsWith('🎉 Nueva oferta de recarga');
    const raw=String(item.MENSAJE||"");
    const lines=raw.split(/\r?\n/).map(line=>line.trim()).filter(Boolean);
    const codeLine=lines.find(line=>/^Código\s*:/i.test(line));
    const amountLine=lines.find(line=>isRechargeOffer ? /^Bonificación\s*:/i.test(line) : /^(Bono|Importe)\s*:/i.test(line));
    const minimumLine=lines.find(line=>isRechargeOffer ? /^Recarga mínima\s*:/i.test(line) : /^Saldo mínimo requerido\s*:/i.test(line));
    const amount=amountLine ? amountLine.replace(/^[^:]+:\s*/,'') : '';
    const minimum=minimumLine ? minimumLine.replace(/^[^:]+:\s*/,'') : '';
    const dialogTitle=document.getElementById('bonusBroadcastTitle');
    const gift=document.querySelector('.bonus-broadcast-gift');
    if(dialogTitle) dialogTitle.textContent=isRechargeOffer?'¡Nueva oferta de recarga!':'¡Tienes un bono disponible!';
    if(gift) gift.textContent=isRechargeOffer?'💸':'🎁';
    if(amountBox){amountBox.hidden=!amount;const small=amountBox.querySelector('small');if(small)small.textContent=isRechargeOffer?'Porcentaje de bonificación':'Importe del bono';if(amountBox.querySelector('strong'))amountBox.querySelector('strong').textContent=amount;}
    const minimumBox=document.getElementById('bonusBroadcastMinimum');
    if(minimumBox){minimumBox.hidden=!minimum;const small=minimumBox.querySelector('small');if(small)small.textContent=isRechargeOffer?'Importe mínimo de recarga':'Saldo mínimo requerido para canjear';const value=minimumBox.querySelector('strong');if(value)value.textContent=minimum || 'No especificado';}
    const subtitle=document.getElementById("bonusBroadcastSubtitle");
    if(subtitle) subtitle.textContent=isRechargeOffer?'Promoción nueva de El Radar':(codeLine ? 'Código promocional: '+codeLine.replace(/^Código\s*:\s*/i,'') : 'Una promoción especial de El Radar');
    details.replaceChildren();
    lines.filter(line=>!/^¡Hay un bono promocional/i.test(line) && !/^¡Hay una nueva oferta de recarga/i.test(line) && !/^Canjéalo/i.test(line) && !/^Bono\s*:/i.test(line) && !/^Bonificación\s*:/i.test(line) && !/^Saldo mínimo requerido\s*:/i.test(line) && !/^Recarga mínima\s*:/i.test(line)).forEach((line,index)=>{
        const split=line.indexOf(':');
        const row=document.createElement('div');row.className='bonus-broadcast-detail';row.style.animationDelay=(index*75)+'ms';
        const label=document.createElement('b');const value=document.createElement('span');
        if(split>0){label.textContent=line.slice(0,split)+':';value.textContent=line.slice(split+1).trim();}else{label.textContent='Información';value.textContent=line;}
        row.append(label,value);details.appendChild(row);
    });
    overlay.dataset.notificationId=String(item.ID||'');
    overlay.classList.add('show');overlay.setAttribute('aria-hidden','false');
}
async function closeBonusBroadcastDialog(){
    const overlay=document.getElementById("bonusBroadcastOverlay");if(!overlay)return;
    const id=Number(overlay.dataset.notificationId||0);overlay.classList.remove('show');overlay.setAttribute('aria-hidden','true');overlay.dataset.notificationId='';
    if(id){try{await apiRequest('notification_mark_read',{id});}catch(_){}}
    if(typeof loadNotifications==='function')loadNotifications(false);
}
document.addEventListener('click',event=>{
    if(event.target.closest('#bonusBroadcastClose,#bonusBroadcastOk'))closeBonusBroadcastDialog();
    const overlay=document.getElementById('bonusBroadcastOverlay');if(overlay&&event.target===overlay)closeBonusBroadcastDialog();
});

function renderNotifications(items) {
    if (!notificationsList) return;
    notificationsList.replaceChildren();
    if (!Array.isArray(items) || items.length === 0) {
        const empty = document.createElement("div");
        empty.className = "notifications-empty";
        empty.textContent = "Todavía no tienes notificaciones. Aquí aparecerán los avisos de acceso, cambios de cuenta y solicitudes.";
        notificationsList.appendChild(empty);
        return;
    }
    items.forEach(item => {
        const button = document.createElement("div");
        button.className = "notification-item" + (Number(item.LEIDA) === 0 ? " unread" : "");
        button.dataset.type = String(item.TIPO || "info");
        button.setAttribute("role", "button");
        button.setAttribute("tabindex", "0");
        button.setAttribute("aria-label", "Abrir notificación: " + String(item.TITULO || "Notificación"));
        // Toda la tarjeta abre el contenido asociado; los botones de acción internos
        // conservan su comportamiento independiente.
        const openNotification = async () => {
            const refType = String(item.REFERENCIA_TIPO || "");
            const titleText = String(item.TITULO || "");
            const refId = Number(item.REFERENCIA_ID || 0);
            try {
                if (refType === "solicitud_viaje" && refId > 0 && titleText === "Nueva solicitud de viaje") {
                    await openTripResponseDialog(refId, Number(item.ID || 0));
                } else if (["novedad", "noticia"].includes(refType) && refId > 0) {
                    // Abrir el diálogo completo de la novedad asociada.
                    await showNewsBroadcastDialog(item);
                } else if (
                    ["bono", "oferta_recarga", "recarga"].includes(refType) ||
                    titleText.toLowerCase().includes("bono") ||
                    titleText.toLowerCase().includes("oferta de recarga") ||
                    titleText.toLowerCase().includes("recarga")
                ) {
                    // Bonos y promociones de recarga comparten el diálogo de promociones.
                    showBonusBroadcastDialog(item);
                    if (item.ID) {
                        try { await apiRequest("notification_mark_read", {id:Number(item.ID)}); } catch (_) {}
                    }
                } else {
                    // Las notificaciones con acciones de viaje mantienen sus botones
                    // Aceptar/Rechazar/Iniciar visibles y utilizables.
                    if (item.ID) {
                        try { await apiRequest("notification_mark_read", {id:Number(item.ID)}); } catch (_) {}
                    }
                    button.classList.remove("unread");
                    await loadNotifications(false);
                }
            } catch (error) {
                showToast(error.message || "No se pudo abrir la notificación.");
            }
        };
        button.addEventListener("click", event => {
            if (event.target.closest("button")) return;
            openNotification();
        });
        button.addEventListener("keydown", event => {
            if ((event.key === "Enter" || event.key === " ") && !event.target.closest("button")) {
                event.preventDefault();
                openNotification();
            }
        });
        const icon = document.createElement("span");
        icon.className = "notification-item-icon";
        const contactPhoto = String(item.FOTO_CONTACTO || "").trim();
        if (item.REFERENCIA_TIPO === "solicitud_viaje" && contactPhoto) {
            const avatar = document.createElement("img");
            avatar.src = contactPhoto;
            avatar.alt = "Foto de perfil del otro participante";
            avatar.loading = "lazy";
            avatar.referrerPolicy = "no-referrer";
            avatar.onerror = () => { avatar.remove(); icon.textContent = notificationTypeIcon(item.TIPO); };
            icon.appendChild(avatar);
            icon.classList.add("notification-item-avatar");
        } else {
            icon.textContent = notificationTypeIcon(item.TIPO);
        }
        const copy = document.createElement("span");
        copy.className = "notification-item-copy";
        const title = document.createElement("span");
        title.className = "notification-item-title";
        title.textContent = String(item.TITULO || "Notificación");
        if (Number(item.LEIDA) === 0) {
            const marker = document.createElement("span");
            marker.className = "notification-unread-marker";
            marker.setAttribute("aria-label", "Sin leer");
            title.prepend(marker);
        }
        const message = document.createElement("span");
        message.className = "notification-item-message";
        message.textContent = String(item.MENSAJE || "");
        const date = document.createElement("span");
        date.className = "notification-item-date";
        date.textContent = formatNotificationDate(item.CREADA_EN);
        copy.append(title, message, date);
        button.append(icon, copy);
        if (item.REFERENCIA_TIPO === "solicitud_viaje" && Number(item.REFERENCIA_ID) > 0 && String(item.TITULO || "") === "Nueva solicitud de viaje") {
            const actions = document.createElement("div");
            actions.className = "trip-notification-actions";
            const pending = String(item.SOLICITUD_ESTADO || "") === "pendiente";
            const respond = document.createElement("button");
            respond.type = "button"; respond.className = "trip-notification-respond"; respond.textContent = pending ? "Responder" : "Solicitud atendida";
            respond.disabled = !pending;
            if (pending) respond.addEventListener("click", () => openTripResponseDialog(Number(item.REFERENCIA_ID), Number(item.ID)));
            const reject = document.createElement("button");
            reject.type = "button"; reject.className = "trip-notification-reject"; reject.textContent = pending ? "Rechazar" : "Solicitud atendida";
            reject.disabled = !pending;
            if (pending) reject.addEventListener("click", async () => {
                respond.disabled = true; reject.disabled = true;
                try { const result = await apiRequest("trip_request_reject", {solicitud_id:Number(item.REFERENCIA_ID)}); closeNotificationCenter(); showToast(result.message || "Solicitud rechazada."); await loadNotifications(true); }
                catch (error) { showToast(error.message || "No se pudo rechazar la solicitud."); respond.disabled = false; reject.disabled = false; }
            });
            actions.append(respond, reject);
            button.appendChild(actions);
        } else if (item.REFERENCIA_TIPO === "solicitud_viaje" && Number(item.REFERENCIA_ID) > 0 && String(item.TITULO || "") === "Precio del viaje recibido") {
            const actions = document.createElement("div");
            actions.className = "trip-notification-actions";
            const awaitingDecision = String(item.SOLICITUD_ESTADO || "") === "respondida";
            const accept = document.createElement("button");
            accept.type = "button"; accept.className = "trip-notification-respond"; accept.textContent = awaitingDecision ? "Aceptar" : "Respuesta enviada"; accept.disabled = !awaitingDecision;
            const decline = document.createElement("button");
            decline.type = "button"; decline.className = "trip-notification-reject"; decline.textContent = awaitingDecision ? "Rechazar" : "Respuesta enviada"; decline.disabled = !awaitingDecision;
            const sendDecision = async (decision) => {
                accept.disabled = true; decline.disabled = true;
                try {
                    const result = await apiRequest("trip_request_client_decision", {solicitud_id:Number(item.REFERENCIA_ID), decision});
                    closeNotificationCenter();
                    showToast(result.message || "Tu respuesta fue enviada al transportista.");
                    await loadNotifications(true);
                } catch (error) {
                    showToast(error.message || "No se pudo enviar tu respuesta.");
                    accept.disabled = false; decline.disabled = false;
                }
            };
            if (awaitingDecision) {
                accept.addEventListener("click", () => sendDecision("aceptar"));
                decline.addEventListener("click", () => sendDecision("rechazar"));
            }
            actions.append(accept, decline);
            button.appendChild(actions);
        } else if (item.REFERENCIA_TIPO === "solicitud_viaje" && Number(item.REFERENCIA_ID) > 0 && String(item.TITULO || "") === "Cliente aceptó el precio") {
            const actions = document.createElement("div");
            actions.className = "trip-notification-actions";
            const tripState = String(item.SOLICITUD_ESTADO || "").toLowerCase();
            const canOpenTrip = ["aceptada", "en_curso", "llegado"].includes(tripState);
            const tripAlreadyArrived = tripState === "llegado";
            const start = document.createElement("button");
            start.type = "button"; start.className = "trip-notification-respond"; start.textContent = tripState === "aceptada" ? "Iniciar viaje" : (tripAlreadyArrived ? "Continuar / finalizar viaje" : "Continuar viaje"); start.disabled = !canOpenTrip;
            if (canOpenTrip) start.addEventListener("click", async () => {
                start.disabled = true;
                try {
                    const result = await apiRequest("trip_start_details", {solicitud_id:Number(item.REFERENCIA_ID)});
                    await openTripNavigationMap(result.viaje || {});
                    try { await apiRequest("notification_mark_read", {id:Number(item.ID)}); } catch (_) {}
                    closeNotificationCenter();
                    await loadNotifications(true);
                } catch (error) { showToast(error.message || "No se pudo iniciar el viaje."); start.disabled = false; }
            });
            actions.appendChild(start);
            button.appendChild(actions);
        } else {
            button.addEventListener("click", async () => {
                if (Number(item.LEIDA) === 1) return;
                button.classList.remove("unread");
                try { await apiRequest("notification_mark_read", {id:Number(item.ID)}); await loadNotifications(true); }
                catch (error) { showToast(error.message || "No se pudo marcar la notificación como leída."); }
            });
        }
        notificationsList.appendChild(button);
    });
}
async function openTripResponseDialog(requestId, notificationId) {
    const overlay = document.getElementById("tripResponseOverlay");
    const content = document.getElementById("tripResponseDetails");
    const form = document.getElementById("tripResponseForm");
    if (!overlay || !content || !form) return;
    content.textContent = "Cargando datos del viaje…";
    form.dataset.requestId = String(requestId);
    form.dataset.notificationId = String(notificationId || "");
    document.getElementById("tripResponsePrice").value = "";
    overlay.classList.add("show"); overlay.setAttribute("aria-hidden", "false");
    try {
        const result = await apiRequest("trip_request_details", {solicitud_id:requestId});
        const r = result.solicitud || {};
        content.replaceChildren();
        const rows = [["Cliente", `${r.NOMBRE || ""} ${r.APELLIDOS || ""}`.trim() || "No indicado"], ["Destino", r.DESTINO || "No indicado"], ["Personas", String(r.PERSONAS || "1")], ["Punto de recogida", r.ORIGEN_DIRECCION || "No indicado"], ["Observaciones", r.OBSERVACIONES || "Ninguna"]];
        rows.forEach(([label,value]) => { const line=document.createElement("p"); const strong=document.createElement("strong"); strong.textContent=label+": "; line.append(strong,document.createTextNode(value)); content.appendChild(line); });
        if (r.ESTADO !== "pendiente") { document.getElementById("tripResponseSubmit").disabled = true; showToast("Esta solicitud ya fue atendida."); }
        else document.getElementById("tripResponseSubmit").disabled = false;
        if (notificationId) { try { await apiRequest("notification_mark_read", {id:notificationId}); } catch (_) {} }
    } catch (error) { content.textContent = error.message || "No se pudieron cargar los datos del viaje."; }
}
function closeTripResponseDialog() {
    const overlay=document.getElementById("tripResponseOverlay");
    if (overlay) { overlay.classList.remove("show"); overlay.setAttribute("aria-hidden","true"); }
}
document.addEventListener("click", event => {
    if (event.target.closest("#tripResponseClose, #tripResponseCancel")) closeTripResponseDialog();
    const overlay=document.getElementById("tripResponseOverlay");
    if (overlay && event.target===overlay) closeTripResponseDialog();
});
document.addEventListener("submit", async event => {
    const form=event.target;
    if (!form || form.id!=="tripResponseForm") return;
    event.preventDefault();
    const submit=document.getElementById("tripResponseSubmit");
    if (!submit || submit.disabled) return;
    submit.disabled=true; submit.textContent="Enviando precio…";
    let sentSuccessfully = false;
    try {
        const result=await apiRequest("trip_request_respond", {solicitud_id:Number(form.dataset.requestId), precio:document.getElementById("tripResponsePrice").value});
        sentSuccessfully = true;
        closeTripResponseDialog(); closeNotificationCenter(); showToast(result.message || "Precio enviado al cliente."); await loadNotifications(true);
    } catch(error) { showToast(error.message || "No se pudo enviar el precio."); }
    finally { submit.disabled=sentSuccessfully; submit.textContent=sentSuccessfully ? "Precio enviado" : "Enviar precio al cliente"; }
});

let tripNavigationMap = null;
let tripNavigationRouteLayer = null;
let tripNavigationTransporterMarker = null;
let tripNavigationClientMarker = null;
let tripLiveLocationsTimer = null;
let tripLiveLocationsBusy = false;
let tripArrivalWatchId = null;
let activeTripNavigationId = 0;
let tripArrivalCheckInFlight = false;
let lastTripArrivalCheckAt = 0;

let tripScreenWakeLock = null;
let tripScreenWakeLockWanted = false;
async function requestTripScreenWakeLock() {
    if (!tripScreenWakeLockWanted || document.visibilityState !== "visible" || !("wakeLock" in navigator)) return;
    try {
        if (tripScreenWakeLock && !tripScreenWakeLock.released) return;
        tripScreenWakeLock = await navigator.wakeLock.request("screen");
        tripScreenWakeLock.addEventListener("release", () => { tripScreenWakeLock = null; });
    } catch (error) {
        console.warn("No se pudo mantener la pantalla encendida durante el viaje:", error);
    }
}
function keepScreenOnDuringTrip() {
    tripScreenWakeLockWanted = true;
    requestTripScreenWakeLock();
}
function releaseTripScreenWakeLock() {
    tripScreenWakeLockWanted = false;
    if (tripScreenWakeLock) {
        const lock = tripScreenWakeLock;
        tripScreenWakeLock = null;
        try { lock.release(); } catch (_) {}
    }
}
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") requestTripScreenWakeLock();
});
async function checkTripArrivalPosition(position) {
    if (!activeTripNavigationId || tripArrivalCheckInFlight || !position || !position.coords) return;
    const lat = Number(position.coords.latitude), lng = Number(position.coords.longitude);
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
    if (tripNavigationTransporterMarker && tripNavigationMap) tripNavigationTransporterMarker.setLatLng([lat, lng]);
    const now = Date.now();
    if (now - lastTripArrivalCheckAt < 750) return;
    lastTripArrivalCheckAt = now;
    tripArrivalCheckInFlight = true;
    try {
        const result = await apiRequest("trip_arrival_check", {
            solicitud_id:activeTripNavigationId,
            lat, lng,
            accuracy:Number(position.coords.accuracy || 0)
        });
        if (result.llegado) {
            if (tripArrivalWatchId !== null && navigator.geolocation) {
                try { navigator.geolocation.clearWatch(tripArrivalWatchId); } catch (_) {}
            }
            tripArrivalWatchId = null;
            const status = document.getElementById("tripNavigationStatus");
            const finishButton = document.getElementById("tripNavigationFinish");
            if (status) status.textContent = "¡Has llegado al punto de recogida! El cliente fue notificado. Continúa el servicio y pulsa «Finalizar viaje» cuando llegues al destino.";
            if (finishButton) finishButton.hidden = false;
            showToast("¡Has llegado al punto de recogida! Sigues ocupado hasta finalizar el viaje.");
            refreshNearbyTransportistas(true);
        }
    } catch (error) {
        console.warn("No se pudo comprobar la llegada del viaje:", error.message || error);
    } finally { tripArrivalCheckInFlight = false; }
}
function startTripArrivalTracking(tripId) {
    activeTripNavigationId = Number(tripId) || 0;
    lastTripArrivalCheckAt = 0;
    if (!activeTripNavigationId || !navigator.geolocation) return;
    if (tripArrivalWatchId !== null) { try { navigator.geolocation.clearWatch(tripArrivalWatchId); } catch (_) {} }
    tripArrivalWatchId = navigator.geolocation.watchPosition(
        checkTripArrivalPosition,
        error => { const status=document.getElementById("tripNavigationStatus"); if (status && error && error.code===1) status.textContent="Permite el GPS para detectar automáticamente la llegada dentro de la distancia configurada por el cliente (5 a 20 metros)."; },
        {enableHighAccuracy:true, maximumAge:0, timeout:10000}
    );
    if (lastGpsPosition) checkTripArrivalPosition({coords:{latitude:lastGpsPosition.lat, longitude:lastGpsPosition.lng, accuracy:lastGpsPosition.accuracy || 0}});
}
async function refreshTripLiveLocations() {
    if (!activeTripNavigationId || tripLiveLocationsBusy || !currentRadarUser) return;
    tripLiveLocationsBusy = true;
    try {
        const data = await apiRequest('trip_live_locations', {solicitud_id:activeTripNavigationId});
        const t = data.transportista, c = data.cliente;
        if (t && tripNavigationMap) {
            const point = [Number(t.lat), Number(t.lng)];
            if (point.every(Number.isFinite)) {
                if (tripNavigationTransporterMarker) tripNavigationTransporterMarker.setLatLng(point);
                else tripNavigationTransporterMarker = L.marker(point).addTo(tripNavigationMap).bindPopup('Ubicación del transportista');
            }
        }
        if (c && tripNavigationMap) {
            const point = [Number(c.lat), Number(c.lng)];
            if (point.every(Number.isFinite)) {
                if (tripNavigationClientMarker) tripNavigationClientMarker.setLatLng(point);
                else tripNavigationClientMarker = L.marker(point).addTo(tripNavigationMap).bindPopup('Ubicación del cliente');
            }
        }
        const status = document.getElementById('tripNavigationStatus');
        if (status && (t || c)) status.dataset.liveUpdated = new Date().toLocaleTimeString();
    } catch (error) {
        console.warn('No se pudieron actualizar las ubicaciones del viaje:', error.message || error);
    } finally { tripLiveLocationsBusy = false; }
}
function startTripLiveLocations() {
    if (tripLiveLocationsTimer) clearInterval(tripLiveLocationsTimer);
    refreshTripLiveLocations();
    tripLiveLocationsTimer = setInterval(refreshTripLiveLocations, 5000);
}

async function openTripNavigationMap(trip) {
    const overlay = document.getElementById("tripNavigationOverlay");
    const mapNode = document.getElementById("tripNavigationMap");
    const status = document.getElementById("tripNavigationStatus");
    const info = document.getElementById("tripNavigationInfo");
    if (!overlay || !mapNode || !status || !info) throw new Error("No se encontró el mapa de navegación.");
    info.textContent = `Viaje #${trip.id || ""} · Cliente: ${trip.cliente || "No indicado"} · Destino: ${trip.destino || "No indicado"} · Recogida: ${trip.recogida || "No indicada"}`;
    activeTripNavigationId = Number(trip.id) || 0;
    const finishButton = document.getElementById("tripNavigationFinish");
    const alreadyAtPickup = String(trip.estado || "").toLowerCase() === "llegado";
    if (finishButton) finishButton.hidden = !alreadyAtPickup;
    if (alreadyAtPickup && status) status.textContent = "Ya llegaste al punto de recogida. El transportista continúa ocupado hasta que pulses «Finalizar viaje» al completar el servicio.";
    keepScreenOnDuringTrip();
    overlay.classList.add("show"); overlay.setAttribute("aria-hidden", "false");
    status.textContent = "Preparando el mapa y la ruta hacia el cliente…";
    if (!window.L) { status.textContent = "No se pudo cargar el servicio de mapas. Comprueba tu conexión."; return; }
    if (tripNavigationMap) { tripNavigationMap.remove(); tripNavigationMap = null; }
    tripNavigationTransporterMarker = null;
    tripNavigationClientMarker = null;
    tripNavigationMap = L.map(mapNode, {zoomControl:true}).setView([39.5,-98.35], 5);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {maxZoom:19, attribution:"© OpenStreetMap"}).addTo(tripNavigationMap);
    const tLat = Number(trip.transportista_lat), tLng = Number(trip.transportista_lng);
    const cLat = Number(trip.cliente_lat), cLng = Number(trip.cliente_lng);
    const validT = Number.isFinite(tLat) && Number.isFinite(tLng) && Math.abs(tLat)<=90 && Math.abs(tLng)<=180;
    const validC = Number.isFinite(cLat) && Number.isFinite(cLng) && Math.abs(cLat)<=90 && Math.abs(cLng)<=180;
    const bounds = [];
    if (validT) {
        const vehiclePhoto = validMapPhoto(trip.transportista_foto_vehiculo);
        const vehicleHtml = vehiclePhoto
            ? '<div class="trip-nav-photo-marker vehicle"><img src="' + vehiclePhoto + '" alt="Vehículo del transportista"></div>'
            : '<div class="trip-nav-photo-marker vehicle"><span aria-hidden="true">🚕</span></div>';
        const vehicleIcon = L.divIcon({className:"trip-nav-div-icon", html:vehicleHtml, iconSize:[58,58], iconAnchor:[29,29]});
        tripNavigationTransporterMarker = L.marker([tLat,tLng], {icon:vehicleIcon}).addTo(tripNavigationMap).bindPopup("Tu ubicación (transportista)").openPopup();
        bounds.push([tLat,tLng]);
    }
    if (validC) {
        const clientPhoto = validMapPhoto(trip.cliente_foto);
        const clientHtml = clientPhoto
            ? '<div class="trip-nav-photo-marker client"><img src="' + clientPhoto + '" alt="Foto de perfil del cliente"></div>'
            : '<div class="trip-nav-photo-marker client"><span aria-hidden="true">👤</span></div>';
        const clientIcon = L.divIcon({className:"trip-nav-div-icon", html:clientHtml, iconSize:[58,58], iconAnchor:[29,29]});
        tripNavigationClientMarker = L.marker([cLat,cLng], {icon:clientIcon}).addTo(tripNavigationMap).bindPopup("Ubicación del cliente / punto de recogida");
        bounds.push([cLat,cLng]);
    }
    if (bounds.length) tripNavigationMap.fitBounds(bounds, {padding:[35,35], maxZoom:15});
    setTimeout(() => tripNavigationMap && tripNavigationMap.invalidateSize(), 180);
    if (!alreadyAtPickup) startTripArrivalTracking(trip.id);
    startTripLiveLocations();
    if (!validT || !validC) {
        status.textContent = !validT && !validC ? "No hay coordenadas GPS disponibles para ninguno de los dos. Mantén el GPS activo en ambos dispositivos y vuelve a abrir el mapa." : (!validT ? "No se recibió la ubicación GPS actual del transportista. Activa el GPS y vuelve a intentarlo." : "La solicitud no guardó la ubicación GPS del cliente. Se muestra tu ubicación; el cliente deberá compartir su ubicación para calcular la ruta.");
        return;
    }
    try {
        const url = `https://router.project-osrm.org/route/v1/driving/${encodeURIComponent(tLng+","+tLat)};${encodeURIComponent(cLng+","+cLat)}?overview=full&geometries=geojson`;
        const response = await fetch(url, {headers:{"Accept":"application/json"}});
        if (!response.ok) throw new Error("Servicio de rutas no disponible");
        const data = await response.json();
        if (!data.routes || !data.routes.length) throw new Error("No se encontró una ruta por carretera.");
        const route = data.routes[0];
        tripNavigationRouteLayer = L.geoJSON(route.geometry, {style:{color:"#1769e0",weight:6,opacity:.88}}).addTo(tripNavigationMap);
        tripNavigationMap.fitBounds(tripNavigationRouteLayer.getBounds(), {padding:[35,35]});
        const km = (route.distance/1000).toFixed(1);
        const mins = Math.max(1, Math.round(route.duration/60));
        status.textContent = `Ruta hacia el cliente: ${km} km · aproximadamente ${mins} min en automóvil. Sigue la ruta azul del mapa.`;
    } catch (error) {
        status.textContent = "Se muestran ambas ubicaciones, pero no se pudo calcular la ruta automáticamente. Comprueba tu conexión e inténtalo de nuevo.";
    }
}
async function finishActiveTrip() {
    const button = document.getElementById("tripNavigationFinish");
    if (!activeTripNavigationId) { showToast("No hay un viaje activo para finalizar."); return; }
    if (button) { button.disabled = true; button.textContent = "Finalizando…"; }
    try {
        const result = await apiRequest("trip_finish", {solicitud_id:activeTripNavigationId});
        const finishedId = activeTripNavigationId;
        activeTripNavigationId = 0;
        if (tripArrivalWatchId !== null && navigator.geolocation) { try { navigator.geolocation.clearWatch(tripArrivalWatchId); } catch (_) {} }
        tripArrivalWatchId = null;
        if (tripLiveLocationsTimer) { clearInterval(tripLiveLocationsTimer); tripLiveLocationsTimer = null; }
        closeTripNavigationMap();
        releaseTripScreenWakeLock();
        if (button) { button.hidden = true; button.disabled = false; button.textContent = "Finalizar viaje"; }
        showToast(result.message || "Viaje finalizado. Ya estás disponible.");
        refreshNearbyTransportistas(true);
    } catch (error) {
        if (button) { button.disabled = false; button.textContent = "Finalizar viaje"; }
        showToast(error.message || "No se pudo finalizar el viaje.");
    }
}
const tripNavigationFinishButton = document.getElementById("tripNavigationFinish");
if (tripNavigationFinishButton) tripNavigationFinishButton.addEventListener("click", finishActiveTrip);

function closeTripNavigationMap() {
    const overlay=document.getElementById("tripNavigationOverlay");
    if (overlay) { overlay.classList.remove("show"); overlay.setAttribute("aria-hidden","true"); }
    // Cerrar el mapa no debe cancelar el GPS: el detector sigue activo durante el viaje.
    // Solo se detiene cuando el servidor confirma que el transportista ya llegó.
    if (tripLiveLocationsTimer) { clearInterval(tripLiveLocationsTimer); tripLiveLocationsTimer = null; }
    tripLiveLocationsBusy = false;
    tripNavigationClientMarker = null;
    tripNavigationTransporterMarker = null;
    if (tripNavigationMap) { tripNavigationMap.remove(); tripNavigationMap=null; tripNavigationRouteLayer=null; }
}
document.addEventListener("click", event => {
    if (event.target.closest("#tripNavigationClose, #tripNavigationDone")) closeTripNavigationMap();
    const overlay=document.getElementById("tripNavigationOverlay");
    if (overlay && event.target===overlay) closeTripNavigationMap();
});

function showEditorSalaryPaidDialog(item){
    if(!item)return;
    let overlay=document.getElementById('editorSalaryPaidOverlay');
    if(!overlay){
        const style=document.createElement('style');style.textContent='@keyframes editorSalaryPop{0%{opacity:0;transform:translateY(22px) scale(.92)}70%{transform:translateY(-4px) scale(1.015)}100%{opacity:1;transform:translateY(0) scale(1)}}@keyframes editorSalaryGlow{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.18)}50%{box-shadow:0 0 0 13px rgba(16,185,129,0)}}.editor-salary-overlay{position:fixed;inset:0;z-index:11020;display:flex;align-items:center;justify-content:center;padding:18px;background:rgba(8,20,43,.72);backdrop-filter:blur(8px)}.editor-salary-dialog{width:min(100%,430px);padding:28px 24px;border-radius:25px;background:linear-gradient(155deg,#fff 0%,#f1fff7 100%);text-align:center;box-shadow:0 28px 90px rgba(0,0,0,.32);animation:editorSalaryPop .55s cubic-bezier(.2,.85,.25,1)}.editor-salary-gift{width:82px;height:82px;margin:0 auto 14px;border-radius:26px;display:grid;place-items:center;font-size:42px;background:linear-gradient(145deg,#d1fae5,#a7f3d0);animation:editorSalaryGlow 2s infinite}.editor-salary-dialog h2{margin:0;color:#123b2b;font-size:23px;font-weight:950}.editor-salary-dialog p{color:#557365;font-size:13px;line-height:1.55}.editor-salary-amount{margin:18px 0;padding:17px;border-radius:18px;background:linear-gradient(120deg,#087443,#16a36a);color:#fff}.editor-salary-amount small{display:block;font-size:10px;font-weight:850;letter-spacing:.1em}.editor-salary-amount strong{display:block;margin-top:4px;font-size:clamp(27px,8vw,36px);font-weight:950}.editor-salary-info{padding:12px;border:1px solid #dceee4;border-radius:13px;background:#fff;text-align:left;font-size:12px;color:#345b49;overflow-wrap:anywhere}.editor-salary-info div+div{margin-top:8px}.editor-salary-close{width:100%;margin-top:18px;padding:13px;border:0;border-radius:13px;background:#123f2e;color:#fff;font-weight:900;cursor:pointer}';document.head.appendChild(style);
        overlay=document.createElement('div');overlay.id='editorSalaryPaidOverlay';overlay.className='editor-salary-overlay';overlay.setAttribute('role','dialog');overlay.setAttribute('aria-modal','true');
        const dialog=document.createElement('section');dialog.className='editor-salary-dialog';
        const gift=document.createElement('div');gift.className='editor-salary-gift';gift.textContent='💸';
        const title=document.createElement('h2');title.textContent='¡Tu pago ha sido realizado!';
        const subtitle=document.createElement('p');subtitle.textContent='La Administración ha registrado el pago de tus ganancias como Editor.';
        const amount=document.createElement('div');amount.className='editor-salary-amount';const amountLabel=document.createElement('small');amountLabel.textContent='IMPORTE RECIBIDO';const amountValue=document.createElement('strong');amountValue.id='editorSalaryPaidAmount';amount.append(amountLabel,amountValue);
        const info=document.createElement('div');info.className='editor-salary-info';info.id='editorSalaryPaidInfo';
        const close=document.createElement('button');close.type='button';close.className='editor-salary-close';close.textContent='¡Excelente!';close.addEventListener('click',closeEditorSalaryPaidDialog);
        dialog.append(gift,title,subtitle,amount,info,close);overlay.appendChild(dialog);overlay.addEventListener('click',e=>{if(e.target===overlay)closeEditorSalaryPaidDialog();});document.body.appendChild(overlay);
    }
    const lines=String(item.MENSAJE||'').split(/\r?\n/).map(x=>x.trim()).filter(Boolean);
    const valueFor=label=>{const line=lines.find(x=>x.toLowerCase().startsWith(label.toLowerCase()+':'));return line?line.slice(line.indexOf(':')+1).trim():'—';};
    document.getElementById('editorSalaryPaidAmount').textContent=valueFor('Importe');
    const info=document.getElementById('editorSalaryPaidInfo');info.replaceChildren();
    [['Número de transferencia',valueFor('ID de transferencia')],['Tarjeta destino',valueFor('Tarjeta destino')],['Fecha',valueFor('Fecha')]].forEach(([label,value])=>{const row=document.createElement('div');const b=document.createElement('b');b.textContent=label+': ';row.append(b,document.createTextNode(value));info.appendChild(row);});
    overlay.dataset.notificationId=String(item.ID||'');overlay.classList.add('show');document.body.style.overflow='hidden';
}
async function closeEditorSalaryPaidDialog(){const overlay=document.getElementById('editorSalaryPaidOverlay');if(!overlay)return;const id=Number(overlay.dataset.notificationId||0);overlay.remove();document.body.style.overflow='';if(id){try{await apiRequest('notification_mark_read',{id});}catch(_){}}if(typeof loadNotifications==='function')loadNotifications(false);}

async function loadNotifications(renderList = false) {
    if (!currentRadarUser || notificationsLoading) return;
    notificationsLoading = true;
    try {
        const result = await apiRequest("notifications_list", {});
        const incomingNotifications = Array.isArray(result.notificaciones) ? result.notificaciones : [];
        if (String(currentRadarUser.tipo || "").toLowerCase() !== "transportista") {
            const tripState = incomingNotifications
                .filter(item => String(item.REFERENCIA_TIPO || "") === "solicitud_viaje"
                    && ["Viaje iniciado", "Tu transporte ha llegado"].includes(String(item.TITULO || "")))
                .sort((a, b) => Number(b.ID || 0) - Number(a.ID || 0))[0];
            if (tripState && String(tripState.TITULO) === "Viaje iniciado") keepScreenOnDuringTrip();
            else if (tripState && String(tripState.TITULO) === "Tu transporte ha llegado") releaseTripScreenWakeLock();
        }
        const newestId = incomingNotifications.reduce((maxId, item) => Math.max(maxId, Number(item.ID) || 0), 0);
        if (!notificationBaselineReady) {
            // Primera consulta: no reproducir sonidos antiguos, salvo una alerta de llegada aún sin leer.
            latestKnownNotificationId = newestId;
            notificationBaselineReady = true;
            const pendingSalary = incomingNotifications.find(item => Number(item.LEIDA) === 0 && String(item.REFERENCIA_TIPO || "") === "editor_salary_paid");
            const pendingArrival = incomingNotifications.find(item => Number(item.LEIDA) === 0 && String(item.TITULO || "") === "Tu transporte ha llegado");
            const pendingNews = incomingNotifications.find(item => Number(item.LEIDA) === 0 && String(item.REFERENCIA_TIPO || "") === "novedad");
            const pendingBonus = incomingNotifications.find(item => Number(item.LEIDA) === 0 && (String(item.TITULO || "").toLowerCase().includes("bono") || String(item.TITULO || "").toLowerCase().includes("oferta de recarga") || String(item.TITULO || "").toLowerCase().includes("recarga") || ["bono","oferta_recarga","recarga"].includes(String(item.REFERENCIA_TIPO || ""))));
            if (pendingSalary && document.visibilityState === "visible") showEditorSalaryPaidDialog(pendingSalary);
            else if (pendingNews && document.visibilityState === "visible") showNewsBroadcastDialog(pendingNews);
            else if (pendingBonus && document.visibilityState === "visible") showBonusBroadcastDialog(pendingBonus);
            else if (pendingArrival && String(currentRadarUser.tipo || "").toLowerCase() !== "transportista") showTripArrivalAlert(pendingArrival);
        } else if (newestId > latestKnownNotificationId) {
            const previousKnownNotificationId = latestKnownNotificationId;
            const hasNewUnread = incomingNotifications.some(item => (Number(item.ID) || 0) > previousKnownNotificationId && Number(item.LEIDA) === 0);
            const newestUnread = incomingNotifications.find(item => (Number(item.ID) || 0) > previousKnownNotificationId && Number(item.LEIDA) === 0);
            const newestSalaryPaid = incomingNotifications.find(item => (Number(item.ID) || 0) > previousKnownNotificationId && Number(item.LEIDA) === 0 && String(item.REFERENCIA_TIPO || "") === "editor_salary_paid");
            latestKnownNotificationId = newestId;
            // Solo sonido del navegador. Si el audio está bloqueado, el aviso queda pendiente
            // hasta la próxima interacción del usuario con la página.
            if (hasNewUnread && document.visibilityState === "visible") {
                if (newestSalaryPaid) {
                    showEditorSalaryPaidDialog(newestSalaryPaid);
                } else if (newestUnread && String(newestUnread.REFERENCIA_TIPO || "") === "novedad") {
                    showNewsBroadcastDialog(newestUnread);
                } else if (newestUnread && (String(newestUnread.TITULO || "").toLowerCase().includes("bono") || String(newestUnread.TITULO || "").toLowerCase().includes("oferta de recarga") || String(newestUnread.TITULO || "").toLowerCase().includes("recarga") || ["bono","oferta_recarga","recarga"].includes(String(newestUnread.REFERENCIA_TIPO || "")))) {
                    showBonusBroadcastDialog(newestUnread);
                } else if (newestUnread && String(newestUnread.TITULO || "") === "Tu transporte ha llegado" && String(currentRadarUser.tipo || "").toLowerCase() !== "transportista") {
                    showTripArrivalAlert(newestUnread);
                } else {
                    playSmallBellNotificationSound();
                }
                // La notificación del sistema la entrega exclusivamente el evento push del Service Worker.
                // No crear otra desde el sondeo de la página: eso provocaba avisos duplicados.
            }
        }
        updateNotificationBadge(result.no_leidas);
        const totalValue = document.getElementById("notificationTotalValue");
        if (totalValue) totalValue.textContent = Number(result.total || 0).toLocaleString("es-MX");
        if (renderList || (notificationsOverlay && !notificationsOverlay.hidden)) {
            renderNotifications(result.notificaciones || []);
            if (notificationsSummary) {
                const unread = Number(result.no_leidas || 0);
                notificationsSummary.textContent = unread ? `${unread.toLocaleString("es-MX")} notificación(es) sin leer · se muestran las 100 más recientes como máximo.` : "Estás al día · se muestran las 100 notificaciones más recientes como máximo.";
            }
        }
    } catch (error) {
        if (renderList && notificationsSummary) notificationsSummary.textContent = error.message || "No se pudieron cargar las notificaciones.";
        if (renderList) showToast(error.message || "No se pudieron cargar las notificaciones.");
    } finally {
        notificationsLoading = false;
    }
}
function startNotificationSync() {
    if (notificationSyncTimer) clearInterval(notificationSyncTimer);
    loadNotifications(false);
    // Consulta cada 5 segundos cuando la página está visible. Los navegadores pueden
    // suspender temporizadores y audio si la aplicación está minimizada.
    notificationSyncTimer = setInterval(() => {
        if (currentRadarUser && document.visibilityState === "visible") loadNotifications(false);
    }, 5000);
}
document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && currentRadarUser) loadNotifications(false);
});
function stopNotificationSync() {
    if (notificationSyncTimer) clearInterval(notificationSyncTimer);
    notificationSyncTimer = null;
    notificationBaselineReady = false;
    latestKnownNotificationId = 0;
    updateNotificationBadge(0);
    if (notificationsOverlay) notificationsOverlay.hidden = true;
}
function closeNotificationCenter() {
    if (notificationsOverlay) notificationsOverlay.hidden = true;
}

document.getElementById("notificationButton").addEventListener("click", async () => {
    if (!currentRadarUser) { showToast("Inicia sesión para consultar tus notificaciones."); return; }
    notificationsOverlay.hidden = false;
    if (notificationsList) notificationsList.innerHTML = '<div class="notifications-empty">Cargando notificaciones…</div>';
    if (notificationsSummary) notificationsSummary.textContent = "Actualizando…";
    try {
        // Al abrir el centro de notificaciones, marcar como leídas todas las del usuario actual.
        const result = await apiRequest("notifications_mark_all_read", {});
        updateNotificationBadge(result.no_leidas ?? 0);
        await loadNotifications(true);
    } catch (error) {
        showToast(error.message || "No se pudieron marcar las notificaciones como leídas.");
        await loadNotifications(true);
    }
});
document.getElementById("notificationsClose").addEventListener("click", closeNotificationCenter);
document.getElementById("notificationsBackdrop").addEventListener("click", closeNotificationCenter);
// Sin registro de service worker ni suscripciones push: el sonido se reproduce en la página.
document.getElementById("markAllNotificationsRead").addEventListener("click", async () => {
    try {
        const result = await apiRequest("notifications_mark_all_read", {});
        updateNotificationBadge(result.no_leidas);
        await loadNotifications(true);
        showToast(result.message || "Notificaciones actualizadas.");
    } catch (error) { showToast(error.message || "No se pudieron actualizar las notificaciones."); }
});
document.addEventListener("keydown", event => { if (event.key === "Escape" && notificationsOverlay && !notificationsOverlay.hidden) closeNotificationCenter(); });

let editorPaymentsCache = [];
function loadEditorPayments() {
    const status=document.getElementById('editorPaymentsStatus');
    const list=document.getElementById('editorPaymentsCards');
    if(!status||!list||!isAdminUser(currentRadarUser))return;
    status.textContent='Cargando Editores…'; list.replaceChildren();
    apiRequest('admin_editor_payments_list',{}).then(result=>{
        editorPaymentsCache=Array.isArray(result.editores)?result.editores:[];
        const summary=result.resumen||{};
        document.getElementById('editorPaymentsEarned').textContent=formatFinanceMoney(summary.ganancias_acumuladas||0);
        document.getElementById('editorPaymentsPaid').textContent=formatFinanceMoney(summary.total_pagado||0);
        document.getElementById('editorPaymentsPending').textContent=formatFinanceMoney(summary.pendiente_pago||0);
        renderEditorPaymentsCards();
        status.textContent=editorPaymentsCache.length+' Editor(es) encontrado(s). Porcentaje actual: '+Number(result.porcentaje_editor||0).toLocaleString('es-MX')+'%.';
    }).catch(error=>{status.textContent=error.message||'No se pudo cargar el listado de Editores.';});
}
function renderEditorPaymentsCards(){
    const list=document.getElementById('editorPaymentsCards'); if(!list)return; list.replaceChildren();
    if(!editorPaymentsCache.length){const empty=document.createElement('div');empty.className='card';empty.textContent='Todavía no hay usuarios con el permiso de Editor activado.';list.appendChild(empty);return;}
    editorPaymentsCache.forEach(editor=>{
        const card=document.createElement('article');card.className='editor-payment-card';
        const head=document.createElement('div');head.className='editor-payment-card-head';
        const photo=document.createElement('img');photo.className='editor-payment-avatar';photo.alt='Foto de perfil';photo.src=isEmbeddedRadarImage(editor.FOTO)?editor.FOTO:'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"%3E%3Crect width="80" height="80" rx="40" fill="%23e5ecf8"/%3E%3Ccircle cx="40" cy="28" r="14" fill="%2396a7c2"/%3E%3Cpath d="M12 76c2-18 13-27 28-27s26 9 28 27" fill="%2396a7c2"/%3E%3C/svg%3E';
        const identity=document.createElement('div');identity.className='editor-payment-identity';
        const name=document.createElement('h3');name.textContent=(`${editor.NOMBRE||''} ${editor.APELLIDOS||''}`).trim()||editor.USUARIO||'Editor';
        const username=document.createElement('p');username.textContent='@'+(editor.USUARIO||'');
        const state=document.createElement('span');state.className='editor-payment-status';state.textContent=String(editor.ESTADO||'').toLowerCase()==='activo'?'Cuenta activa':'Cuenta '+(editor.ESTADO||'sin estado');
        identity.append(name,username,state);head.append(photo,identity);
        const cardNumber=document.createElement('div');cardNumber.className='editor-payment-card-number';
        const cardLabel=document.createElement('small');cardLabel.textContent='TARJETA REGISTRADA';
        const cardValue=document.createElement('strong');const digits=String(editor.TARJETA_PAGO||'').replace(/\D/g,'');cardValue.textContent=digits?'•••• •••• •••• '+digits.slice(-4):'No ha registrado tarjeta';
        cardNumber.append(cardLabel,cardValue);
        const metrics=document.createElement('div');metrics.className='editor-payment-metrics';
        [['Ganancias generadas',editor.GANANCIAS_GENERADAS],['Total pagado',editor.TOTAL_PAGADO],['Ganancias acumuladas disponibles',editor.GANANCIAS_ACUMULADAS]].forEach((pair,index)=>{const box=document.createElement('div');box.className='editor-payment-metric'+(index===2?' pending':'');const label=document.createElement('small');label.textContent=pair[0];const value=document.createElement('strong');value.textContent=formatFinanceMoney(pair[1]);box.append(label,value);metrics.appendChild(box);});
        const details=document.createElement('div');details.className='editor-payment-contact';details.textContent=[editor.TELEFONO,editor.CORREO,[editor.MUNICIPIO,editor.PROVINCIA].filter(Boolean).join(', ')].filter(Boolean).join(' · ')||'Sin datos de contacto';
        const action=document.createElement('button');action.type='button';action.className='btn editor-payment-action';action.textContent=Number(editor.PENDIENTE_PAGO)>0?'Registrar pago  →':'Sin ganancias pendientes';action.disabled=Number(editor.PENDIENTE_PAGO)<=0||!editor.TARJETA_CONFIGURADA;
        if(!editor.TARJETA_CONFIGURADA){action.textContent='Esperando tarjeta del Editor';action.disabled=true;}
        action.addEventListener('click',()=>openEditorPaymentDialog(editor));
        card.append(head,cardNumber,metrics,details,action);list.appendChild(card);
    });
}
function openEditorPaymentDialog(editor){
    if(!editor||!editor.TARJETA_CONFIGURADA||Number(editor.PENDIENTE_PAGO)<=0)return;
    const overlay=document.createElement('div');overlay.className='editor-payment-modal';
    const dialog=document.createElement('section');dialog.className='editor-payment-modal-dialog';dialog.setAttribute('role','dialog');dialog.setAttribute('aria-modal','true');
    const title=document.createElement('h3');title.textContent='Registrar pago al Editor';
    const person=document.createElement('p');person.className='editor-payment-modal-person';person.textContent=(`${editor.NOMBRE||''} ${editor.APELLIDOS||''}`).trim()+' · @'+editor.USUARIO;
    const amount=document.createElement('div');amount.className='editor-payment-modal-amount';const amountLabel=document.createElement('small');amountLabel.textContent='IMPORTE QUE SE PAGARÁ';const amountValue=document.createElement('strong');amountValue.textContent=formatFinanceMoney(editor.PENDIENTE_PAGO);amount.append(amountLabel,amountValue);
    const card=document.createElement('p');card.className='editor-payment-modal-card';const digits=String(editor.TARJETA_PAGO||'').replace(/\D/g,'');card.textContent='Tarjeta destino: •••• '+digits.slice(-4);
    const label=document.createElement('label');label.textContent='Número / ID de la transferencia';label.htmlFor='editorPaymentTransferId';
    const input=document.createElement('input');input.id='editorPaymentTransferId';input.type='text';input.maxLength=150;input.autocomplete='off';input.placeholder='Escribe el ID de la transferencia';input.required=true;
    const status=document.createElement('p');status.className='editor-payment-modal-status';status.setAttribute('aria-live','polite');
    const actions=document.createElement('div');actions.className='editor-payment-modal-actions';
    const cancel=document.createElement('button');cancel.type='button';cancel.className='btn btn-secondary';cancel.textContent='Cancelar';
    const save=document.createElement('button');save.type='button';save.className='btn';save.textContent='Confirmar pago';
    const close=()=>{overlay.remove();document.body.style.overflow='';};cancel.addEventListener('click',close);overlay.addEventListener('click',e=>{if(e.target===overlay)close();});
    save.addEventListener('click',async()=>{const transferId=input.value.trim();if(!transferId){status.textContent='Introduce el ID de la transferencia.';input.focus();return;}save.disabled=true;save.textContent='Registrando…';status.textContent='Guardando el pago y enviando la notificación…';try{const result=await apiRequest('admin_editor_payment_register',{editor_id:Number(editor.ID),id_transferencia:transferId});if(result.pago){editor.TOTAL_PAGADO=Number(result.pago.total_pagado||0);editor.GANANCIAS_ACUMULADAS=Number(result.pago.ganancias_acumuladas||0);editor.PENDIENTE_PAGO=Number(result.pago.ganancias_acumuladas||0);}close();showToast(result.message||'Pago registrado correctamente.');loadEditorPayments();}catch(error){status.textContent=error.message||'No se pudo registrar el pago.';save.disabled=false;save.textContent='Confirmar pago';}});
    actions.append(cancel,save);dialog.append(title,person,amount,card,label,input,status,actions);overlay.appendChild(dialog);document.body.appendChild(overlay);document.body.style.overflow='hidden';input.focus();
}
document.getElementById('refreshEditorPayments')?.addEventListener('click',loadEditorPayments);

async function refreshAdminSummary() {
    if (!isAdminUser(currentRadarUser)) return;
    const button = document.getElementById("refreshAdminSummary");
    const status = document.getElementById("adminSummaryUpdated");
    if (button) button.disabled = true;
    if (status) status.textContent = "Actualizando datos…";
    try {
        const result = await apiRequest("admin_summary", {});
        const summary = result.resumen || {};
        document.getElementById("adminTotalUsers").textContent = Number(summary.usuarios || 0).toLocaleString("es-MX");
        document.getElementById("adminActiveUsers").textContent = Number(summary.activos || 0).toLocaleString("es-MX");
        document.getElementById("adminInactiveUsers").textContent = Number(summary.inactivos || 0).toLocaleString("es-MX");
        document.getElementById("adminPendingRequests").textContent = Number(summary.solicitudes_pendientes || 0).toLocaleString("es-MX");
        if (status) status.textContent = "Actualizado " + new Date().toLocaleTimeString("es-MX", {hour:"2-digit", minute:"2-digit", second:"2-digit"});
    } catch (error) {
        if (status) status.textContent = error.message || "No se pudo cargar el resumen de administración.";
        showToast(error.message || "No se pudo cargar el resumen de administración.");
    } finally {
        if (button) button.disabled = false;
    }
}

function formatFinanceMoney(value) {
    return Number(value || 0).toLocaleString("es-MX", {style:"currency", currency:"CUP", minimumFractionDigits:2, maximumFractionDigits:2});
}
let adminFinanceTransfers = [];
function financeTransferDateKey(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const iso = raw.match(/^(\\d{4}-\\d{2}-\\d{2})/);
    if (iso) return iso[1];
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return "";
    const year = parsed.getFullYear();
    const month = String(parsed.getMonth() + 1).padStart(2, "0");
    const day = String(parsed.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
}
function renderAdminFinanceTransfers() {
    const body = document.getElementById("adminFinancesRows");
    if (!body) return;
    const from = document.getElementById("financeFilterDateFrom")?.value || "";
    const to = document.getElementById("financeFilterDateTo")?.value || "";
    const user = (document.getElementById("financeFilterUser")?.value || "").trim().toLocaleLowerCase();
    const state = (document.getElementById("financeFilterStatus")?.value || "").toLocaleLowerCase();
    const id = (document.getElementById("financeFilterId")?.value || "").trim().toLocaleLowerCase();
    const filtered = adminFinanceTransfers.filter(item => {
        const date = financeTransferDateKey(item.FECHA);
        const itemUser = String(item.USUARIO || "Sin asignar").toLocaleLowerCase();
        const itemId = String(item.ID_TRANSFERENCIA || "").toLocaleLowerCase();
        const itemState = String(item.ESTADO || "").toLocaleLowerCase();
        const confirmed = ["confirmada", "confirmado", "confirmed"].includes(itemState);
        return (!from || (date && date >= from)) && (!to || (date && date <= to))
            && (!user || itemUser.includes(user)) && (!id || itemId.includes(id))
            && (!state || (state === "confirmada" ? confirmed : !confirmed));
    });
    body.replaceChildren();
    if (!filtered.length) {
        const empty = document.createElement("p"); empty.className = "card-description";
        empty.textContent = adminFinanceTransfers.length ? "No hay transferencias que coincidan con los filtros." : "Todavía no hay transferencias registradas.";
        body.appendChild(empty);
    } else filtered.forEach(item => {
        const confirmed = ["confirmada", "confirmado", "confirmed"].includes(String(item.ESTADO || "").toLocaleLowerCase());
        const card = document.createElement("article"); card.className = "finance-transfer-card";
        const top = document.createElement("div"); top.className = "finance-transfer-card-top";
        const transferId = document.createElement("div"); transferId.className = "finance-transfer-id"; transferId.textContent = "ID: " + (item.ID_TRANSFERENCIA || "—");
        const badge = document.createElement("span"); badge.className = "finance-transfer-status" + (confirmed ? " is-confirmed" : ""); badge.textContent = confirmed ? "Confirmada" : "Por confirmar";
        top.append(transferId, badge);
        const amount = document.createElement("div"); amount.className = "finance-transfer-amount"; amount.textContent = formatFinanceMoney(item.IMPORTE);
        const meta = document.createElement("div"); meta.className = "finance-transfer-meta";
        const date = document.createElement("span"); date.textContent = "Fecha: " + (item.FECHA || "—");
        const owner = document.createElement("span"); owner.textContent = "Usuario: " + (item.USUARIO || "Sin asignar");
        meta.append(date, owner);
        card.append(top, amount, meta);
        if (!confirmed) {
            const assign = document.createElement("button"); assign.type = "button"; assign.className = "btn"; assign.textContent = "Asignar a usuario y confirmar";
            assign.addEventListener("click", () => openFinanceUserPicker(item)); card.appendChild(assign);
        }
        body.appendChild(card);
    });
    const status = document.getElementById("adminFinancesStatus");
    if (status) status.textContent = `Mostrando ${filtered.length} de ${adminFinanceTransfers.length} transferencias${from || to || user || state || id ? " (filtros aplicados)." : "."}`;
}
["financeFilterDateFrom", "financeFilterDateTo", "financeFilterUser", "financeFilterStatus", "financeFilterId"].forEach(id => {
    const control = document.getElementById(id);
    if (control) control.addEventListener("input", renderAdminFinanceTransfers);
    if (control && control.tagName === "SELECT") control.addEventListener("change", renderAdminFinanceTransfers);
});
document.getElementById("clearFinanceFilters")?.addEventListener("click", () => {
    ["financeFilterDateFrom", "financeFilterDateTo", "financeFilterUser", "financeFilterStatus", "financeFilterId"].forEach(id => { const control = document.getElementById(id); if (control) control.value = ""; });
    renderAdminFinanceTransfers();
});
let financePickerUsers = [];
let financePickerTransfer = null;
function closeFinanceUserPicker() {
    const modal = document.getElementById("financeUserPicker"); if (modal) modal.hidden = true;
    document.body.style.overflow = ""; financePickerTransfer = null;
}
function renderFinanceUserPicker() {
    const list = document.getElementById("financeUserPickerList");
    const status = document.getElementById("financeUserPickerStatus");
    const term = (document.getElementById("financeUserPickerSearch")?.value || "").trim().toLocaleLowerCase();
    if (!list) return;
    const eligible = financePickerUsers.filter(u => String(u.tipo || "cliente").toLocaleLowerCase() !== "admin");
    const filtered = eligible.filter(u => [u.id,u.usuario,u.nombre,u.apellidos,u.carnet_identidad,u.telefono,u.correo,u.provincia,u.municipio].some(v => String(v ?? "").toLocaleLowerCase().includes(term)));
    list.replaceChildren();
    if (!filtered.length) { const empty = document.createElement("p"); empty.className = "card-description"; empty.textContent = term ? "No hay usuarios que coincidan con la búsqueda." : "No hay cuentas disponibles para recibir recargas."; list.appendChild(empty); }
    filtered.forEach(u => {
        const card = document.createElement("article"); card.className = "finance-user-choice-card";
        const img = document.createElement("img"); img.alt = "Foto de " + (u.nombre || u.usuario || "usuario");
        img.src = isEmbeddedRadarImage(u.foto) ? u.foto : 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"%3E%3Crect width="80" height="80" rx="40" fill="%23e9edf5"/%3E%3Ccircle cx="40" cy="29" r="14" fill="%239aa6bd"/%3E%3Cpath d="M14 74c2-17 12-25 26-25s24 8 26 25" fill="%239aa6bd"/%3E%3C/svg%3E';
        const info = document.createElement("div"); info.className = "finance-user-choice-info";
        const name = document.createElement("h4"); name.textContent = (`${u.nombre || ""} ${u.apellidos || ""}`).trim() || u.usuario || "Usuario";
        const details = document.createElement("p"); details.textContent = `@${u.usuario || "—"} · ID ${u.id}`;
        const type = document.createElement("p"); type.textContent = `Cuenta: ${u.tipo || "cliente"} · Estado: ${u.estado || "—"}`;
        const contact = document.createElement("p"); contact.textContent = `Teléfono: ${u.telefono || "—"} · Correo: ${u.correo || "—"}`;
        const identity = document.createElement("p"); identity.textContent = `Carné: ${u.carnet_identidad || "—"} · ${[u.municipio,u.provincia].filter(Boolean).join(", ") || "Ubicación no indicada"}`;
        const balance = document.createElement("p"); balance.textContent = `Saldo: ${formatFinanceMoney(u.saldo)} · Días disponibles: ${Number(u.tiempo_disponible || 0)}`;
        const choose = document.createElement("button"); choose.type = "button"; choose.className = "btn"; choose.textContent = "Asignar y confirmar";
        choose.addEventListener("click", () => confirmFinanceTransferAssignment(u));
        info.append(name, details, type, contact, identity, balance, choose); card.append(img, info); list.appendChild(card);
    });
    if (status) status.textContent = `Mostrando ${filtered.length} de ${eligible.length} usuarios disponibles.`;
}
async function openFinanceUserPicker(transfer) {
    if (!transfer || !transfer.ID) { showToast("No se identificó la transferencia seleccionada."); return; }
    financePickerTransfer = transfer;
    const modal = document.getElementById("financeUserPicker");
    document.getElementById("financeUserPickerTransferInfo").textContent = `Transferencia ${transfer.ID_TRANSFERENCIA || "—"} · ${formatFinanceMoney(transfer.IMPORTE)} · ${transfer.FECHA || "Fecha no indicada"}`;
    document.getElementById("financeUserPickerSearch").value = "";
    document.getElementById("financeUserPickerStatus").textContent = "Cargando usuarios…";
    document.getElementById("financeUserPickerList").replaceChildren();
    modal.hidden = false; document.body.style.overflow = "hidden";
    try {
        const result = await apiRequest("admin_users_list", {});
        financePickerUsers = Array.isArray(result.usuarios) ? result.usuarios : [];
        renderFinanceUserPicker(); document.getElementById("financeUserPickerSearch").focus();
    } catch (error) {
        document.getElementById("financeUserPickerStatus").textContent = error.message || "No se pudieron cargar los usuarios.";
    }
}
async function confirmFinanceTransferAssignment(user) {
    if (!financePickerTransfer || !user) return;
    const transfer = financePickerTransfer;
    const displayName = (`${user.nombre || ""} ${user.apellidos || ""}`).trim() || user.usuario;
    const accepted = await radarConfirm(`Se asignará la transferencia ${transfer.ID_TRANSFERENCIA} (${formatFinanceMoney(transfer.IMPORTE)}) a ${displayName} (@${user.usuario}) y se acreditará su recarga, los días correspondientes y cualquier bono vigente. ¿Deseas continuar?`, {title:"Confirmar y asignar transferencia", confirmText:"Asignar y confirmar", danger:false});
    if (!accepted) return;
    try {
        const result = await apiRequest("admin_assign_confirm_transfer", {transfer_id:Number(transfer.ID), user_id:Number(user.id)});
        closeFinanceUserPicker(); showToast(result.message || "Transferencia asignada y confirmada."); await loadAdminFinances();
    } catch (error) {
        showToast(error.message || "No se pudo asignar la transferencia.");
    }
}
document.getElementById("financeUserPickerSearch")?.addEventListener("input", renderFinanceUserPicker);
document.getElementById("financeUserPickerClose")?.addEventListener("click", closeFinanceUserPicker);
document.getElementById("financeUserPicker")?.addEventListener("click", event => { if (event.target.id === "financeUserPicker") closeFinanceUserPicker(); });
document.addEventListener("keydown", event => { if (event.key === "Escape" && !document.getElementById("financeUserPicker")?.hidden) closeFinanceUserPicker(); });
async function loadAdminFinances() {
    if (!isAdminUser(currentRadarUser)) return;
    const status = document.getElementById("adminFinancesStatus");
    const button = document.getElementById("refreshAdminFinances");
    if (button) button.disabled = true;
    if (status) status.textContent = "Consultando movimientos financieros…";
    try {
        const result = await apiRequest("admin_finances", {});
        const f = result.finanzas || {};
        document.getElementById("financeAppIncome").textContent = formatFinanceMoney(f.ingresos_app);
        document.getElementById("financeConfirmedAmount").textContent = formatFinanceMoney(f.transferencias_confirmadas_importe);
        document.getElementById("financeConfirmedCount").textContent = Number(f.transferencias_confirmadas_cantidad || 0).toLocaleString("es-MX") + " transferencias";
        document.getElementById("financePendingAmount").textContent = formatFinanceMoney(f.transferencias_pendientes_importe);
        document.getElementById("financePendingCount").textContent = Number(f.transferencias_pendientes_cantidad || 0).toLocaleString("es-MX") + " transferencias";
        adminFinanceTransfers = Array.isArray(f.transferencias) ? f.transferencias : [];
        renderAdminFinanceTransfers();
        if (status) status.textContent = "Datos actualizados " + new Date().toLocaleString("es-MX") + ".";
    } catch (error) {
        if (status) status.textContent = error.message || "No se pudo cargar Finanzas.";
        showToast(error.message || "No se pudo cargar Finanzas.");
    } finally { if (button) button.disabled = false; }
}
document.getElementById("refreshAdminFinances")?.addEventListener("click", loadAdminFinances);

function appendAdminRequestField(parent, label, value) {
    const field = document.createElement("div");
    field.className = "admin-request-field";
    const heading = document.createElement("span");
    heading.className = "admin-request-field-label";
    heading.textContent = label;
    const content = document.createElement("span");
    content.className = "admin-request-field-value";
    content.textContent = value === undefined || value === null || String(value).trim() === "" ? "No especificado" : String(value);
    field.append(heading, content);
    parent.appendChild(field);
}

function isEmbeddedRadarImage(value) {
    return typeof value === "string" && /^data:image\/(jpeg|png|webp);base64,/i.test(value);
}

let adminUsersCache = [];
function escapeAdminText(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
}
function renderAdminUsers() {
    const list = document.getElementById('adminUsersList');
    const term = (document.getElementById('adminUserSearch')?.value || '').trim().toLocaleLowerCase();
    const filtered = adminUsersCache.filter(u => [u.usuario,u.nombre,u.apellidos,u.carnet_identidad].some(v => String(v || '').toLocaleLowerCase().includes(term)));
    list.replaceChildren(); list.classList.add('admin-users-grid');
    if (!filtered.length) { list.textContent = term ? 'No hay usuarios que coincidan con la búsqueda.' : 'No hay usuarios registrados.'; return; }
    filtered.forEach(u => {
        const card = document.createElement('article'); card.className = 'admin-user-card';
        const img = document.createElement('img'); img.className='admin-user-avatar'; img.alt='Foto de '+(u.nombre || u.usuario); img.src=isEmbeddedRadarImage(u.foto)?u.foto:'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"%3E%3Crect width="80" height="80" rx="40" fill="%23e9edf5"/%3E%3Ccircle cx="40" cy="29" r="14" fill="%239aa6bd"/%3E%3Cpath d="M14 74c2-17 12-25 26-25s24 8 26 25" fill="%239aa6bd"/%3E%3C/svg%3E';
        const info=document.createElement('div'); info.className='admin-user-main';
        const h=document.createElement('h3'); h.textContent=(`${u.nombre||''} ${u.apellidos||''}`).trim()||u.usuario;
        const p=document.createElement('p'); p.textContent='@'+u.usuario;
        const meta=document.createElement('p'); meta.textContent=`Tipo: ${u.tipo||'—'} · Estado: ${u.estado||'—'} · Editor: ${Number(u.es_editor)===1?'Sí':'No'}`;
        const actions=document.createElement('div'); actions.style.cssText='display:flex;gap:8px;flex-wrap:wrap;margin-top:8px';
        const edit=document.createElement('button'); edit.type='button'; edit.className='btn'; edit.textContent='Editar'; edit.addEventListener('click',()=>openAdminUserEditor(u));
        const del=document.createElement('button'); del.type='button'; del.className='btn btn-secondary'; del.textContent='Eliminar'; del.style.cssText='border-color:#dc2626;color:#b91c1c'; del.addEventListener('click',()=>deleteAdminUser(u));
        actions.append(edit,del); info.append(h,p,meta,actions); card.append(img,info); list.append(card);
    });
}
function openAdminUserEditor(u) {
    document.getElementById('adminEditId').value=u.id;
    document.getElementById('adminEditTipo').value=String(u.tipo ?? 'cliente').toLocaleLowerCase();
    document.getElementById('adminEditEditor').checked=Number(u.es_editor)===1;
    document.getElementById('adminEditDisplayName').textContent=(`${u.nombre||''} ${u.apellidos||''}`).trim()||u.usuario||'Usuario';
    document.getElementById('adminEditDisplayUsername').textContent='@'+(u.usuario||'');
    document.getElementById('adminEditPhoto').src=isEmbeddedRadarImage(u.foto)?u.foto:'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 80 80"%3E%3Crect width="80" height="80" rx="8" fill="%23e9edf5"/%3E%3Ccircle cx="40" cy="29" r="14" fill="%239aa6bd"/%3E%3Cpath d="M14 74c2-17 12-25 26-25s24 8 26 25" fill="%239aa6bd"/%3E%3C/svg%3E';
    document.getElementById('adminEditPassword').value='';
    const readOnly=document.getElementById('adminEditReadOnly');
    readOnly.innerHTML=`<p><strong>Usuario:</strong> ${escapeAdminText(u.usuario||'—')}</p><p><strong>Nombre:</strong> ${escapeAdminText((u.nombre||'')+' '+(u.apellidos||'')||'—')}</p><p><strong>Carné de identidad:</strong> ${escapeAdminText(u.carnet_identidad||'—')}</p><p><strong>Estado:</strong> ${escapeAdminText(u.estado||'—')}</p>`;
    document.getElementById('adminUserFormStatus').textContent=''; document.getElementById('adminUserEditor').hidden=false;
    document.body.style.overflow='hidden'; document.getElementById('adminUserEditorClose').focus();
}
function radarConfirm(message, options = {}) {
    const overlay = document.getElementById('radarConfirmOverlay');
    const title = document.getElementById('radarConfirmTitle');
    const text = document.getElementById('radarConfirmMessage');
    const accept = document.getElementById('radarConfirmAccept');
    const cancel = document.getElementById('radarConfirmCancel');
    if (!overlay || !title || !text || !accept || !cancel) return Promise.resolve(false);
    title.textContent = options.title || 'Confirmar acción';
    text.textContent = String(message || '¿Deseas continuar?');
    accept.textContent = options.confirmText || 'Confirmar';
    accept.style.background = options.danger === false ? '#2563eb' : '#dc3545';
    overlay.hidden = false; overlay.classList.add('show');
    document.body.style.overflow = 'hidden';
    return new Promise(resolve => {
        let finished = false;
        const finish = value => { if (finished) return; finished = true; overlay.classList.remove('show'); overlay.hidden = true; document.body.style.overflow = ''; accept.removeEventListener('click', yes); cancel.removeEventListener('click', no); overlay.removeEventListener('click', outside); document.removeEventListener('keydown', keydown); resolve(value); };
        const yes = () => finish(true), no = () => finish(false);
        const outside = event => { if (event.target === overlay) no(); };
        const keydown = event => { if (event.key === 'Escape') no(); };
        accept.addEventListener('click', yes); cancel.addEventListener('click', no); overlay.addEventListener('click', outside); document.addEventListener('keydown', keydown);
        accept.focus();
    });
}

async function deleteAdminUser(u) {
    const displayName=(`${u.nombre||''} ${u.apellidos||''}`).trim() || u.usuario || 'este usuario';
    if (!await radarConfirm(`¿Seguro que deseas eliminar a ${displayName} (@${u.usuario})? Esta acción no se puede deshacer.`, {title:'Eliminar usuario', confirmText:'Eliminar'})) return;
    try {
        const result=await apiRequest('admin_user_delete',{id:Number(u.id)});
        document.getElementById('adminUsersStatus').textContent=result.message || 'Usuario eliminado.';
        if (Number(document.getElementById('adminEditId').value)===Number(u.id)) closeAdminUserEditor();
        await refreshAdminUsers();
        await refreshAdminSummary();
    } catch(e) {
        document.getElementById('adminUsersStatus').textContent=e.message;
    }
}
let adminUsersRefreshInProgress = false;
async function refreshAdminUsers(silent = false) {
    const status=document.getElementById('adminUsersStatus'); if (!status || adminUsersRefreshInProgress) return;
    adminUsersRefreshInProgress = true;
    if (!silent) status.textContent='Cargando usuarios…';
    try {
        const result=await apiRequest('admin_users_list',{});
        const incoming=Array.isArray(result.usuarios)?result.usuarios:[];
        // Actualiza las tarjetas desde la base de datos sin borrar la búsqueda activa.
        adminUsersCache=incoming;
        renderAdminUsers();
        if (!silent || !status.textContent || status.textContent.startsWith('Cargando')) status.textContent=`${adminUsersCache.length} usuario(s) encontrado(s). Actualizado en ${new Date().toLocaleTimeString()}.`;
    } catch(e) { if (!silent) status.textContent=e.message; }
    finally { adminUsersRefreshInProgress = false; }
}

// Sincronización automática entre sesiones/navegadores: consulta la BD cada 5 segundos
// únicamente mientras Administración > Usuarios esté visible.
if (!window.adminUsersRealtimeTimer) {
    window.adminUsersRealtimeTimer = window.setInterval(() => {
        const page=document.getElementById('admin-usuarios');
        if (page && page.classList.contains('active') && !document.hidden) refreshAdminUsers(true);
    }, 3000);
}
document.getElementById('refreshAdminUsers')?.addEventListener('click',refreshAdminUsers);
document.getElementById('adminUserSearch')?.addEventListener('input',renderAdminUsers);
function closeAdminUserEditor(){document.getElementById('adminUserEditor').hidden=true;document.body.style.overflow='';}
document.getElementById('adminCancelEdit')?.addEventListener('click',closeAdminUserEditor);
document.getElementById('adminUserEditorClose')?.addEventListener('click',closeAdminUserEditor);
document.getElementById('adminUserEditor')?.addEventListener('click',event=>{if(event.target.id==='adminUserEditor')closeAdminUserEditor();});
document.addEventListener('keydown',event=>{if(event.key==='Escape'&&!document.getElementById('adminUserEditor')?.hidden)closeAdminUserEditor();});
document.getElementById('adminUserForm')?.addEventListener('submit',async event=>{
    event.preventDefault(); const status=document.getElementById('adminUserFormStatus'); status.textContent='Guardando cambios…';
    try {
        const result=await apiRequest('admin_user_update',{id:Number(document.getElementById('adminEditId').value),tipo:document.getElementById('adminEditTipo').value,es_editor:document.getElementById('adminEditEditor').checked?1:0,contrasena:document.getElementById('adminEditPassword').value});
        status.textContent=result.message || 'Cambios guardados.'; await refreshAdminUsers(); await refreshAdminSummary();
        if(currentRadarUser && Number(currentRadarUser.id)===Number(result.usuario.id)) { currentRadarUser=result.usuario; syncAdminModuleVisibility(currentRadarUser); }
        document.getElementById('adminEditPassword').value='';
    } catch(e) { status.textContent=e.message; }
});

let adminRequestView = "pendiente";

function updateAdminRequestTabs() {
    document.querySelectorAll("[data-request-state]").forEach(button => {
        const active = button.dataset.requestState === adminRequestView;
        button.classList.toggle("active", active);
        button.setAttribute("aria-selected", active ? "true" : "false");
    });
    const title = document.getElementById("adminTransportRequestsTitle");
    const help = document.getElementById("adminTransportRequestsHelp");
    if (title) title.textContent = adminRequestView === "aprobada" ? "Solicitudes de transportista aprobadas" : "Solicitudes de transportista pendientes";
    if (help) help.textContent = adminRequestView === "aprobada"
        ? "Consulta las autorizaciones aprobadas y revoca las que ya no deban permanecer activas."
        : "Revisa los datos del solicitante, su cuenta y la información del vehículo antes de decidir.";
}

function renderAdminTransportRequests(requests, state = adminRequestView) {
    const container = document.getElementById("adminTransportRequestsList");
    if (!container) return;
    container.replaceChildren();

    if (!Array.isArray(requests) || requests.length === 0) {
        const empty = document.createElement("div");
        empty.className = "admin-requests-empty";
        empty.textContent = state === "aprobada"
            ? "No hay solicitudes aprobadas que puedan revocarse."
            : "No hay solicitudes de transportista pendientes. Cuando un usuario envíe una solicitud, sus datos aparecerán aquí para que puedas revisarlos.";
        container.appendChild(empty);
        return;
    }

    requests.forEach(request => {
        const card = document.createElement("article");
        card.className = "admin-request-card";
        card.dataset.requestId = String(request.ID);

        // Cada solicitud inicia contraída para mantener compacta la lista.
        const details = document.createElement("details");
        details.className = "admin-request-details";
        details.dataset.requestId = String(request.ID);
        const head = document.createElement("summary");
        head.className = "admin-request-card-head";
        head.setAttribute("aria-label", "Mostrar u ocultar los datos de la solicitud " + request.ID);
        const avatar = document.createElement("div");
        avatar.className = "admin-request-avatar";
        const fullName = [request.NOMBRE, request.APELLIDOS].filter(value => String(value || "").trim()).join(" ").trim();
        const displayName = fullName || request.USUARIO || "Usuario";
        if (isEmbeddedRadarImage(request.FOTO_USUARIO)) {
            const image = document.createElement("img");
            image.src = request.FOTO_USUARIO;
            image.alt = "Foto de perfil de " + displayName;
            avatar.appendChild(image);
        } else {
            avatar.textContent = displayName.trim().charAt(0).toLocaleUpperCase("es-MX") || "U";
        }

        const person = document.createElement("div");
        person.className = "admin-request-person";
        const name = document.createElement("h4");
        name.textContent = displayName;
        const username = document.createElement("p");
        const shortVehicle = [request.TIPO_VEHICULO, request.CHAPA ? "Chapa: " + request.CHAPA : ""].filter(value => String(value || "").trim()).join(" · ");
        username.textContent = "@" + (request.USUARIO || "usuario") + (shortVehicle ? " · " + shortVehicle : "");
        person.append(name, username);

        const badge = document.createElement("span");
        badge.className = state === "aprobada" ? "admin-request-approved-badge" : "admin-request-pending-badge";
        badge.textContent = state === "aprobada" ? "Aprobada" : "Pendiente";
        const chevron = document.createElement("span");
        chevron.className = "admin-request-chevron";
        chevron.setAttribute("aria-hidden", "true");
        chevron.textContent = "⌄";
        head.append(avatar, person, badge, chevron);
        details.appendChild(head);

        const body = document.createElement("div");
        body.className = "admin-request-body";
        const fields = document.createElement("div");
        fields.className = "admin-request-fields";
        appendAdminRequestField(fields, "Número de solicitud", "#" + (request.ID || "—"));
        appendAdminRequestField(fields, "Nombre y apellidos", fullName || "No especificado");
        appendAdminRequestField(fields, "Carné de identidad", request.CARNET_IDENTIDAD);
        appendAdminRequestField(fields, "Estado actual de la cuenta", request.ESTADO_USUARIO);
        appendAdminRequestField(fields, "Tipo actual de cuenta", request.TIPO_CUENTA);
        appendAdminRequestField(fields, "Tipo de vehículo", request.TIPO_VEHICULO);
        appendAdminRequestField(fields, "Chapa", request.CHAPA);
        appendAdminRequestField(fields, "No. de licencia de conducción", request.LICENCIA_CONDUCCION);
        appendAdminRequestField(fields, "Teléfono de contacto", request.TELEFONO);
        appendAdminRequestField(fields, "Fecha de solicitud", request.CREADA_EN);
        if (state === "aprobada" && request.NOTA_ADMIN) appendAdminRequestField(fields, "Nota de Administración", request.NOTA_ADMIN);

        const photoWrap = document.createElement("div");
        photoWrap.className = "admin-request-vehicle-photo";
        const photoLabel = document.createElement("span");
        photoLabel.className = "admin-request-vehicle-photo-label";
        photoLabel.textContent = "Foto del vehículo";
        photoWrap.appendChild(photoLabel);
        if (isEmbeddedRadarImage(request.FOTO_VEHICULO)) {
            const photo = document.createElement("img");
            photo.src = request.FOTO_VEHICULO;
            photo.alt = "Vehículo presentado en la solicitud " + request.ID;
            photo.loading = "lazy";
            photoWrap.appendChild(photo);
        } else {
            const emptyPhoto = document.createElement("div");
            emptyPhoto.className = "admin-request-photo-empty";
            emptyPhoto.textContent = "Esta solicitud no tiene una foto de vehículo válida guardada.";
            photoWrap.appendChild(emptyPhoto);
        }
        body.append(fields, photoWrap);
        details.appendChild(body);

        const actions = document.createElement("div");
        actions.className = "admin-request-actions";
        if (state === "aprobada") {
            const revoke = document.createElement("button");
            revoke.type = "button";
            revoke.className = "btn btn-admin-reject";
            revoke.textContent = "⛔ Revocar autorización";
            revoke.addEventListener("click", () => reviewAdminTransportRequest(request.ID, "revocar", revoke, null));
            actions.appendChild(revoke);
        } else {
            const approve = document.createElement("button");
            approve.type = "button";
            approve.className = "btn btn-admin-approve";
            approve.textContent = "✓ Aprobar";
            approve.addEventListener("click", () => reviewAdminTransportRequest(request.ID, "aprobar", approve, reject));
            const reject = document.createElement("button");
            reject.type = "button";
            reject.className = "btn btn-admin-reject";
            reject.textContent = "✕ Desaprobar";
            reject.addEventListener("click", () => reviewAdminTransportRequest(request.ID, "desaprobar", approve, reject));
            actions.append(approve, reject);
        }
        details.appendChild(actions);
        card.appendChild(details);
        container.appendChild(card);
    });
}

async function refreshAdminTransportRequests() {
    if (!canReviewTransportRequests(currentRadarUser)) return;
    const button = document.getElementById("refreshAdminRequests");
    const status = document.getElementById("adminTransportRequestsStatus");
    updateAdminRequestTabs();
    if (button) button.disabled = true;
    if (status) status.textContent = adminRequestView === "aprobada" ? "Consultando solicitudes aprobadas…" : "Consultando solicitudes pendientes…";
    try {
        const result = await apiRequest("admin_transport_requests", {estado: adminRequestView});
        const requests = Array.isArray(result.solicitudes) ? result.solicitudes : [];
        renderAdminTransportRequests(requests, adminRequestView);
        const stateLabel = adminRequestView === "aprobada" ? "aprobada" : "pendiente";
        if (status) status.textContent = requests.length === 1
            ? "Hay 1 solicitud " + stateLabel + (adminRequestView === "aprobada" ? ", disponible para revocar." : " pendiente de revisión.")
            : "Hay " + requests.length.toLocaleString("es-MX") + (adminRequestView === "aprobada" ? " solicitudes aprobadas, disponibles para revocar." : " solicitudes pendientes de revisión.");
    } catch (error) {
        if (status) status.textContent = error.message || "No se pudieron cargar las solicitudes.";
        showToast(error.message || "No se pudieron cargar las solicitudes.");
    } finally {
        if (button) button.disabled = false;
    }
}

async function reviewAdminTransportRequest(requestId, decision, approveButton, rejectButton) {
    const labels = {aprobar:"aprobar", desaprobar:"desaprobar", revocar:"revocar la autorización de"};
    const actionLabel = labels[decision] || decision;
    let confirmation = "¿Confirmas " + actionLabel + " esta solicitud de cuenta transportista?";
    if (decision === "aprobar") confirmation += " La cuenta del usuario cambiará a Transportista.";
    else if (decision === "desaprobar") confirmation += " La cuenta del usuario no cambiará.";
    else confirmation += " El tipo de cuenta se devolverá al tipo anterior registrado, o a cliente si no existe ese dato.";
    if (!await radarConfirm(confirmation, {title: decision === 'revocar' ? 'Revocar autorización' : (decision === 'desaprobar' ? 'Desaprobar solicitud' : 'Aprobar solicitud'), confirmText: decision === 'revocar' ? 'Revocar' : (decision === 'desaprobar' ? 'Desaprobar' : 'Aprobar'), danger: decision !== 'aprobar'})) return;
    if (approveButton) approveButton.disabled = true;
    if (rejectButton) rejectButton.disabled = true;
    try {
        const result = await apiRequest("admin_review_transport_request", {solicitud_id: Number(requestId), decision});
        showToast(result.message || "La solicitud se ha actualizado.");
        if (isAdminUser(currentRadarUser)) await refreshAdminSummary();
        await refreshAdminTransportRequests();
    } catch (error) {
        showToast(error.message || "No se pudo procesar la solicitud.");
        if (approveButton) approveButton.disabled = false;
        if (rejectButton) rejectButton.disabled = false;
    }
}

function showPage(name) {
    if (name === "admin-bases-datos") {
        name = "admin-ajustes";
        activateAppSettingsTab("bases-datos");
    }
    if (!pageInfo[name]) return;
    if (name === "admin-transport-historial" && !isAdminUser(currentRadarUser)) { showPage("radar"); showToast("Solo los administradores pueden consultar el historial global."); return; }
    if ((name === "admin-mis-transportistas" || name === "editor-estado-cuenta") && !isTransportEditor(currentRadarUser)) { showPage("radar"); showToast("Esta sección requiere Editor activado."); return; }
    if (name === "admin-solicitudes" && !canReviewTransportRequests(currentRadarUser)) {
        name = "radar";
        if (location.hash === "#admin-solicitudes") history.replaceState(null, "", "#radar");
    } else if (["administracion", "admin-finanzas", "admin-pagos-editores", "admin-usuarios", "admin-ajustes"].includes(name) && !isAdminUser(currentRadarUser)) {
        name = "radar";
        if (["#administracion", "#admin-finanzas", "#admin-pagos-editores", "#admin-usuarios", "#admin-ajustes"].includes(location.hash)) history.replaceState(null, "", "#radar");
    }

    const previousActivePage = document.querySelector(".page.active");
    const isChangingPage = !previousActivePage || previousActivePage.id !== name;

    document.querySelectorAll(".page").forEach(page => {
        page.classList.remove("active");
    });

    document.getElementById(name).classList.add("active");
    document.getElementById("pageTitle").textContent = pageInfo[name].title;
    document.getElementById("pageSubtitle").textContent = pageInfo[name].subtitle;

    // El resaltado se reserva exclusivamente para el submenú seleccionado.
    // Los enlaces de Ajustes comparten data-page="admin-ajustes", por lo que
    // se distingue cuál está abierto mediante data-settings-tab-nav.
    document.querySelectorAll("[data-page]").forEach(link => {
        const isSettingsSubmenuLink = link.hasAttribute("data-settings-tab-nav");
        const isActive = isSettingsSubmenuLink
            ? (name === "admin-ajustes" && link.dataset.settingsTabNav === (document.querySelector("[data-settings-tab].active")?.dataset.settingsTab || "tarifas"))
            : (link.closest(".submenu") && link.dataset.page === name);
        link.classList.toggle("active", Boolean(isActive));
    });

    // Los botones principales solo expanden/contraen; nunca quedan resaltados.
    settingsToggle.classList.remove("active");
    adminNavLink.classList.remove("active");

    if (name === "recargar" || name === "password" || name === "solicitar-transportista") {
        settingsSubmenu.classList.add("open");
        settingsToggle.setAttribute("aria-expanded", "true");
    }
    if (name === "administracion" || name === "admin-finanzas" || name === "admin-pagos-editores" || name === "admin-solicitudes" || name === "admin-transport-historial" || name === "admin-mis-transportistas" || name === "editor-estado-cuenta" || name === "admin-usuarios" || name === "admin-ajustes") {
        adminSubmenu.classList.add("open");
        adminNavLink.setAttribute("aria-expanded", "true");
    }
    if (name === "administracion") refreshAdminSummary();
    if (name === "admin-finanzas") loadAdminFinances();
    if (name === "admin-pagos-editores") loadEditorPayments();
    if (name === "admin-transport-historial") loadTransportAudit();
    if (name === "admin-mis-transportistas") loadMyTransportistas();
    if (name === "editor-estado-cuenta") loadEditorAccountStatement();
    if (name === "admin-usuarios") refreshAdminUsers();
    if (name === "admin-ajustes") { loadAdminTariffs(); }
    if (name === "solicitar-transportista") loadTransportTypeOptions();
    if (name === "admin-solicitudes") {
        if (isAdminUser(currentRadarUser)) refreshAdminSummary();
        refreshAdminTransportRequests();
    }

    sidebar.classList.remove("open");
    overlay.classList.remove("open");

    // Al cambiar de módulo, la nueva página siempre se presenta desde el inicio.
    if (isChangingPage) {
        requestAnimationFrame(() => window.scrollTo({ top: 0, left: 0, behavior: "auto" }));
    }

    if (name === "radar" && mobilityMap) {
        setTimeout(() => { try { mobilityMap.invalidateSize(); } catch (_) {} }, 120);
    }
    if (name === "solicitar-transportista") {
        refreshTransportRequestStatus();
    }

    if (location.hash !== "#" + name) {
        history.replaceState(null, "", "#" + name);
    }
}

document.getElementById("refreshAdminSummary").addEventListener("click", async () => {
    await Promise.all([refreshAdminSummary(), refreshAdminTransportRequests()]);
});
document.getElementById("refreshAdminRequests").addEventListener("click", refreshAdminTransportRequests);
document.querySelectorAll("[data-request-state]").forEach(button => {
    button.addEventListener("click", () => {
        const nextState = button.dataset.requestState;
        if (!['pendiente', 'aprobada'].includes(nextState) || nextState === adminRequestView) return;
        adminRequestView = nextState;
        refreshAdminTransportRequests();
    });
});

settingsToggle.addEventListener("click", () => {
    const expanded = settingsToggle.getAttribute("aria-expanded") === "true";
    settingsToggle.setAttribute("aria-expanded", String(!expanded));
    settingsSubmenu.classList.toggle("open", !expanded);
});

// La distancia de llegada se administra desde Perfil de Usuario > Datos de la cuenta.
async function loadProfileArrivalRadius() {
    const select = document.getElementById("profileArrivalRadius");
    const feedback = document.getElementById("profileArrivalRadiusFeedback");
    if (!select) return;
    try {
        const current = await apiRequest("arrival_radius_get", {});
        const radius = Number(current.radio_m || 10);
        select.value = String(Number.isInteger(radius) && radius >= 5 && radius <= 20 ? radius : 10);
        if (feedback) feedback.textContent = "Distancia actual: " + select.value + " metros.";
    } catch (error) {
        if (feedback) feedback.textContent = error.message || "No se pudo cargar la distancia guardada.";
    }
}
document.getElementById("saveProfileArrivalRadius")?.addEventListener("click", async () => {
    const select = document.getElementById("profileArrivalRadius");
    const feedback = document.getElementById("profileArrivalRadiusFeedback");
    const radius = Number(select?.value);
    if (!Number.isInteger(radius) || radius < 5 || radius > 20) {
        if (feedback) feedback.textContent = "La distancia debe estar entre 5 y 20 metros.";
        return;
    }
    const button = document.getElementById("saveProfileArrivalRadius");
    if (button) button.disabled = true;
    try {
        const saved = await apiRequest("arrival_radius_save", {radio_m: radius});
        if (feedback) feedback.textContent = saved.message || "Distancia guardada correctamente.";
        showToast(saved.message || "Distancia guardada.");
    } catch (error) {
        if (feedback) feedback.textContent = error.message || "No se pudo guardar la distancia.";
        showToast(error.message || "No se pudo guardar la distancia.");
    } finally {
        if (button) button.disabled = false;
    }
});

const shareApplicationLink = document.getElementById("shareApplicationLink");
if (shareApplicationLink) {
    shareApplicationLink.addEventListener("click", async () => {
        sidebar.classList.remove("open");
        overlay.classList.remove("open");
        try {
            const info = await apiRequest("referral_info");
            const inviteUrl = String(info.enlace || "");
            if (!inviteUrl) throw new Error("No se pudo obtener tu enlace personal.");
            const layer = document.createElement("div");
            layer.className = "recharge-result-overlay";
            layer.style.zIndex = "17000";
            layer.innerHTML = `<div class="recharge-result-backdrop"></div><section class="recharge-result-dialog" role="dialog" aria-modal="true" aria-labelledby="inviteDialogTitle"><div class="recharge-result-icon">🎁</div><h2 id="inviteDialogTitle">Invita y gana saldo</h2><p class="recharge-result-intro">Comparte tu enlace personal. Cuando alguien se registre correctamente desde él, recibirás <strong>${Number(info.recompensa || 0).toFixed(2).replace(".",",")}</strong> en el saldo de tu cuenta.</p><label for="radarInviteUrl" style="display:block;text-align:left;font-size:12px;font-weight:800;margin:0 0 7px">Tu enlace único</label><input id="radarInviteUrl" readonly value="${escapeAdminText(inviteUrl)}" style="width:100%;box-sizing:border-box;padding:13px;border:1px solid #dfe3ef;border-radius:12px;background:#f7f8fc;color:#252a48;font-size:13px"><p style="margin:10px 0 18px;color:#858ca5;font-size:11px">Código: ${escapeAdminText(info.codigo || "")}</p><div style="display:flex;gap:9px;flex-wrap:wrap"><button type="button" class="btn btn-primary" id="radarInviteCopy" style="flex:1">Copiar enlace</button><button type="button" class="btn btn-secondary" id="radarInviteShare" style="flex:1">Compartir</button></div><button type="button" class="btn btn-secondary" id="radarInviteClose" style="width:100%;margin-top:10px">Cerrar</button></section>`;
            document.body.appendChild(layer);
            const close = () => layer.remove();
            layer.querySelector(".recharge-result-backdrop").addEventListener("click", close);
            layer.querySelector("#radarInviteClose").addEventListener("click", close);
            layer.querySelector("#radarInviteCopy").addEventListener("click", async () => {
                try {
                    if (navigator.clipboard && navigator.clipboard.writeText) await navigator.clipboard.writeText(inviteUrl);
                    else { const field=layer.querySelector("#radarInviteUrl"); field.select(); if(!document.execCommand("copy")) throw new Error("copy"); }
                    showToast("Tu enlace personal se copió correctamente.");
                } catch (_) { showToast("No se pudo copiar. Selecciona el enlace y cópialo manualmente."); }
            });
            layer.querySelector("#radarInviteShare").addEventListener("click", async () => {
                if (navigator.share) {
                    try { await navigator.share({title:"Invitación a El Radar", text:"Regístrate en El Radar usando mi invitación.", url:inviteUrl}); }
                    catch (error) { if (!error || error.name !== "AbortError") showToast("No se pudo abrir el menú para compartir."); }
                } else {
                    try { await navigator.clipboard.writeText(inviteUrl); showToast("Enlace copiado para compartir."); }
                    catch (_) { showToast("Copia el enlace del cuadro para compartirlo."); }
                }
            });
        } catch (error) {
            showToast(error.message || "No se pudo generar el enlace de invitación.");
        }
    });
}

adminNavLink.addEventListener("click", () => {
    // Los administradores y los editores autorizados pueden desplegar Administración.
    // Para los editores, syncAdminModuleVisibility mantiene visible únicamente
    // el enlace de Solicitudes de transportista.
    if (!isAdminUser(currentRadarUser) && !isTransportEditor(currentRadarUser)) return;
    const expanded = adminNavLink.getAttribute("aria-expanded") === "true";
    adminNavLink.setAttribute("aria-expanded", String(!expanded));
    adminSubmenu.classList.toggle("open", !expanded);
});

document.querySelectorAll("[data-page]").forEach(link => {
    link.addEventListener("click", event => {
        event.preventDefault();
        showPage(link.dataset.page);
    });
});


// Navegación entre las tres pestañas de Recargar cuenta.
document.querySelectorAll("[data-recharge-tab]").forEach(tab => {
    tab.addEventListener("click", () => {
        const targetId = tab.dataset.rechargeTab;
        document.querySelectorAll("[data-recharge-tab]").forEach(item => {
            const selected = item === tab;
            item.classList.toggle("active", selected);
            item.setAttribute("aria-selected", selected ? "true" : "false");
        });
        document.querySelectorAll(".recharge-tab-pane").forEach(pane => {
            const selected = pane.id === targetId;
            pane.hidden = !selected;
            pane.classList.toggle("active", selected);
        });
    });
});

document.getElementById("mobileMenu").addEventListener("click", () => {
    sidebar.classList.add("open");
    overlay.classList.add("open");
});

overlay.addEventListener("click", () => {
    sidebar.classList.remove("open");
    overlay.classList.remove("open");
});

const rechargeResultModal = document.getElementById("rechargeResultModal");
const closeRechargeResult = () => { if (rechargeResultModal) { rechargeResultModal.hidden = true; rechargeResultModal.classList.remove("is-animated"); } };
document.getElementById("rechargeResultClose").addEventListener("click", closeRechargeResult);
rechargeResultModal.querySelector(".recharge-result-backdrop").addEventListener("click", closeRechargeResult);
async function loadBalanceOperations() {
    const list = document.getElementById("balanceOperationsList");
    const balance = document.getElementById("operationsCurrentBalance");
    if (!list || !balance) return;
    list.innerHTML = '<p class="card-description">Cargando operaciones…</p>';
    try {
        const result = await apiRequest("balance_history", {});
        const money = value => new Intl.NumberFormat("es-ES", {style:"currency", currency:"CUP"}).format(Number(value || 0));
        balance.textContent = money(result.saldo_actual);
        if (!Array.isArray(result.operaciones) || result.operaciones.length === 0) {
            list.innerHTML = '<p class="card-description">Todavía no hay operaciones registradas. Los movimientos nuevos aparecerán aquí automáticamente.</p>';
            return;
        }
        list.innerHTML = result.operaciones.map(op => {
            const incoming = Number(op.importe) > 0;
            const amountClass = incoming ? 'color:var(--success, #16834a)' : 'color:var(--danger, #c0392b)';
            const date = op.creado_en ? new Date(String(op.creado_en).replace(' ', 'T')).toLocaleString('es-ES') : '';
            return `<article class="notice" style="margin-top:10px"><div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap"><strong>${escapeAdminText(op.descripcion || op.tipo || 'Movimiento')}</strong><strong style="${amountClass}">${incoming ? '+' : ''}${money(op.importe)}</strong></div><small>${escapeAdminText(date)}${op.referencia ? ' · Ref.: ' + escapeAdminText(op.referencia) : ''}</small><div style="margin-top:7px;font-size:.9em">Saldo anterior: ${money(op.saldo_anterior)} → <strong>Saldo nuevo: ${money(op.saldo_nuevo)}</strong></div></article>`;
        }).join('');
    } catch (error) {
        list.innerHTML = `<p class="card-description">${escapeAdminText(error.message || 'No se pudo cargar el historial.')}</p>`;
    }
}

// Cargar el libro de movimientos al entrar en Recargar cuenta y después de cada movimiento.
const balanceOperationsNav = document.querySelector('[data-page="recargar"]');
if (balanceOperationsNav) balanceOperationsNav.addEventListener('click', () => setTimeout(loadBalanceOperations, 100));

document.getElementById("balanceTransferForm").addEventListener("submit", async event => {
    event.preventDefault();
    const recipient = document.getElementById("balanceRecipient").value.trim();
    const amount = document.getElementById("balanceTransferAmount").value;
    if (!recipient) return showToast("Introduce el usuario destinatario.");
    if (!Number.isFinite(Number(amount)) || Number(amount) <= 0) return showToast("Introduce un importe mayor que cero.");
    const button = event.target.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
        const result = await apiRequest("transfer_balance", {usuario_destino: recipient, importe: amount});
        if (currentRadarUser) currentRadarUser.saldo = Number(result.saldo_restante || 0);
        const balanceValue = document.getElementById("balanceValue");
        if (balanceValue) balanceValue.textContent = new Intl.NumberFormat("es-ES", {style:"currency", currency:"CUP"}).format(Number(result.saldo_restante || 0));
        event.target.reset();
        showToast(`Transferencia realizada: ${new Intl.NumberFormat("es-ES", {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(result.importe || 0))} al usuario ${result.destinatario}. Saldo restante: ${new Intl.NumberFormat("es-ES", {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(result.saldo_restante || 0))}.`);
        loadBalanceOperations();
    } catch (error) {
        showToast(error.message || "No se pudo transferir el saldo.");
    } finally {
        if (button) button.disabled = false;
    }
});

document.getElementById("rechargeForm").addEventListener("submit", async event => {
    event.preventDefault();
    const transferId = document.getElementById("transferId").value.trim();
    if (!transferId) {
        showToast("Introduce el ID de la transferencia.");
        return;
    }
    const button = event.target.querySelector('button[type="submit"]');
    if (button) button.disabled = true;
    try {
        const result = await apiRequest("confirm_transfer_submission", {id_transferencia: transferId});
        const money = new Intl.NumberFormat("es-ES", {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(result.importe || 0));
        document.getElementById("rechargeResultAmount").textContent = money;
        const hasRechargeBonus = Number(result.bono_recarga || 0) > 0;
        document.getElementById("rechargeResultTitle").textContent = hasRechargeBonus ? "¡Oferta acreditada!" : "¡Recarga acreditada!";
        document.getElementById("rechargeResultIntro").textContent = hasRechargeBonus ? "Tu transferencia se confirmó y recibiste una bonificación promocional que ya fue sumada a tu saldo." : "Tu transferencia se confirmó y el importe ya fue sumado a tu saldo.";
        const bonusMoney = new Intl.NumberFormat("es-ES", {minimumFractionDigits:2, maximumFractionDigits:2}).format(Number(result.bono_recarga || 0));
        document.getElementById("rechargeResultBonus").textContent = Number(result.bono_recarga || 0) > 0 ? `+${bonusMoney} CUP (${Number(result.porcentaje_bono || 0).toLocaleString("es-ES")}%)` : "Sin oferta aplicada";
        const balanceFormatter = new Intl.NumberFormat("es-ES", {minimumFractionDigits:2, maximumFractionDigits:2});
        document.getElementById("rechargeResultPreviousBalance").textContent = `${balanceFormatter.format(Number(result.saldo_anterior || 0))} CUP`;
        document.getElementById("rechargeResultTotalCredit").textContent = `${balanceFormatter.format(Number(result.total_acreditado || result.importe || 0))} CUP`;
        document.getElementById("rechargeResultNewBalance").textContent = `${balanceFormatter.format(Number(result.saldo_actual || 0))} CUP`;
        document.getElementById("rechargeResultGrantedDays").textContent = `${Number(result.dias_otorgados || 0).toLocaleString("es-ES")} días`;
        document.getElementById("rechargeResultAvailableDays").textContent = `${Number(result.dias_disponibles || 0).toLocaleString("es-ES")} días`;
        const expiry = result.fecha_vencimiento ? new Date(String(result.fecha_vencimiento).replace(" ", "T")) : null;
        document.getElementById("rechargeResultExpiry").textContent = expiry && !Number.isNaN(expiry.getTime()) ? expiry.toLocaleString("es-ES") : "—";
        document.getElementById("rechargeResultFormula").textContent = Number(result.bono_recarga || 0) > 0
            ? `Cálculo: saldo anterior + transferencia + bonificación. Se añadió un bono del ${Number(result.porcentaje_bono || 0).toLocaleString("es-ES")}% (${bonusMoney} CUP). El nuevo saldo incluye todos esos importes.`
            : `Cálculo: saldo anterior + transferencia. No había una oferta aplicable a este importe.`;
        rechargeResultModal.classList.remove("is-animated");
        rechargeResultModal.hidden = false;
        void rechargeResultModal.offsetWidth;
        rechargeResultModal.classList.add("is-animated");
        event.target.reset();
        loadBalanceOperations();
        if (typeof currentRadarUser === "object" && currentRadarUser) {
            currentRadarUser.tiempo_disponible = Number(result.dias_disponibles || 0);
            if (result.saldo_actual !== undefined) currentRadarUser.saldo = Number(result.saldo_actual);
            currentRadarUser.pago_hasta = result.fecha_vencimiento || currentRadarUser.pago_hasta;
            currentRadarUser.estado = "activo";
        }
        const availableTime = document.getElementById("availableTime");
        if (availableTime) availableTime.textContent = Number(result.dias_disponibles || 0).toLocaleString("es-ES");
    } catch (error) {
        showToast(error.message || "No se pudo confirmar la transferencia.");
    } finally {
        if (button) button.disabled = false;
    }
});

document.getElementById("passwordForm").addEventListener("submit", async event => {
    event.preventDefault();
    const current = document.getElementById("currentPassword").value;
    const next = document.getElementById("newPassword").value;
    const confirm = document.getElementById("confirmPassword").value;
    if (next.length < 8) return showToast("La nueva contraseña debe tener al menos 8 caracteres.");
    if (next === current) return showToast("La nueva contraseña debe ser diferente de la actual.");
    if (next !== confirm) return showToast("Las nuevas contraseñas no coinciden.");
    try {
      const result = await apiRequest("change_password", {actual: current, nueva: next});
      showToast(result.message || "Contraseña cambiada correctamente.");
      event.target.reset();
    } catch (error) { showToast(error.message || "No se pudo cambiar la contraseña."); }
});

function setTransportRequestView(state, message = "") {
    const box = document.getElementById("transportRequestStatus");
    const icon = document.getElementById("transportRequestStatusIcon");
    const title = document.getElementById("transportRequestStatusTitle");
    const text = document.getElementById("transportRequestStatusText");
    const button = document.getElementById("sendTransportRequestButton");
    const form = document.getElementById("transportAccountForm");
    const menuLink = document.getElementById("transportRequestMenuLink");
    if (!box || !icon || !title || !text || !button) return;
    state = String(state || "ninguna").toLowerCase();
    box.dataset.state = state;
    const stateContent = {
        transportista: { icon: "✓", title: "Tu cuenta ya es de transportista", text: "Tu cuenta transportista está activa.", button: "Cuenta transportista activa", disabled: true },
        pendiente: { icon: "◷", title: "Solicitud en proceso de aprobación", text: "Tu solicitud fue enviada. El formulario permanecerá oculto hasta que la administración tome una decisión.", button: "Solicitud en revisión", disabled: true },
        aprobada: { icon: "✓", title: "Solicitud aprobada", text: "Tu solicitud fue aprobada. Esta opción ya no está disponible porque tu cuenta fue autorizada como transportista.", button: "Solicitud aprobada", disabled: true },
        revocada: { icon: "↻", title: "Autorización revocada", text: "La autorización anterior fue revocada. Puedes volver a solicitar una cuenta transportista.", button: "🚕 Solicitar cuenta transportista", disabled: false },
        rechazada: { icon: "!", title: "Solicitud rechazada", text: "Puedes corregir los datos y enviar una nueva solicitud.", button: "Enviar nueva solicitud", disabled: false },
        ninguna: { icon: "🚕", title: "Puedes solicitar el cambio de cuenta", text: "Al enviar la solicitud, la administración podrá revisarla.", button: "🚕 Solicitar cuenta transportista", disabled: false },
        cargando: { icon: "…", title: "Consultando el estado", text: "Estamos comprobando si ya existe una solicitud para tu cuenta.", button: "Comprobando…", disabled: true }
    };
    const view = stateContent[state] || stateContent.ninguna;
    icon.textContent = view.icon;
    title.textContent = view.title;
    text.textContent = message || view.text;
    button.textContent = view.button;
    button.disabled = view.disabled;
    // Mientras está pendiente se oculta el formulario; una autorización aprobada
    // oculta también el acceso del menú. Si se revoca, ambos vuelven a mostrarse.
    if (form) {
        form.hidden = ["pendiente", "aprobada", "transportista", "cargando"].includes(state);
        if (["pendiente", "aprobada", "transportista"].includes(state)) {
            form.querySelectorAll("input, select, button").forEach(control => control.disabled = true);
        } else {
            form.querySelectorAll("input, select").forEach(control => control.disabled = false);
            button.disabled = false;
        }
    }
    if (menuLink) menuLink.hidden = ["aprobada", "transportista"].includes(state);
}

let transportStatusLoaded = false;
let transportStatusSyncInProgress = false;
async function refreshTransportRequestStatus() {
    if (transportStatusSyncInProgress || document.visibilityState === "hidden") return;
    transportStatusSyncInProgress = true;
    if (!transportStatusLoaded) setTransportRequestView("cargando");
    try {
        const result = await apiRequest("transport_request_status", {});
        if (String(result.tipo || "").toLowerCase() === "transportista") {
            setTransportRequestView("transportista");
        } else {
            const state = String(result.estado_solicitud || "").toLowerCase();
            if (["pendiente", "aprobada", "rechazada", "revocada"].includes(state)) setTransportRequestView(state, result.nota_admin ? ("Nota de administración: " + result.nota_admin) : "");
            else setTransportRequestView("ninguna");
        }
        transportStatusLoaded = true;
    } catch (error) {
        if (!transportStatusLoaded) setTransportRequestView("ninguna", "No se pudo consultar el estado. Puedes intentarlo de nuevo al enviar la solicitud.");
        console.warn("No se pudo consultar la solicitud de transportista:", error);
    } finally {
        transportStatusSyncInProgress = false;
    }
}

document.getElementById("transportAccountForm").addEventListener("submit", async event => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = document.getElementById("sendTransportRequestButton");
    if (button.disabled || vehiclePhotoProcessing) return;
    if (!form.reportValidity()) return;
    if (!selectedVehiclePhotoData) {
        showToast("Debes seleccionar una foto del vehículo y esperar a que termine de prepararse.");
        return;
    }
    const payload = {
        tipo_vehiculo: document.getElementById("transportVehicleType").value,
        chapa: document.getElementById("transportVehiclePlate").value.trim(),
        licencia_conduccion: document.getElementById("transportDrivingLicense").value.trim(),
        telefono: document.getElementById("transportPhone").value.trim(),
        foto_vehiculo: selectedVehiclePhotoData
    };
    button.disabled = true;
    button.textContent = "Enviando solicitud…";
    try {
        const result = await apiRequest("request_transport_account", payload);
        const state = String(result.estado_solicitud || "pendiente").toLowerCase();
        if (state === "transportista") setTransportRequestView("transportista", result.message || "Tu cuenta ya es de transportista.");
        else setTransportRequestView("pendiente", result.message || "Tu solicitud se ha enviado y está pendiente de revisión.");
        showToast(result.message || "Solicitud registrada correctamente.");
        form.querySelectorAll("input, select").forEach(control => control.disabled = true);
    } catch (error) {
        button.disabled = false;
        button.textContent = "🚕 Solicitar cuenta transportista";
        const failureMessage = error.message || "No se pudo enviar la solicitud.";
        setTransportRequestView("ninguna", failureMessage);
        showToast(failureMessage);
        // Si la conexión se cortó después de guardar, vuelve a consultar el estado real.
        try {
            await refreshTransportRequestStatus();
            const statusBox = document.getElementById("transportRequestStatus");
            if (statusBox && statusBox.dataset.state === "pendiente") {
                showToast("La solicitud sí quedó registrada y está pendiente de revisión.");
            }
        } catch (_) { /* Se conserva el mensaje del error original. */ }
    }
});

// Mantiene el estado de la solicitud sincronizado para reflejar aprobaciones o revocaciones.
setTimeout(() => { refreshTransportRequestStatus(); }, 0);
setInterval(() => {
    if (document.visibilityState === "visible") refreshTransportRequestStatus();
}, 3000);

// Preparación y vista previa de la foto del vehículo en el formulario de transportista.
let selectedVehiclePhotoData = null;
let vehiclePhotoProcessing = false;
let vehiclePhotoSelectionVersion = 0;
const transportVehiclePhotoInput = document.getElementById("transportVehiclePhoto");
if (transportVehiclePhotoInput) {
  transportVehiclePhotoInput.addEventListener("change", async () => {
    const version = ++vehiclePhotoSelectionVersion;
    const file = transportVehiclePhotoInput.files && transportVehiclePhotoInput.files[0];
    const preview = document.getElementById("transportVehiclePhotoPreview");
    const previewImage = document.getElementById("transportVehiclePhotoPreviewImage");
    selectedVehiclePhotoData = null;
    preview.hidden = true;
    if (!file) return;
    vehiclePhotoProcessing = true;
    showToast("Preparando la foto del vehículo…");
    try {
      const prepared = await prepareRegisterPhoto(file, "del vehículo");
      if (version !== vehiclePhotoSelectionVersion) return;
      selectedVehiclePhotoData = prepared;
      previewImage.src = prepared;
      preview.hidden = false;
      showToast("Foto del vehículo lista para enviar.");
    } catch (error) {
      if (version === vehiclePhotoSelectionVersion) {
        transportVehiclePhotoInput.value = "";
        selectedVehiclePhotoData = null;
        preview.hidden = true;
        showToast(error.message || "No se pudo preparar la foto del vehículo.");
      }
    } finally {
      if (version === vehiclePhotoSelectionVersion) vehiclePhotoProcessing = false;
    }
  });
}

// Autenticación real en este mismo archivo index.php.
const authScreen = document.getElementById("authScreen");
const authControls = document.getElementById("authControls");
const serverStatus = document.getElementById("serverStatus");
const serverStatusTitle = document.getElementById("serverStatusTitle");
const serverStatusMessage = document.getElementById("serverStatusMessage");
let serverConnected = false;
const loginPanel = document.getElementById("loginPanel");
const registerPanel = document.getElementById("registerPanel");
function setAuthMode(mode) {
  const login = mode === "login";
  loginPanel.hidden = !login; registerPanel.hidden = login;
  document.getElementById("forgotPasswordPanel").hidden = true;
  document.getElementById("resetPasswordPanel").hidden = true;
  document.getElementById("loginTab").classList.toggle("active", login);
  document.getElementById("registerTab").classList.toggle("active", !login);
  document.getElementById("loginError").classList.remove("show", "success");
  document.getElementById("registerError").classList.remove("show", "success");
}
function showAuthError(id, message) { const el=document.getElementById(id); el.textContent=message; el.classList.remove("processing"); el.classList.add("show"); }
async function apiRequest(action, data={}) {
  const response = await fetch("index.php?action=" + encodeURIComponent(action), {
    method: "POST", credentials: "same-origin", headers: {"Content-Type":"application/json", "Accept":"application/json"}, body: JSON.stringify(data)
  });
  let result; try { result=await response.json(); } catch (_) { throw new Error("El servidor no devolvió una respuesta válida. Comprueba que index.php está instalado y que el sitio usa PHP."); }
  if (result && result.sessionExpired && result.code === "SESSION_REPLACED") {
    if (currentRadarUser) handleExpiredSession(result.message || "Esta cuenta inició sesión en otro dispositivo. Esta sesión se ha cerrado.");
    throw new Error(result.message || "Esta cuenta inició sesión en otro dispositivo.");
  }
  if (!response.ok || !result.ok) throw new Error(result.message || "No se pudo completar la operación.");
  const notificationActions = new Set(["register", "login", "update_status", "request_transport_account", "admin_review_transport_request", "create_trip_request", "change_password"]);
  if (notificationActions.has(action) && currentRadarUser) {
    setTimeout(() => { loadNotifications(false); }, 0);
  }
  return result;
}
let welcomeTimer = null;
let currentRadarUser = null;
const userDetailsModal = document.getElementById("userDetailsModal");
function activateUserProfileTab(tabName) {
  const personal = tabName !== "account";
  const personalTab = document.getElementById("personalDataTab");
  const accountTab = document.getElementById("accountDataTab");
  const personalPanel = document.getElementById("personalDataPanel");
  const accountPanel = document.getElementById("accountDataPanel");
  personalTab.classList.toggle("active", personal);
  accountTab.classList.toggle("active", !personal);
  personalTab.setAttribute("aria-selected", personal ? "true" : "false");
  accountTab.setAttribute("aria-selected", personal ? "false" : "true");
  personalPanel.hidden = !personal;
  accountPanel.hidden = personal;
}
document.getElementById("personalDataTab").addEventListener("click", () => activateUserProfileTab("personal"));
document.getElementById("accountDataTab").addEventListener("click", () => activateUserProfileTab("account"));
const profileAvatarButton = document.getElementById("profileAvatar");

function setDetailText(id, value, fallback = "—") {
  const el = document.getElementById(id);
  if (el) el.textContent = (value !== undefined && value !== null && String(value).trim() !== "") ? String(value) : fallback;
}

function setDetailsPhoto(target, user, name) {
  target.replaceChildren();
  if (user && typeof user.foto === "string" && /^data:image\/(jpeg|png|webp);base64,/.test(user.foto)) {
    const img = document.createElement("img");
    img.src = user.foto;
    img.alt = "Foto de perfil de " + name;
    target.appendChild(img);
  } else {
    target.textContent = (name || user?.usuario || "U").trim().charAt(0).toUpperCase() || "U";
  }
}

function openUserDetails(refreshOnly = false) {
  // No silenciosamente si la sesión todavía no terminó de sincronizarse.
  if (!currentRadarUser) {
    if (!refreshOnly) ensureUserDetailsAndOpen();
    return;
  }
  if (!refreshOnly) { resetDeleteAccountPanel(); activateUserProfileTab("personal"); }
  const user = currentRadarUser;
  const name = [user.nombre, user.apellidos].filter(Boolean).join(" ") || user.usuario || "Usuario";
  const saldo = Number(user.saldo || 0);
  const saldoText = "$" + (Number.isFinite(saldo) ? saldo : 0).toLocaleString("es-MX", {minimumFractionDigits:2, maximumFractionDigits:2});
  const estado = user.estado || "Sin especificar";
  const estadoLower = String(estado).toLocaleLowerCase("es-MX");

  setDetailText("detailsUsername", user.usuario);
  setDetailText("detailsFullName", name);
  setDetailText("detailsIdentityCard", user.carnet_identidad, "No disponible");
  setDetailText("detailsPhone", user.telefono, "No disponible");
  setDetailText("detailsEmail", user.correo, "No disponible");
  setDetailText("detailsProvince", user.provincia, "No disponible");
  setDetailText("detailsMunicipality", user.municipio, "No disponible");
  syncProfileEditFields(user);
  setDetailText("detailsBalance", saldoText);
  setDetailText("detailsAccountType", user.tipo || "Sin especificar");
  const parseAccountDate = value => {
    if (!value) return null;
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
    if (match) return new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4] || 0), Number(match[5] || 0), Number(match[6] || 0));
    const parsed = new Date(value);
    return Number.isNaN(parsed.getTime()) ? null : parsed;
  };
  const formatAccountDate = value => {
    const date = parseAccountDate(value);
    return !date ? "No disponible" : date.toLocaleDateString("es-ES", {day:"2-digit", month:"long", year:"numeric"});
  };
  const createdAt = parseAccountDate(user.fecha_creacion);
  if (!createdAt) {
    setDetailText("detailsAvailableTime", "No disponible");
  } else {
    const now = new Date();
    let years = now.getFullYear() - createdAt.getFullYear();
    let months = now.getMonth() - createdAt.getMonth();
    let days = now.getDate() - createdAt.getDate();
    if (days < 0) {
      months--;
      const previousMonthDays = new Date(now.getFullYear(), now.getMonth(), 0).getDate();
      days += previousMonthDays;
    }
    if (months < 0) { years--; months += 12; }
    if (now < createdAt) {
      years = 0; months = 0; days = 0;
    }
    const elapsedParts = [];
    if (years) elapsedParts.push(years + (years === 1 ? " año" : " años"));
    if (months) elapsedParts.push(months + (months === 1 ? " mes" : " meses"));
    if (days || elapsedParts.length === 0) elapsedParts.push(days + (days === 1 ? " día" : " días"));
    setDetailText("detailsAvailableTime", elapsedParts.join(", "));
  }
  setDetailText("detailsCreatedDate", formatAccountDate(user.fecha_creacion));
  const accountType = String(user.tipo || "").toLowerCase();
  const expiryValue = user.pago_hasta || user.prueba_fin || null;
  if (accountType === "admin") {
    setDetailText("detailsExpiryDate", "No aplica");
    setDetailText("detailsDaysRemaining", "Sin límite");
  } else {
    setDetailText("detailsExpiryDate", formatAccountDate(expiryValue));
    const remaining = expiryValue ? Math.max(0, Math.ceil((new Date(String(expiryValue).replace(" ", "T")).getTime() - Date.now()) / 86400000)) : null;
    setDetailText("detailsDaysRemaining", remaining === null ? "No disponible" : (remaining === 1 ? "1 día" : remaining + " días"));
  }
  setDetailText("userDetailsFullName", name, "Usuario");
  setDetailText("userDetailsAtName", user.usuario ? "@" + user.usuario : "Usuario");
  setDetailText("userDetailsStateBadge", estado);
  document.getElementById("userDetailsStateBadge").classList.toggle("inactive", estadoLower !== "activo");
  setDetailsPhoto(document.getElementById("userDetailsMainPhoto"), user, name);
  loadProfileArrivalRadius();

  const photoValue = document.getElementById("detailsPhotoValue");
  photoValue.replaceChildren();
  if (user.foto && typeof user.foto === "string" && /^data:image\/(jpeg|png|webp);base64,/.test(user.foto)) {
    const img = document.createElement("img");
    img.src = user.foto;
    img.alt = "Foto de perfil";
    photoValue.appendChild(img);
    const label = document.createElement("span");
    label.textContent = "Foto de perfil registrada";
    photoValue.appendChild(label);
  } else {
    photoValue.textContent = "Sin foto";
  }

  if (!refreshOnly) {
    userDetailsModal.hidden = false;
    document.body.classList.add("user-details-open");
    document.getElementById("userDetailsClose").focus();
  }
}

function closeUserDetails() {
  userDetailsModal.hidden = true;
  document.body.classList.remove("user-details-open");
  resetDeleteAccountPanel();
  if (profileAvatarButton && !document.getElementById("authScreen").hidden) return;
  if (profileAvatarButton) profileAvatarButton.focus();
}

function resetDeleteAccountPanel() {
  const panel = document.getElementById("deleteAccountPanel");
  const password = document.getElementById("deleteAccountPassword");
  const error = document.getElementById("deleteAccountError");
  if (panel) panel.hidden = true;
  if (password) password.value = "";
  if (error) { error.hidden = true; error.textContent = ""; }
  const startButton = document.getElementById("startDeleteAccount");
  if (startButton) startButton.hidden = false;
}

document.getElementById("startDeleteAccount").addEventListener("click", () => {
  activateUserProfileTab("account");
  const panel = document.getElementById("deleteAccountPanel");
  const password = document.getElementById("deleteAccountPassword");
  const error = document.getElementById("deleteAccountError");
  panel.hidden = false;
  error.hidden = true;
  error.textContent = "";
  password.value = "";
  document.getElementById("startDeleteAccount").hidden = true;
  password.focus();
});

document.getElementById("cancelDeleteAccount").addEventListener("click", resetDeleteAccountPanel);
document.getElementById("confirmDeleteAccount").addEventListener("click", async () => {
  const password = document.getElementById("deleteAccountPassword").value;
  const error = document.getElementById("deleteAccountError");
  const button = document.getElementById("confirmDeleteAccount");
  if (!password) {
    error.textContent = "Introduce tu contraseña para continuar.";
    error.hidden = false;
    document.getElementById("deleteAccountPassword").focus();
    return;
  }

  button.disabled = true;
  button.textContent = "Eliminando cuenta…";
  try {
    const result = await apiRequest("delete_account", {contrasena: password});
    stopMobilityTracking();
    stopNotificationSync();
    currentRadarUser = null;
    closeUserDetails();
    hideWelcome();
    document.getElementById("authScreen").hidden = false;
    document.body.classList.add("auth-locked");
    setAuthMode("login");
    document.getElementById("loginUser").value = "";
    document.getElementById("loginPass").value = "";
    const loginMessage = document.getElementById("loginError");
    loginMessage.classList.add("success");
    showAuthError("loginError", result.message || "Tu cuenta ha sido eliminada correctamente.");
  } catch (err) {
    error.textContent = err.message || "No se pudo eliminar la cuenta.";
    error.hidden = false;
  } finally {
    button.disabled = false;
    button.textContent = "Eliminar definitivamente";
  }
});

document.getElementById("deleteAccountPassword").addEventListener("keydown", event => {
  if (event.key === "Enter") {
    event.preventDefault();
    document.getElementById("confirmDeleteAccount").click();
  }
});

async function ensureUserDetailsAndOpen() {
  if (currentRadarUser) {
    openUserDetails();
    return;
  }
  // Si el perfil aún no se ha cargado localmente, solicitarlo a la sesión del servidor.
  try {
    const result = await apiRequest("sync");
    if (!result.user) {
      showToast("Tu sesión no está activa. Inicia sesión nuevamente.");
      return;
    }
    updateUserUI(result.user);
    openUserDetails();
  } catch (error) {
    showToast(error.message || "No se pudieron cargar los datos del usuario.");
  }
}


profileAvatarButton.addEventListener("click", event => {
  event.preventDefault();
  ensureUserDetailsAndOpen();
});
profileAvatarButton.addEventListener("keydown", event => {
  if (event.key === "Enter" || event.key === " ") {
    event.preventDefault();
    ensureUserDetailsAndOpen();
  }
});
document.getElementById("userDetailsClose").addEventListener("click", closeUserDetails);
document.getElementById("userDetailsDone").addEventListener("click", closeUserDetails);
document.getElementById("userDetailsBackdrop").addEventListener("click", closeUserDetails);
document.addEventListener("keydown", event => {
  if (event.key === "Escape" && !userDetailsModal.hidden) closeUserDetails();
});

let pendingTrialNotice = null;
let trialNoticeTimer = null;
function hideTrialNotice() {
  const overlay = document.getElementById("trialNoticeOverlay");
  if (overlay) overlay.hidden = true;
  if (trialNoticeTimer) { clearTimeout(trialNoticeTimer); trialNoticeTimer = null; }
}
function showTrialNotice() {
  if (!pendingTrialNotice) return;
  const notice = pendingTrialNotice;
  pendingTrialNotice = null;
  const overlay = document.getElementById("trialNoticeOverlay");
  const card = document.getElementById("trialNoticeCard");
  const expired = !!notice.expired;
  card.classList.toggle("trial-notice-expired", expired);
  document.getElementById("trialNoticeIcon").textContent = expired ? "⏳" : "🎁";
  document.getElementById("trialNoticeEyebrow").textContent = expired ? "PERIODO DE PRUEBA FINALIZADO" : "BENEFICIO DE BIENVENIDA";
  document.getElementById("trialNoticeTitle").textContent = expired ? "Tu prueba ha terminado" : "¡Tu prueba gratuita te espera!";
  document.getElementById("trialNoticeSubtitle").textContent = expired ? "Para seguir utilizando El Radar, necesitas recargar tu cuenta." : "Disfruta de El Radar durante tu periodo de prueba gratuito.";
  document.getElementById("trialNoticeDays").textContent = expired ? "0" : String(notice.days);
  document.getElementById("trialNoticeDaysLabel").textContent = expired ? "días restantes" : "días gratis";
  document.getElementById("trialNoticeSummaryTitle").textContent = expired ? "Es necesario recargar" : "Prueba gratuita activa";
  document.getElementById("trialNoticeSummaryText").textContent = expired ? "Tu cuenta está inactiva hasta que se confirme una recarga." : "Durante estos días no se aplica la tarifa de uso de la aplicación.";
  const dateRow = document.getElementById("trialNoticeDate");
  dateRow.hidden = !notice.date;
  document.getElementById("trialNoticeDateValue").textContent = notice.date || "—";
  document.getElementById("trialNoticeFootnote").textContent = expired ? "Tu cuenta permanecerá inactiva hasta que la recarga sea confirmada. Puedes consultar las opciones en Recargar cuenta." : "Cuando finalice este periodo, será necesario recargar tu cuenta para continuar usando la aplicación.";
  document.getElementById("trialNoticePrimary").textContent = expired ? "Ir a Recargar cuenta →" : "Comenzar a usar El Radar →";
  document.getElementById("trialNoticeSecondary").hidden = expired;
  overlay.hidden = false;
  document.getElementById("trialNoticePrimary").onclick = () => { hideTrialNotice(); if (expired) showPage('recargar'); };
  document.getElementById("trialNoticeSecondary").onclick = hideTrialNotice;
  if (trialNoticeTimer) clearTimeout(trialNoticeTimer);
}
function hideWelcome() {
  const welcome = document.getElementById("welcomeScreen");
  welcome.classList.remove("show");
  welcome.hidden = true;
  if (welcomeTimer) { clearTimeout(welcomeTimer); welcomeTimer = null; }
  if (pendingTrialNotice) {
    if (trialNoticeTimer) clearTimeout(trialNoticeTimer);
    trialNoticeTimer = setTimeout(showTrialNotice, 220);
  }
}
function showWelcome(user, name) {
  const welcome = document.getElementById("welcomeScreen");
  const avatar = document.getElementById("welcomeAvatar");
  document.getElementById("welcomeName").textContent = name;
  avatar.replaceChildren();
  if (user.foto && /^data:image\/(jpeg|png|webp);base64,/.test(user.foto)) {
    const image = document.createElement("img");
    image.src = user.foto;
    image.alt = "Foto de perfil de " + name;
    avatar.appendChild(image);
  } else {
    avatar.textContent = name.charAt(0).toUpperCase();
  }
  welcome.hidden = false;
  welcome.classList.remove("show");
  void welcome.offsetWidth;
  welcome.classList.add("show");
  if (welcomeTimer) clearTimeout(welcomeTimer);
  welcomeTimer = setTimeout(hideWelcome, 4200);
}
document.getElementById("welcomeClose").addEventListener("click", hideWelcome);
document.getElementById("welcomeContinue").addEventListener("click", hideWelcome);
/* GPS del dispositivo: se guarda en USUARIOS.UBICACION solo si la cuenta sigue activa. */
let mobilityMap = null;
let userLocationMarker = null;
let nearbyTransporterMarkers = new Map();
let nearbyTransportersLatestItems = [];
let nearbyTransportersDimmed = false;
let selectedMobilityVehicleType = "";
let nearbyTransportersFetchAt = 0;
let nearbyTransportersFetchInProgress = false;
let nearbyTransportersTimer = null;
let userAccuracyCircle = null;
let userRangeCircle = null;
let gpsWatchId = null;
let locationHeartbeatTimer = null;
let lastGpsPosition = null;
let firstGpsFixReceived = false;
let followGpsLocation = true;
let centerOnNextGpsFix = false;
let gpsPermissionPrompted = false;

function setGpsStatus(message, state = "") {
  const el = document.getElementById("gpsStatus");
  if (!el) return;
  el.textContent = message;
  el.classList.toggle("active", state === "active");
  el.classList.toggle("error", state === "error");
}

function initMobilityMap() {
  if (mobilityMap) return true;
  const mapElement = document.getElementById("mobilityMap");
  if (!mapElement) return false;
  if (typeof window.L === "undefined") {
    setGpsStatus("No se pudo cargar el mapa. Comprueba la conexión a Internet.", "error");
    return false;
  }
  try {
    mobilityMap = L.map(mapElement, {
      zoomControl: true,
      scrollWheelZoom: false,
      attributionControl: true,
      minZoom: 15,
      maxZoom: 19,
      maxBoundsViscosity: 1
    }).setView([20, 0], 2);
    // La foto del marcador es solo visual: pulsarla no abre diálogos ni fichas.
    // Se oculta el crédito de Leaflet; se conserva el de OpenStreetMap porque sus mapas lo requieren.
    mobilityMap.attributionControl.setPrefix(false);
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
      maxZoom: 19,
      attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>'
    }).addTo(mobilityMap);
    mobilityMap.on("dragstart", () => { followGpsLocation = false; });
    setTimeout(() => { if (mobilityMap) mobilityMap.invalidateSize(); }, 150);
    return true;
  } catch (error) {
    console.error("El Radar map:", error);
    setGpsStatus("No se pudo iniciar el mapa. Recarga la página y vuelve a intentarlo.", "error");
    mobilityMap = null;
    return false;
  }
}

function currentUserDisplayName() {
  if (!currentRadarUser) return "Usuario";
  return [currentRadarUser.nombre, currentRadarUser.apellidos].filter(Boolean).join(" ") || currentRadarUser.usuario || "Usuario";
}

function makeGpsAvatarIcon() {
  const user = currentRadarUser || {};
  const photo = typeof user.foto === "string" && /^data:image\/(jpeg|png|webp);base64,/.test(user.foto) ? user.foto : "";
  const initial = (currentUserDisplayName().trim().charAt(0) || "U").toUpperCase();
  const avatarContent = photo
    ? '<img src="' + photo + '" alt="">'
    : '<span>' + initial.replace(/[&<>"']/g, "") + '</span>';
  return L.divIcon({
    className: "gps-div-icon",
    html: '<div class="gps-marker-wrap"><div class="gps-avatar-marker">' + avatarContent + '</div><span class="gps-marker-pointer"></span></div>',
    iconSize: [56, 63],
    iconAnchor: [28, 55],
    popupAnchor: [0, -52]
  });
}

function refreshUserLocationMarker() {
  if (!userLocationMarker || typeof window.L === "undefined") return;
  try {
    userLocationMarker.setIcon(makeGpsAvatarIcon());
  } catch (error) { console.warn("No se pudo enlazar el clic del marcador:", error); }
}

let lastAddressLookupAt = 0;
let lastAddressLookupCoords = null;
let addressLookupTimer = null;
let lastKnownGpsAddress = '';
let lastLocationPersistAt = 0;
let lastLocationPersistCoords = null;
let lastLocationPersistAddress = '';
let locationPersistInProgress = false;
let pendingLocationPersist = null;

function distanceBetweenMeters(a, b) {
  if (!a || !b) return Infinity;
  const rad = value => value * Math.PI / 180;
  const dLat = rad(b.lat - a.lat);
  const dLng = rad(b.lng - a.lng);
  const lat1 = rad(a.lat), lat2 = rad(b.lat);
  const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
  return 6371000 * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
}

async function persistGpsLocation(position, address = '') {
  if (!position || !currentRadarUser || String(currentRadarUser.estado || '').trim().toLocaleLowerCase('es-MX') !== 'activo') return;
  const lat = Number(position.lat);
  const lng = Number(position.lng);
  const accuracy = Number(position.accuracy || 0);
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
  const normalizedAddress = typeof address === 'string' ? address.trim().slice(0, 500) : '';
  const now = Date.now();
  // La base de datos recibe como máximo una actualización GPS cada 60 segundos.
  // La primera posición se guarda inmediatamente.
  if (lastLocationPersistAt > 0 && now - lastLocationPersistAt < 60000) return;

  pendingLocationPersist = {lat, lng, accuracy, address: normalizedAddress};
  if (locationPersistInProgress) return;
  locationPersistInProgress = true;
  try {
    while (pendingLocationPersist) {
      const payload = pendingLocationPersist;
      pendingLocationPersist = null;
      if (!currentRadarUser || String(currentRadarUser.estado || '').trim().toLocaleLowerCase('es-MX') !== 'activo') break;
      // Revalida el intervalo justo antes de escribir para evitar duplicados.
      const writeNow = Date.now();
      if (lastLocationPersistAt > 0 && writeNow - lastLocationPersistAt < 60000) continue;
      await apiRequest('location_update', payload);
      lastLocationPersistAt = Date.now();
      lastLocationPersistCoords = {lat: payload.lat, lng: payload.lng};
      lastLocationPersistAddress = payload.address || lastLocationPersistAddress;
    }
  } catch (error) {
    console.warn('No se pudo actualizar USUARIOS.UBICACION:', error.message || error);
  } finally {
    locationPersistInProgress = false;
    if (pendingLocationPersist && currentRadarUser && String(currentRadarUser.estado || '').trim().toLocaleLowerCase('es-MX') === 'activo') {
      const pending = pendingLocationPersist;
      pendingLocationPersist = null;
      persistGpsLocation(pending, pending.address);
    }
  }
}

function lookupGpsAddress(lat, lng, force = false) {
  const addressEl = document.getElementById("gpsAddress");
  if (!addressEl || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
  const coords = { lat, lng };
  if (!force && lastAddressLookupCoords && distanceBetweenMeters(lastAddressLookupCoords, coords) < 60) return;
  if (addressLookupTimer) clearTimeout(addressLookupTimer);
  const wait = Math.max(0, 1100 - (Date.now() - lastAddressLookupAt));
  addressLookupTimer = setTimeout(async () => {
    addressEl.textContent = "Buscando la dirección de esta ubicación…";
    lastAddressLookupAt = Date.now();
    lastAddressLookupCoords = coords;
    try {
      const url = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=" + encodeURIComponent(lat) + "&lon=" + encodeURIComponent(lng) + "&zoom=18&addressdetails=1";
      const response = await fetch(url, { method: "GET", headers: { "Accept": "application/json" }, cache: "no-store" });
      if (!response.ok) throw new Error("No se pudo consultar la dirección.");
      const data = await response.json();
      const address = data && (data.display_name || (data.address && Object.values(data.address).filter(Boolean).join(", ")));
      addressEl.textContent = address || "No se encontró una dirección para estas coordenadas.";
      if (address) {
        lastKnownGpsAddress = String(address).slice(0, 500);
        // Asocia la dirección a la posición consultada solo si sigue siendo próxima a la posición actual.
        if (lastGpsPosition && distanceBetweenMeters(lastGpsPosition, coords) < 100) {
          persistGpsLocation(lastGpsPosition, lastKnownGpsAddress);
        }
      }
    } catch (error) {
      addressEl.textContent = "No se pudo obtener la dirección. Comprueba tu conexión a Internet e inténtalo de nuevo.";
    }
  }, wait);
}

function updateOneKilometreMapArea(lat, lng) {
  if (!mobilityMap || typeof window.L === "undefined" || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
  // El área navegable se restringe al entorno de 1 km desde la posición GPS.
  const radiusMeters = 1000;
  const latDelta = radiusMeters / 111320;
  const cosLat = Math.max(Math.abs(Math.cos(lat * Math.PI / 180)), 0.01);
  const lngDelta = radiusMeters / (111320 * cosLat);
  const bounds = L.latLngBounds(
    [Math.max(-90, lat - latDelta), Math.max(-180, lng - lngDelta)],
    [Math.min(90, lat + latDelta), Math.min(180, lng + lngDelta)]
  );
  mobilityMap.setMaxBounds(bounds);
  mobilityMap.options.maxBoundsViscosity = 1;

  const center = [lat, lng];
  if (userRangeCircle) {
    userRangeCircle.setLatLng(center);
    userRangeCircle.setRadius(radiusMeters);
  } else {
    userRangeCircle = L.circle(center, {
      radius: radiusMeters,
      color: "#6478ee",
      weight: 1.5,
      opacity: 0.8,
      dashArray: "6 6",
      fillColor: "#6478ee",
      fillOpacity: 0.035,
      interactive: false
    }).addTo(mobilityMap);
  }
}

function removeNearbyTransporterMarkers() {
  if (mobilityMap) {
    for (const marker of nearbyTransporterMarkers.values()) {
      try { mobilityMap.removeLayer(marker); } catch (_) {}
    }
  }
  nearbyTransporterMarkers.clear();
}

function validMapPhoto(value) {
  return typeof value === "string" && /^data:image\/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$/.test(value) ? value : "";
}


let selectedTripTransporter = null;

function setTransporterDialogText(id, value) {
  const element = document.getElementById(id);
  if (element) element.textContent = String(value == null || String(value).trim() === "" ? "No disponible" : value);
}
function openTransporterDialog(transporter) {
  if (!transporter) return;
  const backdrop = document.getElementById("transporterDialogBackdrop");
  const vehiclePhoto = document.getElementById("transporterDialogVehiclePhoto");
  const vehicleFallback = document.getElementById("transporterDialogVehicleFallback");
  const driverPhoto = document.getElementById("transporterDialogDriverPhoto");
  const validVehicle = validMapPhoto(transporter.foto_vehiculo);
  const validDriver = validMapPhoto(transporter.foto_usuario);
  const displayName = String(transporter.nombre || transporter.usuario || "Transportista");
  setTransporterDialogText("transporterDialogName", displayName);
  setTransporterDialogText("transporterDialogType", transporter.tipo_vehiculo);
  setTransporterDialogText("transporterDialogPlate", transporter.chapa);
  setTransporterDialogText("transporterDialogLicense", transporter.licencia_conduccion);
  const meters = Math.max(0, Number(transporter.distancia_m) || 0);
  setTransporterDialogText("transporterDialogDistance", meters < 1000 ? meters.toLocaleString("es-MX") + " m" : (meters / 1000).toLocaleString("es-MX", {maximumFractionDigits: 1}) + " km");
  if (validVehicle) {
    vehiclePhoto.src = validVehicle;
    vehiclePhoto.hidden = false;
    vehicleFallback.hidden = true;
  } else {
    vehiclePhoto.removeAttribute("src");
    vehiclePhoto.hidden = true;
    vehicleFallback.hidden = false;
    vehicleFallback.textContent = "Foto del vehículo no disponible";
  }
  driverPhoto.replaceChildren();
  if (validDriver) {
    const image = document.createElement("img");
    image.src = validDriver;
    image.alt = "Fotografía de " + displayName;
    driverPhoto.appendChild(image);
  } else {
    driverPhoto.textContent = "👤";
  }
  selectedTripTransporter = transporter;
  const request = document.getElementById("transporterDialogRequest");
  if (request) {
    request.disabled = false;
    request.classList.remove("disabled");
    request.removeAttribute("aria-disabled");
  }
  backdrop.classList.add("open");
  backdrop.setAttribute("aria-hidden", "false");
  document.body.style.overflow = "hidden";
  document.getElementById("transporterDialogClose")?.focus();
}
function closeTransporterDialog() {
  const backdrop = document.getElementById("transporterDialogBackdrop");
  if (!backdrop) return;
  backdrop.classList.remove("open");
  backdrop.setAttribute("aria-hidden", "true");
  document.body.style.overflow = "";
}
// Delegación en document: el HTML del diálogo está después de este script.
document.addEventListener("click", event => {
  const closeButton = event.target && event.target.closest ? event.target.closest("#transporterDialogClose") : null;
  const backdrop = document.getElementById("transporterDialogBackdrop");
  if (closeButton) {
    event.preventDefault();
    closeTransporterDialog();
    return;
  }
  if (backdrop && event.target === backdrop) closeTransporterDialog();
});
document.addEventListener("keydown", event => {
  if (event.key === "Escape") {
    closeTripRequestDialog();
    closeTransporterDialog();
  }
});

function openTripRequestDialog() {
  if (!selectedTripTransporter) return;
  const backdrop = document.getElementById("tripRequestBackdrop");
  const name = selectedTripTransporter.nombre || selectedTripTransporter.usuario || "Transportista";
  document.getElementById("tripRequestTransporterName").textContent = "Solicitud para " + name + ". Indica los datos del viaje.";
  document.getElementById("tripRequestForm").reset();
  document.getElementById("tripRequestPeople").value = "1";
  backdrop.classList.add("open");
  backdrop.setAttribute("aria-hidden", "false");
  document.getElementById("tripRequestDestination").focus();
}
function closeTripRequestDialog() {
  const backdrop = document.getElementById("tripRequestBackdrop");
  if (!backdrop) return;
  backdrop.classList.remove("open");
  backdrop.setAttribute("aria-hidden", "true");
}
// Delegación de eventos: estos diálogos están al final del documento, después del script principal.
// Los listeners directos aquí fallaban porque los elementos aún no existían al ejecutar el script.
document.addEventListener("click", event => {
  const requestButton = event.target && event.target.closest ? event.target.closest("#transporterDialogRequest") : null;
  const closeButton = event.target && event.target.closest ? event.target.closest("#tripRequestClose, #tripRequestCancel") : null;
  const tripBackdrop = document.getElementById("tripRequestBackdrop");
  if (requestButton) {
    event.preventDefault();
    // Mantener el transportista seleccionado y cambiar de ficha al formulario.
    closeTransporterDialog();
    openTripRequestDialog();
    return;
  }
  if (closeButton) {
    event.preventDefault();
    closeTripRequestDialog();
    return;
  }
  if (tripBackdrop && event.target === tripBackdrop) closeTripRequestDialog();
});

document.addEventListener("submit", async event => {
  const form = event.target;
  if (!form || form.id !== "tripRequestForm") return;
  event.preventDefault();
  if (!selectedTripTransporter || !Number(selectedTripTransporter.id)) {
    showToast("No se pudo identificar al transportista. Cierra la ficha y selecciónalo nuevamente en el mapa.");
    return;
  }
  const submit = document.getElementById("tripRequestSubmit");
  if (!submit || submit.disabled) return;
  submit.disabled = true;
  submit.textContent = "Enviando…";
  try {
    const result = await apiRequest("create_trip_request", {
      transportista_id: Number(selectedTripTransporter.id),
      destino: document.getElementById("tripRequestDestination").value.trim(),
      personas: Number(document.getElementById("tripRequestPeople").value),
      observaciones: document.getElementById("tripRequestNotes").value.trim(),
      origen_lat: lastGpsPosition ? lastGpsPosition.lat : null,
      origen_lng: lastGpsPosition ? lastGpsPosition.lng : null,
      origen_direccion: lastKnownGpsAddress || ""
    });
    closeTripRequestDialog();
    closeTransporterDialog();
    showToast(result.message || "Solicitud enviada al transportista.");
    if (typeof loadNotifications === "function") loadNotifications(false);
  } catch (error) {
    showToast(error && error.message ? error.message : "No se pudo enviar la solicitud.");
  } finally {
    submit.disabled = false;
    submit.textContent = "Enviar solicitud";
  }
});

function makeTransporterMapIcon(transporter) {
  const photo = validMapPhoto(transporter.foto_marcador || transporter.foto_vehiculo || transporter.foto_usuario);
  const label = String(transporter.tipo_vehiculo || "Transportista").replace(/[&<>\"']/g, "");
  const fallback = label.toLocaleLowerCase("es-MX").includes("moto") ? "🏍️" : "🚕";
  const image = photo ? '<img src="' + photo + '" alt="">' : '<span aria-hidden="true">' + fallback + '</span>';
  return L.divIcon({
    className: "transport-div-icon",
    html: '<div class="transport-marker-wrap"><div class="transport-photo-marker">' + image + '</div><span class="transport-marker-pointer"></span></div>',
    iconSize: [58, 64],
    iconAnchor: [29, 55]
  });
}

function updateMobilityVehicleFilter(items) {
  const select = document.getElementById("mobilityVehicleFilter");
  if (!select) return;
  const current = selectedMobilityVehicleType;
  const types = [...new Set((Array.isArray(items) ? items : [])
    .map(item => String(item.tipo_vehiculo || "").trim())
    .filter(Boolean))].sort((a, b) => a.localeCompare(b, "es-MX", {sensitivity:"base"}));
  select.innerHTML = "";
  const allOption = document.createElement("option");
  allOption.value = "";
  allOption.textContent = "Todos los tipos de transporte";
  select.appendChild(allOption);
  types.forEach(type => {
    const option = document.createElement("option");
    option.value = type;
    option.textContent = type;
    select.appendChild(option);
  });
  // Conserva la selección aunque temporalmente no haya unidades de ese tipo cerca.
  if (current && !types.some(type => type.toLocaleLowerCase("es-MX") === current.toLocaleLowerCase("es-MX"))) {
    const option = document.createElement("option");
    option.value = current;
    option.textContent = current;
    select.appendChild(option);
  }
  select.value = current;
}

function applyMobilityVehicleFilter() {
  renderNearbyTransporters(nearbyTransportersLatestItems);
}

function renderNearbyTransporters(items, total = null, diagnostic = null) {
  if (!mobilityMap || typeof window.L === "undefined") return;
  const status = document.getElementById("nearbyTransportersStatus");
  const received = Array.isArray(items) ? items : [];
  nearbyTransportersLatestItems = received;
  updateMobilityVehicleFilter(received);
  const selected = selectedMobilityVehicleType.trim().toLocaleLowerCase("es-MX");
  const filtered = selected
    ? received.filter(item => String(item.tipo_vehiculo || "").trim().toLocaleLowerCase("es-MX") === selected)
    : received;
  const liveIds = new Set();
  filtered.forEach(transporter => {
    const id = Number(transporter.id);
    const lat = Number(transporter.lat), lng = Number(transporter.lng);
    if (!Number.isFinite(id) || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
    liveIds.add(id);
    const distance = Math.max(0, Number(transporter.distancia_m) || 0);
    const kind = String(transporter.tipo_vehiculo || "Transportista");
    const title = kind + " · " + distance.toLocaleString("es-MX") + " m";
    const coords = [lat, lng];
    let marker = nearbyTransporterMarkers.get(id);
    if (!marker) {
      marker = L.marker(coords, {
        icon: makeTransporterMapIcon(transporter),
        title,
        keyboard: true,
        riseOnHover: true,
        interactive: true,
        bubblingMouseEvents: false,
        zIndexOffset: 250
      }).addTo(mobilityMap);
      marker.on("click", () => openTransporterDialog(marker._transporter));
      marker._transporter = transporter;
      nearbyTransporterMarkers.set(id, marker);
    } else {
      marker.setLatLng(coords);
      marker.setIcon(makeTransporterMapIcon(transporter));
      marker._transporter = transporter;
      marker.options.title = title;
      if (!mobilityMap.hasLayer(marker)) marker.addTo(mobilityMap);
    }
    const markerElement = marker.getElement && marker.getElement();
    if (markerElement) markerElement.classList.toggle("transport-marker-dimmed", nearbyTransportersDimmed);
  });
  for (const [id, marker] of nearbyTransporterMarkers.entries()) {
    if (!liveIds.has(id)) {
      try { mobilityMap.removeLayer(marker); } catch (_) {}
      nearbyTransporterMarkers.delete(id);
    }
  }
  if (status) {
    const count = liveIds.size;
    if (count === 0 && selected) {
      status.textContent = "No hay transportistas del tipo «" + selectedMobilityVehicleType + "» activos dentro de 1 km en este momento.";
    } else if (count === 0) {
      status.textContent = (diagnostic && diagnostic.mensaje) || "No hay transportistas activos dentro de 1 km en este momento.";
    } else {
      status.textContent = count.toLocaleString("es-MX") + (count === 1 ? " transportista activo" : " transportistas activos") + (selected ? " de tipo «" + selectedMobilityVehicleType + "»" : "") + " a menos de 1 km. Las fotos de los marcadores no se abren al pulsarlas.";
    }
  }
}

const mobilityVehicleFilter = document.getElementById("mobilityVehicleFilter");
if (mobilityVehicleFilter) {
  mobilityVehicleFilter.addEventListener("change", () => {
    selectedMobilityVehicleType = String(mobilityVehicleFilter.value || "").trim();
    applyMobilityVehicleFilter();
  });
}

async function refreshNearbyTransportistas(force = false) {
  if (!currentRadarUser || !mobilityMap || !lastGpsPosition) return;
  if (String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX") !== "activo") return;
  const now = Date.now();
  if (!force && (now - nearbyTransportersFetchAt < 2500 || nearbyTransportersFetchInProgress)) return;
  if (nearbyTransportersFetchInProgress) return;
  nearbyTransportersFetchInProgress = true;
  nearbyTransportersFetchAt = now;
  const status = document.getElementById("nearbyTransportersStatus");
  if (status && nearbyTransporterMarkers.size === 0) status.textContent = "Buscando transportistas activos dentro de 1 km…";
  try {
    const result = await apiRequest("nearby_transportistas", {lat:lastGpsPosition.lat, lng:lastGpsPosition.lng});
    nearbyTransportersDimmed = !!result.atenuar_transportistas;
    renderNearbyTransporters(result.transportistas || [], result.total, result.diagnostico || null);
  } catch (error) {
    console.warn("No se pudieron actualizar los transportistas cercanos:", error.message || error);
    if (status && nearbyTransporterMarkers.size === 0) status.textContent = "No se pudieron consultar los transportistas. Comprueba la conexión.";
  } finally {
    nearbyTransportersFetchInProgress = false;
  }
}

function startNearbyTransportersSync() {
  if (nearbyTransportersTimer) clearInterval(nearbyTransportersTimer);
  nearbyTransportersTimer = setInterval(() => {
    if (document.visibilityState !== "hidden" && currentRadarUser && lastGpsPosition) refreshNearbyTransportistas();
  }, 3000);
}

function handleGpsPosition(position) {
  if (!currentRadarUser || String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX") !== "activo") {
    setGpsStatus("Cuenta inactiva · el GPS está detenido.", "error");
    return;
  }
  const coords = position && position.coords;
  if (!coords || !Number.isFinite(coords.latitude) || !Number.isFinite(coords.longitude)) {
    setGpsStatus("El dispositivo no devolvió una ubicación GPS válida.", "error");
    return;
  }
  lastGpsPosition = {
    lat: coords.latitude,
    lng: coords.longitude,
    accuracy: Number.isFinite(coords.accuracy) ? coords.accuracy : 0
  };
  if (!initMobilityMap()) return;

  const latlng = [lastGpsPosition.lat, lastGpsPosition.lng];
  updateOneKilometreMapArea(lastGpsPosition.lat, lastGpsPosition.lng);
  lookupGpsAddress(lastGpsPosition.lat, lastGpsPosition.lng);
  persistGpsLocation(lastGpsPosition, lastKnownGpsAddress);
  refreshNearbyTransportistas();
  if (!userLocationMarker) {
    userLocationMarker = L.marker(latlng, {
      icon: makeGpsAvatarIcon(),
      title: "Tu ubicación actual",
      keyboard: false,
      riseOnHover: false,
      interactive: false
    }).addTo(mobilityMap);
  } else {
    userLocationMarker.setLatLng(latlng);
    refreshUserLocationMarker();
  }

  if (userAccuracyCircle) {
    userAccuracyCircle.setLatLng(latlng);
    userAccuracyCircle.setRadius(Math.max(5, lastGpsPosition.accuracy));
  } else {
    userAccuracyCircle = L.circle(latlng, {
      radius: Math.max(5, lastGpsPosition.accuracy),
      color: "#5b7cfa",
      weight: 1,
      opacity: 0.75,
      fillColor: "#5b7cfa",
      fillOpacity: 0.10,
      interactive: false
    }).addTo(mobilityMap);
  }

  if (!firstGpsFixReceived) {
    if (userRangeCircle) {
      mobilityMap.fitBounds(userRangeCircle.getBounds(), {padding:[14,14], maxZoom:15, animate:false});
    } else {
      mobilityMap.setView(latlng, 15, {animate:false});
    }
    firstGpsFixReceived = true;
    centerOnNextGpsFix = false;
  } else if (centerOnNextGpsFix || followGpsLocation) {
    mobilityMap.setView(latlng, Math.max(mobilityMap.getZoom(), 15), {animate:true});
    centerOnNextGpsFix = false;
  }

  const accuracy = Math.round(lastGpsPosition.accuracy);
  setGpsStatus("GPS activo · precisión aproximada de " + accuracy.toLocaleString("es-MX") + " m", "active");
}

function handleGpsError(error) {
  let message = "No se pudo obtener la ubicación GPS.";
  if (error && error.code === 1) {
    message = "Permite el acceso a la ubicación en el navegador o en los permisos de la app.";
  } else if (error && error.code === 2) {
    message = "La ubicación no está disponible. Activa el GPS del dispositivo e inténtalo otra vez.";
  } else if (error && error.code === 3) {
    message = "El GPS está tardando demasiado. Comprueba los permisos de ubicación y que el GPS del dispositivo esté activado.";
  } else if (window.isSecureContext === false) {
    message = "El GPS requiere que abras El Radar mediante HTTPS.";
  }
  setGpsStatus(message, "error");
}

function startMobilityTracking() {
  if (!currentRadarUser) return;
  if (String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX") !== "activo") {
    setGpsStatus("Cuenta inactiva · el GPS está detenido. Cambia el estado a Activo en Datos del usuario para reanudarlo.", "error");
    return;
  }
  if (!initMobilityMap()) return;
  if (locationHeartbeatTimer === null) {
    locationHeartbeatTimer = setInterval(() => {
      if (document.visibilityState === "hidden" || !lastGpsPosition || !currentRadarUser) return;
      if (String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX") !== "activo") return;
      persistGpsLocation(lastGpsPosition, lastKnownGpsAddress);
      refreshNearbyTransportistas(true);
    }, 60000);
  }
  if (!navigator.geolocation) {
    setGpsStatus("Este navegador o la app no permite acceder al GPS.", "error");
    return;
  }
  if (window.isSecureContext === false) {
    setGpsStatus("Para utilizar el GPS, abre la página mediante HTTPS.", "error");
    return;
  }
  if (gpsWatchId !== null) {
    if (lastGpsPosition && mobilityMap) setTimeout(() => mobilityMap.invalidateSize(), 120);
    return;
  }
  setGpsStatus("Solicitando permiso para acceder a la ubicación…");
  gpsPermissionPrompted = true;
  try {
    gpsWatchId = navigator.geolocation.watchPosition(
      handleGpsPosition,
      handleGpsError,
      { enableHighAccuracy: true, maximumAge: 8000, timeout: 25000 }
    );
  } catch (error) {
    handleGpsError(error);
  }
  setTimeout(() => { if (mobilityMap) mobilityMap.invalidateSize(); }, 250);
}

function stopMobilityTracking() {
  if (locationHeartbeatTimer !== null) { clearInterval(locationHeartbeatTimer); locationHeartbeatTimer = null; }
  if (gpsWatchId !== null && navigator.geolocation) {
    try { navigator.geolocation.clearWatch(gpsWatchId); } catch (_) {}
  }
  gpsWatchId = null;
  lastGpsPosition = null;
  pendingLocationPersist = null;
  lastKnownGpsAddress = '';
  lastLocationPersistCoords = null;
  lastLocationPersistAddress = '';
  lastLocationPersistAt = 0;
  firstGpsFixReceived = false;
  followGpsLocation = true;
  if (userLocationMarker && mobilityMap) {
    try { mobilityMap.removeLayer(userLocationMarker); } catch (_) {}
  }
  if (userAccuracyCircle && mobilityMap) {
    try { mobilityMap.removeLayer(userAccuracyCircle); } catch (_) {}
  }
  if (userRangeCircle && mobilityMap) {
    try { mobilityMap.removeLayer(userRangeCircle); } catch (_) {}
  }
  if (mobilityMap) {
    try { mobilityMap.setMaxBounds(null); } catch (_) {}
  }
  userLocationMarker = null;
  userAccuracyCircle = null;
  userRangeCircle = null;
  removeNearbyTransporterMarkers();
  const nearbyStatus = document.getElementById("nearbyTransportersStatus");
  if (nearbyStatus) nearbyStatus.textContent = "Activa tu cuenta y permite el GPS para buscar transportistas dentro de 1 km.";
  if (addressLookupTimer) { clearTimeout(addressLookupTimer); addressLookupTimer = null; }
  const addressEl = document.getElementById("gpsAddress");
  if (addressEl) addressEl.textContent = "Esperando la ubicación GPS para consultar la dirección…";
}

function syncMapStatusControl(user = currentRadarUser) {
  const toggle = document.getElementById("mapStatusSwitch");
  const label = document.getElementById("mapStatusLabel");
  const control = document.querySelector(".map-status-control");
  const mapElement = document.getElementById("mobilityMap");
  const vehicleFilter = document.querySelector(".map-vehicle-filter");
  const active = String(user && user.estado || "").trim().toLocaleLowerCase("es-MX") === "activo";

  // El filtro por tipo de transporte solo se muestra con la cuenta activa.
  if (vehicleFilter) vehicleFilter.style.display = active ? "" : "none";

  // El mapa solo se muestra cuando el estado de la cuenta es Activo.
  // La clase CSS lo oculta sin destruir el mapa Leaflet ni sus capas.
  if (mapElement) {
    mapElement.classList.toggle("account-inactive", !active);
    if (active && mobilityMap) {
      setTimeout(() => {
        try { mobilityMap.invalidateSize(); } catch (_) {}
      }, 150);
    }
  }

  if (!toggle) return;
  toggle.checked = active;
  toggle.setAttribute("aria-checked", String(active));
  if (label) label.textContent = active ? "Activo" : "Inactivo";
  if (control) {
    control.classList.toggle("active", active);
    control.classList.toggle("inactive", !active);
    control.classList.toggle("saving", toggle.disabled);
  }
}

const mapStatusSwitch = document.getElementById("mapStatusSwitch");
if (mapStatusSwitch) {
  mapStatusSwitch.addEventListener("change", async () => {
    if (!currentRadarUser) {
      syncMapStatusControl(null);
      showToast("Inicia sesión para cambiar el estado de la cuenta.");
      return;
    }

    const previousStatus = String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX");
    const requestedStatus = mapStatusSwitch.checked ? "activo" : "inactivo";
    if (requestedStatus === previousStatus) {
      syncMapStatusControl(currentRadarUser);
      return;
    }

    mapStatusSwitch.disabled = true;
    const statusControl = document.querySelector(".map-status-control");
    if (statusControl) statusControl.classList.add("saving");
    const statusLabel = document.getElementById("mapStatusLabel");
    if (statusLabel) statusLabel.textContent = "Guardando…";

    try {
      const result = await apiRequest("update_status", { estado: requestedStatus });
      updateUserUI(result.user);
      if (requestedStatus === "activo") {
        startMobilityTracking();
        setGpsStatus("Cuenta activa · iniciando la actualización de ubicación…");
      } else {
        stopMobilityTracking();
        setGpsStatus("Cuenta inactiva · el GPS está detenido.", "error");
      }
      showToast(requestedStatus === "activo"
        ? "Estado actualizado: Activo."
        : "Estado actualizado: Inactivo. El GPS se ha detenido.");
    } catch (error) {
      syncMapStatusControl(currentRadarUser);
      showToast(error.message || "No se pudo guardar el estado.");
    } finally {
      mapStatusSwitch.disabled = false;
      syncMapStatusControl(currentRadarUser);
    }
  });
}

function updateUserUI(user) {
  currentRadarUser = user;
  loadLatestNews();
  // Procesa acciones abiertas desde los botones de una notificación del sistema.
  try {
    const deep = new URL(window.location.href);
    const deepAction = deep.searchParams.get('radar_action');
    const requestId = Number(deep.searchParams.get('solicitud_id') || 0);
    const notificationId = Number(deep.searchParams.get('notification_id') || 0);
    if (deepAction && requestId > 0 && !window.__radarNotificationActionHandled) {
      window.__radarNotificationActionHandled = true;
      deep.searchParams.delete('radar_action'); deep.searchParams.delete('solicitud_id'); deep.searchParams.delete('notification_id');
      history.replaceState({}, '', deep.pathname + (deep.search ? deep.search : '') + deep.hash);
      setTimeout(async () => {
        if (deepAction === 'respond_trip') {
          openTripResponseDialog(requestId, notificationId);
        } else if (deepAction === 'review_price') {
          const decision = deep.searchParams.get('decision');
          if (decision === 'aceptar' || decision === 'rechazar') {
            try {
              const result = await apiRequest('trip_request_client_decision', {solicitud_id:requestId, decision});
              showToast(result.message || 'Tu respuesta fue enviada.');
              await loadNotifications(true);
            } catch (error) {
              notificationsOverlay.hidden = false;
              await loadNotifications(true);
              showToast(error.message || 'No se pudo enviar tu respuesta.');
            }
          } else {
            notificationsOverlay.hidden = false;
            await loadNotifications(true);
            showToast('Puedes aceptar o rechazar el precio desde la notificación dentro de El Radar.');
          }
        }
      }, 500);
    }
  } catch (_) {}
  syncAdminModuleVisibility(user);
  syncMapStatusControl(user);
  const name = [user.nombre, user.apellidos].filter(Boolean).join(" ") || user.usuario || "Usuario";
  document.getElementById("profileName").textContent = name;
  document.getElementById("profileType").textContent = user.tipo || "cliente";
  const avatar = document.getElementById("profileAvatar");
  avatar.replaceChildren();
  if (user.foto && /^data:image\/(jpeg|png|webp);base64,/.test(user.foto)) {
    const img = document.createElement("img"); img.src = user.foto; img.alt = "Foto de " + name; avatar.appendChild(img);
  } else { avatar.textContent = name.charAt(0).toUpperCase(); }

  const balance = document.getElementById("balanceValue");
  if (balance) balance.textContent = "$" + Number(user.saldo || 0).toLocaleString("es-MX", {minimumFractionDigits:2, maximumFractionDigits:2});
  const availableTime = document.getElementById("timeAvailableValue");
  if (availableTime) availableTime.textContent = Number(user.tiempo_disponible || 0).toLocaleString("es-MX");
  refreshUserLocationMarker();
  if (!userDetailsModal.hidden) openUserDetails(true);
}

function enterApp(user, celebrate = false) {
  try { localStorage.setItem('radar_cached_user_v1', JSON.stringify(user)); } catch (_) {}
  updateUserUI(user);
  startNotificationSync();
  authScreen.hidden = true;
  document.body.classList.remove("auth-locked");
  // La sesión ya está abierta y la pantalla de acceso oculta: registrar/sincronizar
  // el dispositivo ahora evita carreras entre el login y el Service Worker.
  if (window.radarSyncPushSubscription) {
    window.radarSyncPushSubscription().catch(error => console.warn('El Radar: sincronización push tras iniciar sesión fallida.', error));
  }
  updateSyncStatus("Sincronización automática activa");
  const name = [user.nombre, user.apellidos].filter(Boolean).join(" ") || user.usuario || "Usuario";
  const initial = location.hash.replace("#", "");
  showPage(pageInfo[initial] ? initial : "radar");
  if (String(user.estado || "").trim().toLocaleLowerCase("es-MX") === "activo") {
    startMobilityTracking();
  } else {
    stopMobilityTracking();
    setGpsStatus("Cuenta inactiva · el GPS está detenido. Cambia el estado a Activo en Datos del usuario para reanudarlo.", "error");
  }
  pendingTrialNotice = null;
  // Si la cuenta tiene saldo positivo, no mostrar el aviso del periodo de prueba,
  // ni siquiera si existe una fecha de prueba vencida en los datos antiguos.
  const currentBalance = Number(user.saldo || 0);
  if (String(user.tipo || '').toLowerCase() !== 'admin' && (!Number.isFinite(currentBalance) || currentBalance <= 0)) {
    const trialExpired = !!user.prueba_vencida || !!(user.prueba_fin && new Date(user.prueba_fin.replace(' ', 'T')).getTime() <= Date.now());
    if (trialExpired) {
      pendingTrialNotice = {expired:true, days:0, date:user.prueba_fin ? new Date(user.prueba_fin.replace(' ', 'T')).toLocaleDateString('es-ES') : ''};
    } else if (user.prueba_fin && Number.isFinite(Number(user.prueba_dias_restantes))) {
      pendingTrialNotice = {expired:false, days:Math.max(0, Number(user.prueba_dias_restantes)), date:new Date(user.prueba_fin.replace(' ', 'T')).toLocaleDateString('es-ES')};
    }
  }
  if (celebrate) showWelcome(user, name);
  else if (pendingTrialNotice) {
    if (trialNoticeTimer) clearTimeout(trialNoticeTimer);
    trialNoticeTimer = setTimeout(showTrialNotice, 350);
  }
}

let databaseSyncTimer = null;
let databaseSyncInProgress = false;
const syncStatus = document.getElementById("syncStatus");
function updateSyncStatus(message, failed = false) {
  if (!syncStatus) return;
  syncStatus.textContent = message;
  syncStatus.classList.toggle("sync-error", failed);
}
function handleExpiredSession(message) {
  stopMobilityTracking();
  stopNotificationSync();
  currentRadarUser = null;
  try { localStorage.removeItem('radar_cached_user_v1'); } catch (_) {}
  closeUserDetails();
  hideWelcome();
  authScreen.hidden = false;
  document.body.classList.add("auth-locked");
  setAuthMode("login");
  document.getElementById("loginPass").value = "";
  showAuthError("loginError", message || "Tu sesión terminó o la cuenta dejó de estar activa. Inicia sesión nuevamente.");
}

async function syncDatabaseNow() {
  if (databaseSyncInProgress || !currentRadarUser || document.visibilityState === "hidden") return;
  databaseSyncInProgress = true;
  try {
    const result = await apiRequest("sync");
    if (!result.user) {
      handleExpiredSession("Tu cuenta ya no está activa o la sesión terminó. Inicia sesión nuevamente.");
      updateSyncStatus("Sesión finalizada", true);
      return;
    }

    const previous = JSON.stringify(currentRadarUser);
    const previousStatus = String(currentRadarUser.estado || "").trim().toLocaleLowerCase("es-MX");
    const updated = JSON.stringify(result.user);
    const updatedStatus = String(result.user.estado || "").trim().toLocaleLowerCase("es-MX");
    if (previous !== updated) updateUserUI(result.user);
    if (previousStatus !== updatedStatus) {
      if (updatedStatus === "activo") {
        startMobilityTracking();
        setGpsStatus("Cuenta activa · iniciando la actualización de ubicación…");
      } else {
        stopMobilityTracking();
        setGpsStatus("Cuenta inactiva · el GPS está detenido.", "error");
      }
    }
    const now = new Date();
    updateSyncStatus("Sincronizado " + now.toLocaleTimeString("es-MX", {hour:"2-digit", minute:"2-digit", second:"2-digit"}));
  } catch (error) {
    if (currentRadarUser) updateSyncStatus("Conexión temporalmente interrumpida", true);
  } finally {
    databaseSyncInProgress = false;
  }
}

function startDatabaseSync() {
  if (databaseSyncTimer) clearInterval(databaseSyncTimer);
  databaseSyncTimer = setInterval(syncDatabaseNow, 3000);
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible" && currentRadarUser) syncDatabaseNow();
  });
}
startDatabaseSync();
startNearbyTransportersSync();
document.getElementById("loginTab").addEventListener("click",()=>setAuthMode("login"));
document.getElementById("registerTab").addEventListener("click",()=>setAuthMode("register"));
document.getElementById("forgotPasswordLink").addEventListener("click",()=>{
  loginPanel.hidden=true; document.getElementById("forgotPasswordPanel").hidden=false;
  document.getElementById("forgotPasswordEmail").value=document.getElementById("loginUser").value.includes("@")?document.getElementById("loginUser").value:"";
  document.getElementById("forgotPasswordEmail").focus();
});
document.getElementById("backToLoginFromForgot").addEventListener("click",()=>{document.getElementById("forgotPasswordPanel").hidden=true;loginPanel.hidden=false;});
document.getElementById("forgotPasswordForm").addEventListener("submit",async e=>{
  e.preventDefault(); const btn=document.getElementById("forgotPasswordSubmit"); btn.disabled=true; btn.textContent="Enviando…";
  try { const r=await apiRequest("password_reset_request",{correo:document.getElementById("forgotPasswordEmail").value.trim()}); const box=document.getElementById("forgotPasswordError"); box.textContent=r.message; box.classList.add("success","show"); }
  catch(err){showAuthError("forgotPasswordError",err.message);} finally{btn.disabled=false;btn.textContent="Enviar enlace de recuperación";}
});
const resetPasswordToken=new URLSearchParams(location.search).get("reset_password");
if(resetPasswordToken){loginPanel.hidden=true;document.getElementById("registerPanel").hidden=true;document.querySelector(".auth-tabs").hidden=true;document.getElementById("resetPasswordPanel").hidden=false;}
document.getElementById("resetPasswordForm").addEventListener("submit",async e=>{
  e.preventDefault(); const pass=document.getElementById("resetPasswordNew").value; const confirm=document.getElementById("resetPasswordConfirm").value;
  if(pass!==confirm){showAuthError("resetPasswordError","Las contraseñas no coinciden.");return;}
  const btn=document.getElementById("resetPasswordSubmit");btn.disabled=true;btn.textContent="Guardando…";
  try{const r=await apiRequest("password_reset_complete",{token:resetPasswordToken,contrasena:pass});const box=document.getElementById("resetPasswordError");box.textContent=r.message;box.classList.add("success","show");document.getElementById("resetPasswordForm").hidden=true;history.replaceState({},"",location.pathname);setTimeout(()=>{document.getElementById("resetPasswordPanel").hidden=true;document.querySelector(".auth-tabs").hidden=false;setAuthMode("login");},2500);}
  catch(err){showAuthError("resetPasswordError",err.message);}finally{btn.disabled=false;btn.textContent="Guardar nueva contraseña";}
});

document.getElementById("loginForm").addEventListener("submit", async e=>{
  e.preventDefault(); if(!serverConnected) return showAuthError("loginError","Primero debes esperar a que se confirme la conexión con el servidor."); const btn=document.getElementById("loginSubmit"); btn.disabled=true; btn.textContent="Verificando…";
  try { const r=await apiRequest("login", {usuario:document.getElementById("loginUser").value.trim(), contrasena:document.getElementById("loginPass").value}); enterApp(r.user, true); }
  catch(err){showAuthError("loginError",err.message);} finally{btn.disabled=false;btn.textContent="Entrar a El Radar →";}
});
// Selección de fotos desde móviles: no dependemos del MIME que declare el selector,
// porque algunos teléfonos lo dejan vacío o informan un tipo genérico. La imagen se
// decodifica en el navegador, se reduce y se convierte a JPEG para reducir la subida.
let selectedRegisterPhotoData = null;
let registerPhotoProcessing = false;
let registerPhotoSelectionVersion = 0;

function readFileAsDataURL(file) {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result || ""));
    reader.onerror = () => reject(new Error("No se pudo leer la foto. Intenta elegirla nuevamente."));
    reader.readAsDataURL(file);
  });
}

function canvasToBlob(canvas, quality) {
  return new Promise((resolve, reject) => {
    if (typeof canvas.toBlob !== "function") {
      try {
        const dataUrl = canvas.toDataURL("image/jpeg", quality);
        const parts = dataUrl.split(",");
        const binary = atob(parts[1]);
        const bytes = new Uint8Array(binary.length);
        for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
        resolve(new Blob([bytes], {type: "image/jpeg"}));
      } catch (error) { reject(new Error("Este navegador no pudo preparar la foto.")); }
      return;
    }
    canvas.toBlob(blob => blob ? resolve(blob) : reject(new Error("No se pudo preparar la foto. Prueba con otra imagen.")), "image/jpeg", quality);
  });
}

async function prepareRegisterPhoto(file, photoPurpose = "de perfil") {
  if (!file) throw new Error("Selecciona una foto " + photoPurpose + ".");
  // Limitamos el archivo original para proteger la memoria del teléfono, pero después
  // comprimimos automáticamente fotos normales de cámara que sean más grandes.
  if (file.size > 12 * 1024 * 1024) throw new Error("La foto original supera 12 MB. Elige una imagen más pequeña.");
  const source = await readFileAsDataURL(file);
  const img = await new Promise((resolve, reject) => {
    const image = new Image();
    image.onload = () => resolve(image);
    image.onerror = () => reject(new Error("El teléfono no permite leer este formato. Elige una foto JPG o PNG de la galería."));
    image.src = source;
  });
  if (!img.naturalWidth || !img.naturalHeight) throw new Error("La foto seleccionada está vacía o dañada.");

  const maxDimension = 1200;
  const scale = Math.min(1, maxDimension / Math.max(img.naturalWidth, img.naturalHeight));
  const canvas = document.createElement("canvas");
  canvas.width = Math.max(1, Math.round(img.naturalWidth * scale));
  canvas.height = Math.max(1, Math.round(img.naturalHeight * scale));
  const context = canvas.getContext("2d");
  if (!context) throw new Error("No se pudo preparar la foto en este navegador.");
  // El fondo blanco evita zonas oscuras cuando el archivo original tiene transparencia.
  context.fillStyle = "#ffffff";
  context.fillRect(0, 0, canvas.width, canvas.height);
  context.drawImage(img, 0, 0, canvas.width, canvas.height);

  let blob = null;
  for (const quality of [0.86, 0.78, 0.70, 0.62, 0.54, 0.46]) {
    blob = await canvasToBlob(canvas, quality);
    if (blob.size <= 850 * 1024) break;
  }
  if (!blob || blob.size > 950 * 1024) throw new Error("No se pudo reducir la foto lo suficiente. Elige otra imagen.");
  return await readFileAsDataURL(blob);
}

const registerPhotoInput = document.getElementById("registerPhoto");
registerPhotoInput.addEventListener("change", async () => {
  const version = ++registerPhotoSelectionVersion;
  const file = registerPhotoInput.files && registerPhotoInput.files[0];
  const preview = document.getElementById("registerPhotoPreview");
  const previewImage = document.getElementById("registerPhotoPreviewImage");
  selectedRegisterPhotoData = null;
  preview.hidden = true;
  if (!file) { registerPhotoProcessing = false; return; }

  registerPhotoProcessing = true;
  const processingMessage = document.getElementById("registerError");
  processingMessage.textContent = "Preparando la foto seleccionada…";
  processingMessage.classList.add("show", "processing");
  try {
    const preparedPhoto = await prepareRegisterPhoto(file);
    if (version !== registerPhotoSelectionVersion) return;
    selectedRegisterPhotoData = preparedPhoto;
    previewImage.src = preparedPhoto;
    preview.hidden = false;
    const errorBox = document.getElementById("registerError");
    errorBox.classList.remove("show", "processing");
    errorBox.textContent = "";
  } catch (error) {
    if (version !== registerPhotoSelectionVersion) return;
    registerPhotoInput.value = "";
    selectedRegisterPhotoData = null;
    preview.hidden = true;
    showAuthError("registerError", error.message || "No se pudo seleccionar la foto. Intenta nuevamente.");
  } finally {
    if (version === registerPhotoSelectionVersion) registerPhotoProcessing = false;
  }
});

// Catálogo de provincias y municipios de Cuba para el formulario de registro.
const cubaMunicipalities = {
  "Pinar del Río":["Pinar del Río","Consolación del Sur","Guane","La Palma","Los Palacios","Mantua","Minas de Matahambre","San Juan y Martínez","San Luis","Sandino","Viñales"],
  "Artemisa":["Artemisa","Alquízar","Bauta","Caimito","Candelaria","Guanajay","Güira de Melena","Mariel","San Antonio de los Baños","San Cristóbal","Bahía Honda"],
  "La Habana":["Arroyo Naranjo","Boyeros","Centro Habana","Cerro","Cotorro","Diez de Octubre","Guanabacoa","Habana del Este","Habana Vieja","La Lisa","Marianao","Playa","Plaza de la Revolución","Regla","San Miguel del Padrón"],
  "Mayabeque":["Batabanó","Bejucal","Güines","Jaruco","Madruga","Melena del Sur","Nueva Paz","Quivicán","San José de las Lajas","San Nicolás","Santa Cruz del Norte"],
  "Matanzas":["Calimete","Cárdenas","Ciénaga de Zapata","Colón","Jagüey Grande","Jovellanos","Limonar","Los Arabos","Martí","Matanzas","Pedro Betancourt","Perico","Unión de Reyes"],
  "Cienfuegos":["Abreus","Aguada de Pasajeros","Cruces","Cumanayagua","Cienfuegos","Lajas","Palmira","Rodas"],
  "Villa Clara":["Caibarién","Camajuaní","Cifuentes","Corralillo","Encrucijada","Manicaragua","Placetas","Quemado de Güines","Ranchuelo","Remedios","Sagua la Grande","Santa Clara","Santo Domingo"],
  "Sancti Spíritus":["Cabaiguán","Fomento","Jatibonico","La Sierpe","Sancti Spíritus","Taguasco","Trinidad","Yaguajay"],
  "Ciego de Ávila":["Baraguá","Bolivia","Chambas","Ciego de Ávila","Ciro Redondo","Florencia","Majagua","Morón","Primero de Enero","Venezuela"],
  "Camagüey":["Camagüey","Céspedes","Esmeralda","Florida","Guáimaro","Jimaguayú","Minas","Najasa","Nuevitas","Santa Cruz del Sur","Sibanicú","Sierra de Cubitas","Vertientes"],
  "Las Tunas":["Amancio","Colombia","Jesús Menéndez","Jobabo","Las Tunas","Majibacoa","Manatí","Puerto Padre"],
  "Holguín":["Antilla","Báguanos","Banes","Cacocum","Calixto García","Cueto","Frank País","Gibara","Holguín","Mayarí","Moa","Rafael Freyre","Sagua de Tánamo","Urbano Noris"],
  "Granma":["Bartolomé Masó","Bayamo","Buey Arriba","Campechuela","Cauto Cristo","Guisa","Jiguaní","Manzanillo","Media Luna","Niquero","Pilón","Río Cauto","Yara"],
  "Santiago de Cuba":["Contramaestre","Guamá","Mella","Palma Soriano","San Luis","Santiago de Cuba","Segundo Frente","Songo-La Maya","Tercer Frente"],
  "Guantánamo":["Baracoa","Caimanera","El Salvador","Guantánamo","Imías","Maisí","Manuel Tames","Niceto Pérez","San Antonio del Sur","Yateras"],
  "Isla de la Juventud":["Isla de la Juventud"]
};
const registerProvinceSelect = document.getElementById("registerProvince");
const registerMunicipalitySelect = document.getElementById("registerMunicipality");
Object.keys(cubaMunicipalities).forEach(province => {
  const option = document.createElement("option");
  option.value = province; option.textContent = province;
  registerProvinceSelect.appendChild(option);
});
registerProvinceSelect.addEventListener("change", () => {
  const province = registerProvinceSelect.value;
  registerMunicipalitySelect.innerHTML = "";
  const placeholder = document.createElement("option");
  placeholder.value = "";
  placeholder.textContent = province ? "Selecciona tu municipio" : "Primero selecciona una provincia";
  registerMunicipalitySelect.appendChild(placeholder);
  (cubaMunicipalities[province] || []).forEach(municipality => {
    const option = document.createElement("option");
    option.value = municipality; option.textContent = municipality;
    registerMunicipalitySelect.appendChild(option);
  });
  registerMunicipalitySelect.disabled = !province;
});

// Campos editables del perfil: únicamente teléfono, correo, provincia y municipio.
const profileProvinceSelect = document.getElementById("profileProvince");
const profileMunicipalitySelect = document.getElementById("profileMunicipality");
Object.keys(cubaMunicipalities).forEach(province => {
  const option = document.createElement("option"); option.value = province; option.textContent = province;
  profileProvinceSelect.appendChild(option);
});
function fillProfileMunicipalities(province, selected = "") {
  if (!profileMunicipalitySelect) return;
  profileMunicipalitySelect.replaceChildren();
  const placeholder = document.createElement("option"); placeholder.value = "";
  placeholder.textContent = province ? "Selecciona tu municipio" : "Primero selecciona una provincia";
  profileMunicipalitySelect.appendChild(placeholder);
  (cubaMunicipalities[province] || []).forEach(municipality => {
    const option = document.createElement("option"); option.value = municipality; option.textContent = municipality;
    profileMunicipalitySelect.appendChild(option);
  });
  profileMunicipalitySelect.disabled = !province;
  if (selected && (cubaMunicipalities[province] || []).includes(selected)) profileMunicipalitySelect.value = selected;
}
profileProvinceSelect.addEventListener("change", () => fillProfileMunicipalities(profileProvinceSelect.value));
function syncProfileEditFields(user) {
  const phone = document.getElementById("profilePhone"), email = document.getElementById("profileEmail");
  if (phone) phone.value = user.telefono || "";
  if (email) email.value = user.correo || "";
  if (profileProvinceSelect) profileProvinceSelect.value = user.provincia || "";
  fillProfileMunicipalities(user.provincia || "", user.municipio || "");
  const feedback = document.getElementById("profileDataFeedback");
  if (feedback) { feedback.textContent = ""; feedback.classList.remove("error", "success"); }
}
const saveProfileDataButton = document.getElementById("saveProfileDataButton");
if (saveProfileDataButton) saveProfileDataButton.addEventListener("click", async () => {
  const telefono = document.getElementById("profilePhone").value.trim();
  const correo = document.getElementById("profileEmail").value.trim();
  const provincia = profileProvinceSelect.value;
  const municipio = profileMunicipalitySelect.value;
  const feedback = document.getElementById("profileDataFeedback");
  const showFeedback = (message, type) => { feedback.textContent = message; feedback.classList.remove("error", "success"); if (type) feedback.classList.add(type); };
  if (!/^\+?[0-9][0-9\s().-]{6,18}[0-9]$/.test(telefono) || telefono.replace(/\D/g, "").length < 8 || telefono.replace(/\D/g, "").length > 13) return showFeedback("Introduce un teléfono válido.", "error");
  if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(correo)) return showFeedback("Introduce un correo electrónico válido.", "error");
  if (!provincia || !municipio) return showFeedback("Selecciona la provincia y el municipio.", "error");
  saveProfileDataButton.disabled = true; saveProfileDataButton.textContent = "Guardando…";
  try {
    const result = await apiRequest("update_profile", {telefono, correo, provincia, municipio});
    updateUserUI(result.user);
    openUserDetails(true);
    showFeedback(result.message || "Perfil actualizado correctamente.", "success");
    showToast("Datos del perfil actualizados.");
  } catch (error) {
    showFeedback(error.message || "No se pudieron guardar los cambios.", "error");
  } finally { saveProfileDataButton.disabled = false; saveProfileDataButton.textContent = "Guardar cambios"; }
});

document.getElementById("registerForm").addEventListener("submit", async e=>{
  e.preventDefault();
  if(!serverConnected) return showAuthError("registerError","Primero debes esperar a que se confirme la conexión con el servidor.");
  const user=document.getElementById("registerUser").value.trim();
  const pass=document.getElementById("registerPass").value;
  const confirm=document.getElementById("registerConfirm").value;
  if(pass.length<8) return showAuthError("registerError","La contraseña debe tener al menos 8 caracteres.");
  if(pass!==confirm) return showAuthError("registerError","Las contraseñas no coinciden.");
  if (!document.getElementById("registerName").value.trim()) return showAuthError("registerError","El nombre es obligatorio.");
  if (!document.getElementById("registerSurnames").value.trim()) return showAuthError("registerError","Los apellidos son obligatorios.");
  if (!/^[0-9]{11}$/.test(document.getElementById("registerIdentityCard").value.trim())) return showAuthError("registerError","El carné de identidad debe tener 11 dígitos.");
  const telefono=document.getElementById("registerPhone").value.trim();
  const correo=document.getElementById("registerEmail").value.trim();
  const provincia=document.getElementById("registerProvince").value;
  const municipio=document.getElementById("registerMunicipality").value;
  if (!/^\+?[0-9][0-9\s().-]{6,18}[0-9]$/.test(telefono) || telefono.replace(/\D/g,"").length<8 || telefono.replace(/\D/g,"").length>13) return showAuthError("registerError","Introduce un número de teléfono válido.");
  if (!document.getElementById("registerEmail").checkValidity()) return showAuthError("registerError","Introduce un correo electrónico válido.");
  if (!provincia || !municipio) return showAuthError("registerError","Selecciona la provincia y el municipio.");
  if (registerPhotoProcessing) return showAuthError("registerError","Espera a que termine de prepararse la foto.");
  if (!selectedRegisterPhotoData) return showAuthError("registerError","Selecciona una foto y espera a que aparezca la vista previa.");

  const btn=document.getElementById("registerSubmit");
  btn.disabled=true;
  btn.textContent="Creando cuenta…";
  try {
    const nombre=document.getElementById("registerName").value.trim();
    const apellidos=document.getElementById("registerSurnames").value.trim();
    const carnet_identidad=document.getElementById("registerIdentityCard").value.trim();
    if(!nombre || !apellidos) throw new Error("Introduce tu nombre y apellidos.");
    if(!/^[0-9]{11}$/.test(carnet_identidad)) throw new Error("Introduce el carné de identidad de 11 dígitos, sin espacios ni guiones.");
    const referralCode = new URLSearchParams(window.location.search).get('ref') || '';
    const r=await apiRequest("register", {usuario:user, contrasena:pass, confirmar:confirm, nombre, apellidos, carnet_identidad, telefono, correo, provincia, municipio, foto:selectedRegisterPhotoData, codigo_referido:referralCode});
    enterApp(r.user);
    showToast("¡Usuario registrado correctamente! Tu cuenta de El Radar ha sido creada.");
  } catch(err) {
    showAuthError("registerError",err.message);
  } finally {
    btn.disabled=false;
    btn.textContent="Crear cuenta →";
  }
});
document.getElementById("logoutButton").addEventListener("click",async()=>{
  try { await apiRequest("logout"); } catch (_) {}
  stopMobilityTracking();
  stopNotificationSync();
  currentRadarUser = null;
  closeUserDetails();
  authScreen.hidden=false; document.body.classList.add("auth-locked"); setAuthMode("login");
  document.getElementById("loginPass").value=""; showToast("Has cerrado sesión.");
});
async function requestServerHealth() {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 8000);
  try {
    const response = await fetch("index.php?action=health&_=" + Date.now(), {
      method: "GET", credentials: "same-origin", cache: "no-store", signal: controller.signal,
      headers: {"Accept":"application/json"}
    });
    if (!response.ok) throw new Error("El servidor no está disponible.");
    const result = await response.json();
    if (!result || !result.ok) throw new Error((result && result.message) || "No se pudo conectar con el servidor.");
    return true;
  } finally { clearTimeout(timeout); }
}
function setServerAvailability(available) {
  serverConnected = !!available;
  const overlay = document.getElementById("serverOfflineOverlay");
  document.body.classList.toggle("radar-is-offline", !available);
  // No ocultamos la interfaz: el Service Worker conserva la última página en el dispositivo.
  if (overlay) overlay.classList.remove("show");
}

let serverHealthCheckInProgress = false;
async function monitorServerConnection() {
  if (serverHealthCheckInProgress) return;
  serverHealthCheckInProgress = true;
  try {
    await requestServerHealth();
    setServerAvailability(true);
    if (serverStatus) {
      serverStatus.className = "server-status connected";
      serverStatusTitle.textContent = "Conexión establecida";
      serverStatusMessage.textContent = "";
      authControls.hidden = false;
    }
  } catch (error) {
    setServerAvailability(false);
    if (serverStatus) {
      serverStatus.className = "server-status failed";
      serverStatusTitle.textContent = "Sin conexión con el servidor";
      serverStatusMessage.textContent = "Esperando a que el servidor y la base de datos vuelvan a estar disponibles.";
    }
  } finally { serverHealthCheckInProgress = false; }
}
async function checkServerConnection() {
  serverConnected = false;
  authControls.hidden = true;
  serverStatus.className = "server-status checking";
  serverStatusTitle.textContent = "Comprobando conexión…";
  serverStatusMessage.textContent = "Estamos verificando el servidor y la base de datos antes de mostrar el acceso.";
  authScreen.hidden = false;
  document.body.classList.add("auth-locked");
  setServerAvailability(false);
  try {
    await requestServerHealth();
    setServerAvailability(true);
    serverStatus.className = "server-status connected";
    serverStatusTitle.textContent = "Conexión establecida";
    serverStatusMessage.textContent = "";
    authControls.hidden = false;
    const session = await apiRequest("me");
    if (session.user) enterApp(session.user);
    else setAuthMode("login");
  } catch (err) {
    setServerAvailability(false);
    serverStatus.className = "server-status failed";
    serverStatusTitle.textContent = "Sin conexión con el servidor";
    serverStatusMessage.textContent = "Mostrando la última sesión guardada. Las funciones que necesitan el servidor estarán disponibles al recuperar la conexión.";
    authControls.hidden = true;
    try {
      const cachedUser = JSON.parse(localStorage.getItem('radar_cached_user_v1') || 'null');
      if (cachedUser && cachedUser.id) enterApp(cachedUser, false);
      else { authScreen.hidden = false; document.body.classList.remove("auth-locked"); }
    } catch (_) { authScreen.hidden = false; document.body.classList.remove("auth-locked"); }
  }
}
checkServerConnection();
// Verificación permanente: detecta caídas y recuperaciones del servidor incluso con sesión iniciada.
setInterval(monitorServerConnection, 10000);
window.addEventListener("online", monitorServerConnection);
window.addEventListener("pageshow", monitorServerConnection);


const initial = location.hash.replace("#", "");
showPage(pageInfo[initial] ? initial : "radar");
</script>


<div class="transporter-dialog-backdrop" id="transporterDialogBackdrop" role="presentation" aria-hidden="true">
  <section class="transporter-dialog" id="transporterDialog" role="dialog" aria-modal="true" aria-labelledby="transporterDialogName">
    <button class="transporter-dialog-close" id="transporterDialogClose" type="button" aria-label="Cerrar ficha">&times;</button>
    <div class="transporter-dialog-hero">
      <div class="transporter-dialog-vehicle-fallback" id="transporterDialogVehicleFallback" aria-hidden="true">Foto del vehículo no disponible</div>
      <img class="transporter-dialog-vehicle" id="transporterDialogVehiclePhoto" alt="Fotografía del vehículo" hidden>
      <div class="transporter-dialog-driver" id="transporterDialogDriverPhoto" aria-label="Fotografía del transportista">👤</div>
    </div>
    <div class="transporter-dialog-content">
      <h2 class="transporter-dialog-name" id="transporterDialogName">Transportista</h2>
      <p class="transporter-dialog-subtitle">Transportista activo</p>
      <div class="transporter-dialog-section-title">Datos del vehículo</div>
      <div class="transporter-dialog-grid">
        <div class="transporter-dialog-field"><div class="transporter-dialog-field-label">Tipo de vehículo</div><div class="transporter-dialog-field-value" id="transporterDialogType">No disponible</div></div>
        <div class="transporter-dialog-field"><div class="transporter-dialog-field-label">Chapa / matrícula</div><div class="transporter-dialog-field-value" id="transporterDialogPlate">No disponible</div></div>
      </div>
      <div class="transporter-dialog-section-title">Datos del transportista</div>
      <div class="transporter-dialog-grid">
        <div class="transporter-dialog-field"><div class="transporter-dialog-field-label">Distancia aproximada</div><div class="transporter-dialog-field-value" id="transporterDialogDistance">No disponible</div></div>
        <div class="transporter-dialog-field"><div class="transporter-dialog-field-label">Licencia de conducción</div><div class="transporter-dialog-field-value" id="transporterDialogLicense">No disponible</div></div>
      </div>
      <div class="transporter-dialog-footer">
        <button class="transporter-dialog-request" id="transporterDialogRequest" type="button">Solicitar viaje</button>
      </div>
    </div>
  </section>
</div>

<div class="trip-request-backdrop" id="tripRequestBackdrop" aria-hidden="true">
  <section class="trip-request-dialog" role="dialog" aria-modal="true" aria-labelledby="tripRequestTitle">
    <button class="trip-request-close" id="tripRequestClose" type="button" aria-label="Cerrar">&times;</button>
    <h2 id="tripRequestTitle">Solicitar viaje</h2>
    <p id="tripRequestTransporterName">Completa los datos del viaje para enviar la solicitud.</p>
    <form class="trip-request-form" id="tripRequestForm">
      <label>Destino *<input id="tripRequestDestination" name="destino" type="text" maxlength="500" placeholder="Dirección o lugar de destino" required></label>
      <label>Cantidad de personas *<input id="tripRequestPeople" name="personas" type="number" min="1" max="20" value="1" required></label>
      <label>Otro dato u observaciones<textarea id="tripRequestNotes" name="observaciones" maxlength="1000" placeholder="Equipaje, referencias del lugar, necesidades especiales…"></textarea></label>
      <div class="trip-request-actions">
        <button class="trip-request-cancel" id="tripRequestCancel" type="button">Cancelar</button>
        <button class="trip-request-submit" id="tripRequestSubmit" type="submit">Enviar solicitud</button>
      </div>
    </form>
  </section>
</div>

<button id="radarInstallButton" type="button" aria-label="Instalar El Radar">⬇ INSTALAR APLICACIÓN</button>
<div id="radarPermissionGate" role="dialog" aria-modal="true" aria-labelledby="radarPermissionTitle">
  <div class="radar-permission-card">
    <div style="font-size:42px">🚕</div>
    <h2 id="radarPermissionTitle">Activa los permisos de El Radar</h2>
    <p>Para utilizar todas las funciones, permite el acceso a tu ubicación GPS y las notificaciones de avisos.</p>
    <button id="radarRequestPermissions" type="button">PERMITIR GPS Y AVISOS</button>
    <div id="radarPermissionStatus" role="status" aria-live="polite"></div>
  </div>
</div>
<button id="radarPushButton" type="button" aria-label="Activar notificaciones">🔔 ACTIVAR AVISOS</button>
<script>
(() => {
  const installBtn = document.getElementById('radarInstallButton');
  const pushBtn = document.getElementById('radarPushButton');
  let installPrompt = null;
  const isStandalone = () => window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  const permissionGate = document.getElementById('radarPermissionGate');
  const permissionBtn = document.getElementById('radarRequestPermissions');
  const permissionStatus = document.getElementById('radarPermissionStatus');
  const permissionsKey = 'radar_permissions_requested_v1';
  let pushVisibilityCheck = 0;

  // Oculta el botón cuando el navegador ya tiene una suscripción push activa.
  // El permiso concedido por sí solo no basta: también debe existir la suscripción.
  async function updatePushButtonVisibility() {
    if (!pushBtn) return;
    const checkId = ++pushVisibilityCheck;
    const authScreen = document.getElementById('authScreen');
    const userAuthenticated = !!authScreen && authScreen.hidden;
    const supported = ('serviceWorker' in navigator) && ('Notification' in window) && ('PushManager' in window);

    // Web Push funciona también desde la web abierta en el navegador; no exige instalar la PWA.
    if (!userAuthenticated || !supported || Notification.permission === 'denied') {
      pushBtn.style.display = 'none';
      return;
    }

    if (Notification.permission !== 'granted') {
      pushBtn.style.display = 'block';
      return;
    }

    // Mientras se consulta la suscripción, se mantiene oculto para evitar
    // mostrar brevemente el botón a usuarios que ya tienen los avisos activos.
    pushBtn.style.display = 'none';
    try {
      const reg = window.radarServiceWorkerRegistration || await navigator.serviceWorker.ready;
      if (checkId !== pushVisibilityCheck) return;
      window.radarServiceWorkerRegistration = reg;
      const subscription = await reg.pushManager.getSubscription();
      if (checkId !== pushVisibilityCheck) return;
      pushBtn.style.display = subscription ? 'none' : 'block';
    } catch (error) {
      if (checkId === pushVisibilityCheck) pushBtn.style.display = 'block';
    }
  }

  function updateInstallButton() {
    if (!installBtn) return;
    const installed = isStandalone();
    document.body.classList.toggle('radar-installed', installed);
    installBtn.style.display = installed ? 'none' : 'flex';
    installBtn.textContent = '⬇ INSTALAR APLICACIÓN';
    updatePushButtonVisibility();
    // No bloquear la autenticación al iniciar: los permisos se solicitan
    // únicamente cuando el usuario entra al flujo correspondiente.
  }
  function showInstalledState() {
    if (!isStandalone()) return;
    document.body.classList.add('radar-installed');
    updateInstallButton();
  }
  window.addEventListener('pageshow', updateInstallButton);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) updateInstallButton(); });
  // Recalcula la visibilidad al entrar o salir de la pantalla de autenticación.
  const authScreenForPush = document.getElementById('authScreen');
  if (authScreenForPush && window.MutationObserver) {
    new MutationObserver(updateInstallButton).observe(authScreenForPush, { attributes: true, attributeFilter: ['hidden'] });
  }
  window.addEventListener('beforeinstallprompt', event => {
    // Guardamos el permiso temporal del navegador para invocar su instalador
    // directamente desde el clic del usuario (no desde un menú intermedio).
    event.preventDefault();
    installPrompt = event;
    updateInstallButton();
  });
  window.addEventListener('appinstalled', () => {
    installPrompt = null;
    // El evento confirma la instalación, pero la vista instalada se activa
    // cuando el navegador abre la PWA en modo independiente.
    if (installBtn) installBtn.style.display = 'none';
    setTimeout(updateInstallButton, 500);
  });
  updateInstallButton();
  installBtn?.addEventListener('click', async () => {
    if (isStandalone()) {
      installBtn.style.display = 'none';
      return;
    }
    if (installPrompt) {
      // El diálogo nativo aparece inmediatamente al pulsar el botón flotante.
      const promptEvent = installPrompt;
      installPrompt = null;
      try {
        await promptEvent.prompt();
        const choice = await promptEvent.userChoice;
        if (choice && choice.outcome === 'accepted') {
          installBtn.textContent = 'INSTALANDO…';
        } else {
          updateInstallButton();
        }
      } catch (error) {
        console.error('No se pudo abrir el instalador PWA:', error);
        updateInstallButton();
        alert('El navegador no pudo iniciar la instalación. Comprueba que El Radar esté abierto mediante HTTPS y que este navegador admita instalar aplicaciones.');
      }
      return;
    }
    // Algunos navegadores (por ejemplo Safari en iPhone/iPad o Firefox en ciertas
    // plataformas) no exponen un diálogo de instalación invocable por JavaScript.
    const ua = navigator.userAgent || '';
    const ios = /iPhone|iPad|iPod/i.test(ua);
    const isSafari = ios || (/Safari/i.test(ua) && !/Chrome|CriOS|Edg|OPR|Firefox/i.test(ua));
    alert(isSafari
      ? 'Para instalar El Radar en este dispositivo: pulsa Compartir y después “Añadir a pantalla de inicio”. El sistema exige confirmar esta acción.'
      : 'Este navegador no ha ofrecido el instalador automático. Abre el menú del navegador (⋮ o ⋯) y selecciona “Instalar aplicación” o “Añadir a pantalla de inicio”. Comprueba también que la página esté en HTTPS.');
  });

  permissionBtn?.addEventListener('click', async () => {
    permissionBtn.disabled = true;
    permissionBtn.textContent = 'SOLICITANDO PERMISOS…';
    const results = [];
    try {
      if (navigator.geolocation) {
        await new Promise(resolve => navigator.geolocation.getCurrentPosition(
          () => { results.push('GPS permitido'); resolve(); },
          error => { results.push(error.code === 1 ? 'GPS denegado' : 'GPS no disponible'); resolve(); },
          {enableHighAccuracy:true, timeout:12000, maximumAge:0}
        ));
      } else results.push('GPS no compatible');
      if ('Notification' in window) {
        const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
        results.push(permission === 'granted' ? 'Avisos permitidos' : 'Avisos no permitidos');
      } else results.push('Avisos no compatibles');
    } catch (error) { results.push('No se pudieron completar todos los permisos'); }
    localStorage.setItem(permissionsKey, '1');
    document.body.classList.remove('radar-permission-pending');
    document.body.classList.add('radar-installed');
    if (permissionStatus) permissionStatus.textContent = results.join(' · ');
    if (permissionGate) permissionGate.style.display = 'none';
    updatePushButtonVisibility();
    // Se muestra la aplicación incluso si el usuario decide denegar un permiso.
  });

  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    navigator.serviceWorker.register('./index.php?radar_sw=1', {scope:'./'}).then(async reg => {
      window.radarServiceWorkerRegistration = reg;
      if (!('PushManager' in window) || !('Notification' in window) || !pushBtn) return;
      if (Notification.permission === 'granted' && await reg.pushManager.getSubscription()) {
        await window.radarSyncPushSubscription();
      }
      updatePushButtonVisibility();
    }).catch(err => {
      console.error('No se pudo registrar el servicio de notificaciones:', err);
      updatePushButtonVisibility();
    });
  }
  // Sustituye esta clave por la clave pública VAPID generada para tu servidor.
  const VAPID_PUBLIC_KEY = 'BBD-v6lARrtb_CAuyzoYzdaAZT9Bvrd-JcRkxqSYohBIJ2Rafn5xpwWzvX-5-NOJdDjB0meE02kdRmRsiPT0Myg';
  function base64UrlToUint8Array(base64String) {
    const padding = '='.repeat((4 - base64String.length % 4) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    return Uint8Array.from([...raw].map(ch => ch.charCodeAt(0)));
  }
  window.radarSyncPushSubscription = async function() {
    try {
      const reg = window.radarServiceWorkerRegistration || await navigator.serviceWorker.ready;
      window.radarServiceWorkerRegistration = reg;
      let subscription = await reg.pushManager.getSubscription();
      const expectedKey = base64UrlToUint8Array(VAPID_PUBLIC_KEY);
      const actualKey = subscription && subscription.options ? subscription.options.applicationServerKey : null;
      const keyMatches = !!actualKey && actualKey.byteLength === expectedKey.byteLength && expectedKey.every((value, index) => value === new Uint8Array(actualKey)[index]);
      if (subscription && !keyMatches) {
        await subscription.unsubscribe();
        subscription = null;
      }
      if (!subscription) {
        if (Notification.permission !== 'granted') return false;
        subscription = await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:expectedKey});
      }
      const response = await fetch('?action=push_subscribe', {
        method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json'},
        body:JSON.stringify({subscription:subscription.toJSON()})
      });
      const result = await response.json();
      if (!response.ok || !result.ok) return false;
      if (pushBtn) pushBtn.style.display = 'none';
      return true;
    } catch (error) { console.warn('No se pudo sincronizar la suscripción push:', error); return false; }
  };
  // Reintenta sincronizar la suscripción cuando Chrome recupera la página.
  // No solicita permisos por sí solo: solo trabaja si el usuario ya los concedió.
  async function refreshPushSubscription() {
    const authScreen = document.getElementById('authScreen');
    if (!authScreen || !authScreen.hidden || !('Notification' in window) || Notification.permission !== 'granted') return;
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) return;
    try {
      await navigator.serviceWorker.ready;
      await window.radarSyncPushSubscription();
    } catch (error) {
      console.warn('El Radar: no se pudo revalidar la suscripción push.', error);
    }
  }
  window.addEventListener('pageshow', refreshPushSubscription);
  window.addEventListener('focus', refreshPushSubscription);
  window.addEventListener('online', refreshPushSubscription);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) refreshPushSubscription();
  });

  pushBtn?.addEventListener('click', async () => {
    try {
      if (!('serviceWorker' in navigator) || !('PushManager' in window) || !('Notification' in window)) throw new Error('Este navegador no admite notificaciones push.');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('Permite las notificaciones en los ajustes del navegador.');
      const reg = window.radarServiceWorkerRegistration || await navigator.serviceWorker.ready;
      window.radarServiceWorkerRegistration = reg;
      let subscription = await reg.pushManager.getSubscription();
      if (!subscription) subscription = await reg.pushManager.subscribe({userVisibleOnly:true, applicationServerKey:base64UrlToUint8Array(VAPID_PUBLIC_KEY)});
      const synced = await window.radarSyncPushSubscription();
      if (!synced) throw new Error('No se pudo registrar este dispositivo. Inicia sesión y vuelve a intentarlo.');
      updatePushButtonVisibility();
      alert('¡Notificaciones activadas! El Radar podrá mostrar avisos del sistema incluso cuando cierres la página, siempre que el navegador y el sistema operativo admitan Web Push.');
    } catch (error) {
      updatePushButtonVisibility();
      alert(error.message || 'No se pudieron activar las notificaciones.');
    }
  });
})();
</script>


<div class="news-broadcast-overlay" id="newsBroadcastOverlay" aria-hidden="true">
  <section class="news-broadcast-dialog" role="alertdialog" aria-modal="true" aria-labelledby="newsBroadcastTitle" aria-describedby="newsBroadcastContent">
    <button class="news-broadcast-close" id="newsBroadcastClose" type="button" aria-label="Cerrar">×</button>
    <div class="news-broadcast-kicker">El Radar · Últimas novedades</div>
    <div class="news-broadcast-icon" id="newsBroadcastIcon" aria-hidden="true">📰</div>
    <h2 id="newsBroadcastTitle">Nueva novedad</h2>
    <div class="news-broadcast-category" id="newsBroadcastCategory"></div>
    <img id="newsBroadcastImage" class="news-broadcast-image" alt="Imagen de la novedad" hidden>
    <div class="news-broadcast-content" id="newsBroadcastContent">Cargando información…</div>
    <div class="news-broadcast-date" id="newsBroadcastDate"></div>
    <button class="news-broadcast-ok" id="newsBroadcastOk" type="button">ENTENDIDO</button>
  </section>
</div>

<div class="bonus-broadcast-overlay" id="bonusBroadcastOverlay" aria-hidden="true">
  <section class="bonus-broadcast-dialog" role="alertdialog" aria-modal="true" aria-labelledby="bonusBroadcastTitle" aria-describedby="bonusBroadcastSubtitle">
    <button class="bonus-broadcast-x" id="bonusBroadcastClose" type="button" aria-label="Cerrar">×</button>
    <div class="bonus-broadcast-top"><div class="bonus-broadcast-gift" aria-hidden="true">🎁</div><h2 id="bonusBroadcastTitle">¡Tienes un bono disponible!</h2><p class="bonus-broadcast-subtitle" id="bonusBroadcastSubtitle">Una promoción especial de El Radar</p></div>
    <div class="bonus-broadcast-amount" id="bonusBroadcastAmount" hidden><small>Importe del bono</small><strong></strong></div>
    <div class="bonus-broadcast-minimum" id="bonusBroadcastMinimum" hidden><small>Saldo mínimo requerido para canjear</small><strong></strong></div>
    <div class="bonus-broadcast-details" id="bonusBroadcastDetails"></div>
    <div class="bonus-broadcast-actions"><button class="bonus-broadcast-ok" id="bonusBroadcastOk" type="button">ENTENDIDO</button></div>
  </section>
</div>

<div class="trip-navigation-overlay" id="tripNavigationOverlay" aria-hidden="true">
  <section class="trip-navigation-dialog" role="dialog" aria-modal="true" aria-labelledby="tripNavigationTitle">
    <button class="trip-navigation-close" id="tripNavigationClose" type="button" aria-label="Cerrar mapa">&times;</button>
    <h2 id="tripNavigationTitle">Ruta hacia el cliente</h2>
    <p class="trip-navigation-info" id="tripNavigationInfo">Cargando información del viaje…</p>
    <div class="trip-navigation-map" id="tripNavigationMap" role="region" aria-label="Mapa con la ubicación del transportista, el cliente y la ruta"></div>
    <p class="trip-navigation-status" id="tripNavigationStatus" role="status" aria-live="polite">Preparando mapa…</p>
    <div class="trip-navigation-actions"><button class="trip-navigation-finish" id="tripNavigationFinish" type="button" hidden>Finalizar viaje</button><button class="trip-navigation-done" id="tripNavigationDone" type="button">Cerrar mapa</button></div>
  </section>
</div>

<div class="trip-arrival-alert-overlay" id="tripArrivalAlertOverlay" aria-hidden="true">
  <section class="trip-arrival-alert-dialog" role="alertdialog" aria-modal="true" aria-labelledby="tripArrivalAlertTitle">
    <div class="trip-arrival-vehicle-background" aria-hidden="true"><img id="tripArrivalVehiclePhoto" alt="" hidden></div>
    <div class="trip-arrival-alert-icon" aria-hidden="true">🚕</div>
    <div class="trip-arrival-photos">
      <div class="trip-arrival-photo-card"><img id="tripArrivalDriverPhoto" alt="Foto del transportista" hidden><span id="tripArrivalDriverName">Transportista</span></div>
    </div>
    <h2 id="tripArrivalAlertTitle">¡Tu transporte ha llegado!</h2>
    <p>El transportista está en tu punto de recogida. Tu vehículo ya está esperándote.</p>
    <button id="tripArrivalAlertOk" type="button">Entendido</button>
    <button id="tripArrivalAlertClose" class="trip-arrival-alert-x" type="button" aria-label="Cerrar">×</button>
  </section>
</div>

<div class="trip-response-overlay" id="tripResponseOverlay" aria-hidden="true">
  <section class="trip-response-dialog" role="dialog" aria-modal="true" aria-labelledby="tripResponseTitle">
    <button class="trip-response-close" id="tripResponseClose" type="button" aria-label="Cerrar">&times;</button>
    <h2 id="tripResponseTitle">Responder solicitud de viaje</h2>
    <div class="trip-response-details" id="tripResponseDetails">Cargando datos del viaje…</div>
    <form class="trip-response-form" id="tripResponseForm">
      <label for="tripResponsePrice">Precio del viaje *</label>
      <input id="tripResponsePrice" name="precio" type="number" min="0.01" max="999999999" step="0.01" placeholder="Introduce el precio" required>
      <div class="trip-response-actions">
        <button class="trip-response-cancel" id="tripResponseCancel" type="button">Cancelar</button>
        <button class="trip-response-submit" id="tripResponseSubmit" type="submit">Enviar precio al cliente</button>
      </div>
    </form>
  </section>
</div>

<style>
#radarChatLauncher{display:none;position:fixed;right:24px;bottom:24px;z-index:9997;border:0;border-radius:999px;background:#172554;color:#fff;padding:15px 21px;font-weight:800;box-shadow:0 10px 30px #0f172a45;cursor:pointer}
#radarChatLauncher .chat-dot{display:inline-block;width:9px;height:9px;background:#4ade80;border-radius:50%;margin-right:8px}
#radarChatPanel{position:fixed;right:22px;bottom:88px;width:min(390px,calc(100vw - 28px));height:min(570px,calc(100dvh - 120px));background:#fff;border:1px solid #dbe3ef;border-radius:20px;box-shadow:0 18px 60px #0f172a35;z-index:9998;display:none;overflow:hidden;color:#172033;font-family:inherit}
#radarChatPanel.open{display:flex;flex-direction:column}
.rchat-head{background:#172554;color:#fff;padding:16px;display:flex;align-items:center;justify-content:space-between}
.rchat-head strong{display:block;font-size:16px}.rchat-head small{opacity:.8}
.rchat-close{border:0;background:#ffffff20;color:#fff;border-radius:10px;font-size:23px;width:35px;height:35px;cursor:pointer}
#rchatAdminList{display:none;max-height:120px;overflow:auto;border-bottom:1px solid #e5eaf2;background:#f8fafc}
.rchat-thread{display:flex;align-items:center;gap:10px;padding:10px 13px;border:0;border-bottom:1px solid #e5eaf2;background:transparent;width:100%;text-align:left;cursor:pointer;color:#172033}
.rchat-thread.active{background:#eaf0ff}.rchat-thread small{display:block;color:#64748b;margin-top:3px}
#rchatMessages{flex:1;overflow:auto;padding:15px;background:#f4f7fb;display:flex;flex-direction:column;gap:10px}
.rchat-empty{margin:auto;text-align:center;color:#64748b;line-height:1.5;padding:20px}
.rchat-avatar{width:36px;height:36px;min-width:36px;height:36px;flex:0 0 36px;border-radius:50%;object-fit:cover;background:#e2e8f0;border:1px solid #dbe3ef}
.rchat-avatar-fallback{display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#1e3a8a}
.rchat-message{display:flex;align-items:flex-end;gap:8px;max-width:100%}
.rchat-message.mine{align-self:flex-end;flex-direction:row-reverse}
.rchat-message.theirs{align-self:flex-start}
.rchat-bubble{max-width:min(84%,300px);padding:10px 12px;border-radius:14px;background:#fff;box-shadow:0 2px 8px #0f172a0c;overflow-wrap:anywhere}
.rchat-bubble.mine{background:#dce8ff;border-bottom-right-radius:4px}
.rchat-bubble.theirs{border-bottom-left-radius:4px}
.rchat-bubble small{display:block;color:#64748b;font-size:10px;margin-top:5px}
.rchat-compose{display:flex;gap:8px;padding:12px;border-top:1px solid #e5eaf2;background:#fff}
#rchatInput{min-width:0;flex:1;resize:none;border:1px solid #d5deea;border-radius:12px;padding:11px;font:inherit;max-height:90px}
#rchatSend{border:0;border-radius:12px;background:#2563eb;color:white;font-weight:700;padding:0 15px;cursor:pointer}
#rchatSend:disabled{opacity:.5;cursor:not-allowed}
@media(max-width:520px){#radarChatLauncher{right:15px;bottom:16px;padding:13px 17px}#radarChatPanel{right:10px;bottom:75px;width:calc(100vw - 20px);height:min(600px,calc(100dvh - 95px))}}
</style>
<button id="radarChatLauncher" type="button" aria-label="Abrir chat con administración"><span class="chat-dot"></span>Chat de soporte</button>
<section id="radarChatPanel" aria-label="Chat con administración">
  <header class="rchat-head"><div><strong>💬 Atención de El Radar</strong><small id="rchatSubtitle">Conversa con los administradores</small></div><button class="rchat-close" id="rchatClose" type="button" aria-label="Cerrar chat">×</button></header>
  <div id="rchatAdminList"></div>
  <div id="rchatMessages"><div class="rchat-empty">Inicia sesión para conversar con los administradores.</div></div>
  <form class="rchat-compose" id="rchatForm"><textarea id="rchatInput" rows="1" maxlength="2000" placeholder="Escribe tu mensaje..." aria-label="Mensaje"></textarea><button id="rchatSend" type="submit">Enviar</button></form>
</section>
<script>
(function(){
 const launcher=document.getElementById('radarChatLauncher'), panel=document.getElementById('radarChatPanel');
 const close=document.getElementById('rchatClose'), list=document.getElementById('rchatAdminList'), messages=document.getElementById('rchatMessages');
 const form=document.getElementById('rchatForm'), input=document.getElementById('rchatInput'), send=document.getElementById('rchatSend');
 let conversationId=0, selectedUser='', isAdmin=false, pollBusy=false, lastSignature='';
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 const radarAdminLogo='./index.php?radar_icon=192';
 function radarAvatar(photo,name,isAdminSender){
   if(isAdminSender) return `<img class="rchat-avatar" src="${radarAdminLogo}" alt="El Radar">`;
   const p=String(photo||'').trim();
   if(p && (/^data:image\/(jpeg|png|webp);base64,/i.test(p) || /^https?:\/\//i.test(p) || /^\.\.?\//.test(p) || p.startsWith('/'))){
     return `<img class="rchat-avatar" src="${esc(p)}" alt="${esc(name||'Usuario')}" onerror="this.style.display='none'">`;
   }
   const initials=String(name||'U').trim().split(/\s+/).slice(0,2).map(x=>x.charAt(0).toUpperCase()).join('');
   return `<span class="rchat-avatar rchat-avatar-fallback">${esc(initials||'U')}</span>`;
 }
 const time=v=>{if(!v)return '';let d=new Date(String(v).replace(' ','T'));return isNaN(d)?'':d.toLocaleString('es-MX',{dateStyle:'short',timeStyle:'short'});};
 function open(){if(!currentRadarUser)return;panel.classList.add('open');launcher.style.display='none';refresh();}
 function shut(){panel.classList.remove('open');launcher.style.display=currentRadarUser?'block':'none';}
 launcher.addEventListener('click',open);close.addEventListener('click',shut);
 async function refresh(){
   if(!panel.classList.contains('open')||!currentRadarUser||pollBusy)return;
   pollBusy=true;
   try{
     const result=await apiRequest('chat_list');
     isAdmin=!!result.admin;
     if(isAdmin){
       list.style.display='block';
       const items=result.conversaciones||[];
       if(!selectedUser&&items.length){selectedUser=String(items[0].USUARIO_ID);conversationId=Number(items[0].conversacion_id||0);}
       const selected=items.find(c=>String(c.USUARIO_ID)===String(selectedUser));
       if(selected)conversationId=Number(selected.conversacion_id||0);
       list.innerHTML=items.length?items.map(c=>{
         const name=(((c.NOMBRE||'')+' '+(c.APELLIDOS||'')).trim()||c.USUARIO||'Usuario');
         return `<button class="rchat-thread ${String(c.USUARIO_ID)===String(selectedUser)?'active':''}" data-user="${Number(c.USUARIO_ID)}" data-id="${Number(c.conversacion_id||0)}">${radarAvatar(c.FOTO,name,false)}<span><b>${esc(name)}</b><small>${esc(c.TIPO)} · ${esc(c.ultimo_mensaje||'Sin mensajes')}</small></span></button>`;
       }).join(''):'<div class="rchat-empty">No hay usuarios disponibles.</div>';
       list.querySelectorAll('[data-user]').forEach(b=>b.addEventListener('click',()=>{selectedUser=String(b.dataset.user);conversationId=Number(b.dataset.id||0);lastSignature='';refresh();}));
       document.getElementById('rchatSubtitle').textContent='Bandeja de conversaciones · Administración';
     }else{
       list.style.display='none';conversationId=Number(result.conversacion_id||0);
       document.getElementById('rchatSubtitle').textContent='Conversa con los administradores';
     }
     if(!conversationId&&!isAdmin){messages.innerHTML='<div class="rchat-empty">¡Hola! Escríbenos y un administrador te responderá por aquí.</div>';lastSignature='';return;}
     if(!conversationId&&isAdmin){messages.innerHTML='<div class="rchat-empty">Escribe el primer mensaje a este usuario para iniciar la conversación.</div>';lastSignature='';}
     // Se consulta mediante GET para que el ID nunca determine la autorización del usuario.
     const messageUrl='index.php?action=chat_messages&conversation_id='+conversationId+(isAdmin&&selectedUser?'&usuario_id='+encodeURIComponent(selectedUser):'');
     const response=await fetch(messageUrl,{credentials:'same-origin',headers:{'Accept':'application/json'}});
     const payload=await response.json();
     if(!payload.ok)throw new Error(payload.message||'No se pudieron cargar los mensajes.');
     const rows=payload.mensajes||[], signature=rows.map(m=>m.ID).join(',');
     if(signature!==lastSignature){
       messages.innerHTML=rows.length?rows.map(m=>{
         const mine=Number(m.REMITENTE_ID)===Number(currentRadarUser.id);
         const senderType=String(m.REMITENTE_TIPO||'').toLowerCase();
         const isAdminSender=senderType==='admin';
         const senderName=isAdminSender?'':(m.REMITENTE||m.REMITENTE_TIPO||'Usuario');
         const messageTime=time(m.CREADO_EN);
         const messageMeta=isAdminSender?messageTime:[senderName,messageTime].filter(Boolean).join(' · ');
         return `<div class="rchat-message ${mine?'mine':'theirs'}">${radarAvatar(m.REMITENTE_FOTO,senderName,isAdminSender)}<div class="rchat-bubble ${mine?'mine':'theirs'}">${esc(m.MENSAJE)}<small>${esc(messageMeta)}</small></div></div>`;
       }).join(''):'<div class="rchat-empty">Esta conversación está lista. Envía tu primer mensaje para contactar con Administración.</div>';
       messages.scrollTop=messages.scrollHeight;lastSignature=signature;
     }
   }catch(e){messages.innerHTML='<div class="rchat-empty">'+esc(e.message||'No se pudo conectar con el chat.')+'</div>';}
   finally{pollBusy=false;}
 }
 form.addEventListener('submit',async e=>{
   e.preventDefault();const message=input.value.trim();if(!message||!currentRadarUser)return;
   send.disabled=true;
   try{const r=await apiRequest('chat_send',{mensaje:message,conversacion_id:conversationId,usuario_id:isAdmin?Number(selectedUser):0});conversationId=Number(r.conversacion_id||conversationId);input.value='';lastSignature='';await refresh();}
   catch(err){alert(err.message||'No se pudo enviar el mensaje.');}
   finally{send.disabled=false;input.focus();}
 });
 input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();form.requestSubmit();}});
 let lastAuthenticatedState=null;
 function syncChatLauncherAuth(){
   const authenticated=!!currentRadarUser;
   if(authenticated===lastAuthenticatedState)return;
   lastAuthenticatedState=authenticated;
   if(!authenticated){
     launcher.style.display='none';
     panel.classList.remove('open');
     list.style.display='none';
     messages.innerHTML='<div class="rchat-empty">Inicia sesión para conversar con los administradores.</div>';
     input.value='';
     conversationId=0;selectedUser='';lastSignature='';
   }else if(!panel.classList.contains('open')){
     launcher.style.display='block';
   }
 }
 syncChatLauncherAuth();
 setInterval(()=>{
   syncChatLauncherAuth();
   if(currentRadarUser&&panel.classList.contains('open'))refresh();
 },1000);
})();
</script>

</body>
</html>
