<?
/* Copyright Derek Macias (parts of code from NUT package)
 * Copyright macester (parts of code from NUT package)
 * Copyright gfjardim (parts of code from NUT package)
 * Copyright SimonF (parts of code from NUT package)
 * Copyright Dan Landon (parts of code from Web GUI)
 * Copyright Bergware International (parts of code from Web GUI)
 * Copyright Lime Technology (any and all other parts of Unraid)
 *
 * Copyright desertwitch (as author and maintainer of this file)
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 */
/* Slave-mode connection test: is the NUT server reachable, does the UPS name exist, what is its status.
 * upsc never logs in, so the monitor username/password are NOT checked here. */
header('Content-Type: application/json');

const NUT_TEST_UPSC = 'timeout 5 /usr/bin/upsc ';

/* hostname / IPv4, optionally with :port; bracketed IPv6 with optional :port; bare IPv6 */
function nut_testconn_valid_host($h) {
    $label = '[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?';
    $port  = '(?::[0-9]{1,5})?';
    $re = "/^(?:$label(?:\\.$label)*$port|\\[[0-9A-Fa-f:.]+\\]$port|(?=(?:[^:]*:){2})[0-9A-Fa-f:.]+)$/D";
    return is_string($h) && strlen($h) <= 255 && preg_match($re, $h) === 1;
}

/* no leading "-", so "name@host" can never be taken for an option */
function nut_testconn_valid_name($n) {
    return is_string($n) && strlen($n) <= 64 && preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]*$/D', $n) === 1;
}

/* run a command string (arguments already escaped); returns [exit code, stdout lines, stderr text] */
function nut_testconn_run($cmd) {
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) return [127, [], 'could not run upsc'];
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    return [$code, $out === '' ? [] : explode("\n", rtrim($out, "\n")), trim($err)];
}

function nut_testconn($host, $name) {
    $res = ['ok' => false, 'lines' => []];
    if (!nut_testconn_valid_host($host)) { $res['lines'][] = ['fail', 'Invalid host: use a hostname, IPv4 or IPv6 address (optionally with :port).']; return $res; }
    if (!nut_testconn_valid_name($name)) { $res['lines'][] = ['fail', 'Invalid UPS name: use only letters, digits, dot, underscore and dash.']; return $res; }

    [$code, $list, $err] = nut_testconn_run(NUT_TEST_UPSC . '-l ' . escapeshellarg($host));
    if ($code === 124) { $res['lines'][] = ['fail', "No response from $host within 5 seconds."]; return $res; }
    if ($code !== 0) { $res['lines'][] = ['fail', "Could not reach the NUT server at $host" . ($err !== '' ? ": $err" : '.')]; return $res; }
    $res['lines'][] = ['ok', "NUT server at $host is reachable."];

    // upsc -l prints "name" or "name: description"
    $names = array_map(function ($l) { return trim(explode(':', $l, 2)[0]); }, $list);
    if (!in_array($name, $names, true)) {
        $res['lines'][] = ['fail', "UPS \"$name\" was not found on that server." . ($names ? ' Available: ' . implode(', ', $names) . '.' : '')];
        return $res;
    }
    $res['lines'][] = ['ok', "UPS \"$name\" exists."];

    [$code, $st, $err] = nut_testconn_run(NUT_TEST_UPSC . escapeshellarg($name . '@' . $host) . ' ups.status');
    if ($code === 0 && $st) {
        $res['ok'] = true;
        $res['lines'][] = ['ok', 'Status: ' . $st[0]];
    } else {
        $res['lines'][] = ['fail', 'Could not read ups.status' . ($err !== '' ? ": $err" : '.')];
    }
    $res['lines'][] = ['note', 'Note: the monitor username and password are not checked by this test (upsc does not log in).'];
    return $res;
}

$res = nut_testconn($_GET['host'] ?? '', $_GET['name'] ?? '');
$html = '<ul>';
foreach ($res['lines'] as [$kind, $text]) {
    $html .= '<li class="nut-' . $kind . '">' . htmlspecialchars($text, ENT_QUOTES) . '</li>';
}
echo json_encode(['ok' => $res['ok'], 'html' => $html . '</ul>']);
?>
