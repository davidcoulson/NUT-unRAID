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

/* Hard upper bound for upsc/upscmd calls, as a backstop in case a call
 * stalls past the client library's own connect timeout. */
const NUT_CLIENT_TIMEOUT = '/usr/bin/timeout -k 2 15 ';

/* Run upsc with the given (already escaped) arguments. Returns the exit
 * code, fills $out with the stdout lines and tells whether upsd itself
 * could not be reached (as opposed to it answering with an error). */
function nut_upsc($args, &$out, &$unreachable) {
    $out = [];
    $unreachable = false;

    $proc = proc_open(NUT_CLIENT_TIMEOUT . '/usr/bin/upsc ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return 1;
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($stdout !== false && $stdout !== '') {
        $out = explode("\n", rtrim($stdout, "\n"));
    }
    $unreachable = ($code === 124 || $code === 137 || stripos((string) $stderr, 'Connection failure') !== false);

    return $code;
}

/* Short-lived cache in RAM so the footer, dashboard tile and details page
 * (separate requests, separate timers) share one upsc call. */
const NUT_ROWS_CACHE_TTL = 3;

function nut_status_rows($name, $ip) {
    $file = '/dev/shm/nut-dw-rows-' . md5($name . '@' . $ip) . '.json';
    if (is_file($file) && (time() - (int)@filemtime($file)) < NUT_ROWS_CACHE_TTL) {
        $cached = json_decode((string)@file_get_contents($file), true);
        if (is_array($cached) && $cached) return $cached;
    }
    $rows = nut_status_rows_uncached($name, $ip);
    if ($rows) { // never cache a failure
        $tmp = $file . '.' . getmypid();
        if (@file_put_contents($tmp, json_encode($rows)) !== false) @rename($tmp, $file);
    }
    return $rows;
}

function nut_status_rows_uncached($name, $ip) {
    $ups = escapeshellarg($name) . '@' . escapeshellarg($ip);

    $status = nut_upsc($ups, $rows, $unreachable);

    if ($status === 0 && !empty($rows)) {
        return $rows;
    }

    // The fallback below is for an upsd that answers but cannot list all
    // variables. If upsd is not reachable at all, every one of those queries
    // would wait for the same connect timeout, so give up right away.
    if ($unreachable) {
        return [];
    }

    // Fallback for broken NUT protocol implementations
    // Try to get a number of known important variables one by one...
    $vars = [
        'battery.charge.low',
        'battery.charge',
        'battery.low', // according to user reports for UniFi UPS
        'battery.runtime',
        'battery.voltage',
        'input.current',
        'input.frequency',
        'input.voltage',
        'output.current',
        'output.frequency',
        'output.power.nominal', // according to user reports for UniFi UPS
        'output.power', // according to user reports for UniFi UPS
        'output.voltage',
        'ups.id',
        'ups.load',
        'ups.mfr',
        'ups.model',
        'ups.power.nominal',
        'ups.power',
        'ups.realpower.nominal',
        'ups.realpower',
        'ups.serial',
        'ups.status',
        'ups.test.date',
        'ups.test.interval',
        'ups.test.result',
        'ups.type',
    ];

    $rows = [];
    foreach ($vars as $var) {
        $code = nut_upsc($ups . ' ' . escapeshellarg($var), $out, $unreachable);

        if ($unreachable) {
            break;
        }

        if ($code === 0 && isset($out[0]) && $out[0] !== '') {
            $rows[] = $var . ': ' . $out[0];
        }
    }

    if(!empty($rows)) {
        $rows[] = "x.plugin.varfetch: fallback";
    }

    return $rows;
}

/* get options for battery level */
function nut_get_battery_options($selected=20){
    $range = [1,99];
    rsort($range);
    $options = "";
    foreach(range($range[0], $range[1], 1) as $level){
        $options .= "<option value='$level'";

        // set saved option as selected
        if (intval($selected) === $level) {
            $options .= " selected";
        }

        $options .= ">$level</option>";
    }
    return $options;
}

/* get options for time intervals */
function nut_get_minute_options($time){
    $options = '';
        for($i = 1; $i <= 180; $i++){
            $options .= '<option value="'.($i*60).'"';

            if(intval($time) === ($i*60)) {
                $options .= ' selected';
            }

            $options .= '>'.$i.'</option>';
        }
    return $options;
}

function nut_ups_status($rows, $valueOnly = false)
{
    global $nut_states;

    $severity = 0;
    $status_values = [];
    $status_fulltext = [];

    array_walk($rows, function($row) use (&$severity, &$status_fulltext, &$status_values, $nut_states, $valueOnly) {
        if ($valueOnly) {
            # if only ups.status value as param
            $status_values = explode(' ', $row);
        }
        elseif (preg_match('/^ups.status:\s*([^$]+)/i', $row, $matches)) {
            # if status array as param, find ups.status
            $status_values = explode(' ', $matches[1]);
        } else {
            # skip everything else
            return;
        }

        # if debug constant defined, overwrite ups.status values
        if (defined('NUT_STATUS_DEBUG')) {
            $status_values = explode(' ', NUT_STATUS_DEBUG);
        }

        # replace ups.status flags with full text message.
        $status_fulltext = array_map(function($var) use (&$severity, $nut_states) {
            if (isset($nut_states[$var]) && $nut_states[$var]) {
                # keep the highest severity message level
                $severity = max($severity, $nut_states[$var]['severity']);
                return $nut_states[$var]['msg'];
            } else {
                # if unknown status flag, return it (escaped, it comes from the UPS)
                return htmlspecialchars($var);
            }
        }, $status_values);
    });

    # return highest severity message level, array of status flags and array of full text status message
    return ['severity' => $severity, 'value' => $status_values, 'fulltext' => $status_fulltext];
}

function nut_download_url($url, $conn_timeout = 15, $timeout = 45) {
    try {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FRESH_CONNECT, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $conn_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_REFERER, "");
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $out = curl_exec($ch) ?: false;
        curl_close($ch);
        return $out;
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_get_dev_message() {
    try {
        $dev_message_url = "https://raw.githubusercontent.com/desertwitch/NUT-unRAID/master/plugin/developer_message";
        $raw_dev_message = nut_download_url($dev_message_url, 30, 45);
        if($raw_dev_message && strpos($raw_dev_message, "NODISPLAY") === false) {
            return $raw_dev_message;
        } else {
            return false;
        }
    } catch (\Throwable $t) { // For PHP 7
        return false;
    } catch (\Exception $e) { // For PHP 5
        return false;
    }
}

function nut_tailFile($filePath, $lines = 90) {
    try {
        if (!file_exists($filePath)) {
            throw new Exception("... requested syslog file is either empty or does not exist (yet)");
        }

        $f = fopen($filePath, "r");
        if (!$f) {
            throw new Exception("... requested syslog file exists but was not accessible");
        }

        $buffer = 4096;
        $lineCount = 0;
        $position = -1;
        $text = '';

        fseek($f, 0, SEEK_END);

        while ($lineCount < $lines) {
            $position -= $buffer;
            if ($position < -ftell($f)) {
                $position = -ftell($f);
            }

            fseek($f, $position, SEEK_END);
            $chunk = fread($f, $buffer);
            $text = $chunk . $text;
            $lineCount = substr_count($text, "\n");

            if ($position == -ftell($f)) {
                break;
            }
        }

        fclose($f);

        $allLines = explode("\n", str_replace("\r\n", "\n", $text));
        $sanitizedLines = array_map('htmlspecialchars', array_slice($allLines, -$lines));
        return implode("\n", $sanitizedLines);
    } catch (\Throwable $t) {
        return $t->getMessage();
    } catch (\Exception $e) {
        return $e->getMessage();
    }
}

/* All UPS names (ups.conf sections plus the GUI UPS) => the host to reach them on. */
function nut_ups_list($gui_name, $gui_ip) {
    $list = [];
    if (preg_match_all('/^\s*\[([A-Za-z0-9._-]+)\]/m', (string)@file_get_contents('/etc/nut/ups.conf'), $m)) {
        foreach ($m[1] as $n) $list[$n] = '127.0.0.1';
    }
    $list[$gui_name] = $gui_ip;
    return $list;
}

/* Parse the output of `upsrw <ups>` into [name => [desc, type, value, options[], maxlen]].
 * Blocks look like "[var]", a description line, "Type: ENUM|RANGE|NUMBER|STRING", "Option: ..." lines,
 * "Maximum length: n" and "Value: v". RANGE options are "min max" or "min - max". */
function nut_parse_upsrw($lines) {
    $vars = [];
    $cur = null;
    foreach ($lines as $line) {
        $line = rtrim($line, "\r\n");
        if (preg_match('/^\[([a-z0-9._]+)\]$/', $line, $m)) {
            $cur = $m[1];
            $vars[$cur] = ['desc' => '', 'type' => '', 'value' => '', 'options' => [], 'maxlen' => null];
        } elseif ($cur === null || trim($line) === '') {
            continue;
        } elseif (preg_match('/^Type: (\w+)/', $line, $m)) {
            $vars[$cur]['type'] = $m[1];
        } elseif (preg_match('/^Maximum length: (\d+)/', $line, $m)) {
            $vars[$cur]['maxlen'] = (int)$m[1];
        } elseif (preg_match('/^Option: "(.*)"( SELECTED)?$/', $line, $m)) {
            $vars[$cur]['options'][] = $m[1];
            if (!empty($m[2])) $vars[$cur]['value'] = $m[1]; // ENUM blocks have no Value line
        } elseif (preg_match('/^Value: ?(.*)$/', $line, $m)) {
            $vars[$cur]['value'] = $m[1];
        } elseif ($vars[$cur]['desc'] === '') {
            $vars[$cur]['desc'] = $line;
        }
    }
    return $vars;
}

/* Check $value against a variable parsed by nut_parse_upsrw(). Returns an error text, or null if allowed. */
function nut_check_upsrw_value($var, $value) {
    if (preg_match('/[\x00-\x1f\x7f]/', $value)) return 'Control characters are not allowed.';
    switch ($var['type']) {
        case 'ENUM':
            return in_array($value, $var['options'], true) ? null : 'Value is not one of the allowed options.';
        case 'RANGE':
            if (!is_numeric($value)) return 'Value must be a number.';
            foreach ($var['options'] as $o) {
                if (preg_match('/^(-?[0-9.]+)\s+(-?[0-9.]+)$/', $o, $m) || preg_match('/^(-?[0-9.]+)\s*-\s*(-?[0-9.]+)$/', $o, $m)) {
                    if ($value + 0 >= $m[1] + 0 && $value + 0 <= $m[2] + 0) return null;
                }
            }
            return 'Value is outside the allowed range.';
        case 'NUMBER':
            return is_numeric($value) ? null : 'Value must be a number.';
        case 'STRING':
            return ($var['maxlen'] === null || strlen($value) <= $var['maxlen']) ? null : 'Value is longer than ' . $var['maxlen'] . ' characters.';
    }
    return 'Unknown variable type.';
}
/* One summary per UPS for the "All UPS devices" table and the dashboard line, from the cached upsc rows.
 * Every returned string is already HTML-escaped. ok=false means upsc returned nothing (unreachable). */
function nut_ups_overview($list) {
    global $nut_runtime, $nut_rtunit, $nut_msgSeverity;
    $out = [];
    foreach ($list as $name => $ip) {
        $v = [];
        foreach (nut_status_rows($name, $ip) as $r) {
            $p = explode(':', $r, 2);
            if (count($p) == 2) $v[trim($p[0])] = trim($p[1]);
        }
        $e = fn($k, $unit = '') => (isset($v[$k]) && $v[$k] !== '') ? htmlspecialchars($v[$k]) . $unit : '-';
        $st = nut_ups_status(isset($v['ups.status']) ? ['ups.status: ' . $v['ups.status']] : []);
        $rt = (isset($v[$nut_runtime]) && is_numeric($v[$nut_runtime])) ? gmdate('H:i:s', (int)round($v[$nut_runtime] * ($nut_rtunit == 'minutes' ? 60 : 1))) : '-';
        $out[$name] = [
            'ok'     => !empty($v),
            'desc'   => htmlspecialchars(implode(' ', array_filter([$v['device.mfr'] ?? ($v['ups.mfr'] ?? ''), $v['ups.model'] ?? '', $v['ups.desc'] ?? '']))) ?: '-',
            'status' => $st['fulltext'] ? implode(' - ', $st['fulltext']) : '-',
            'css'    => $nut_msgSeverity[$st['severity']]['css_class'] ?? '',
            'word'   => $st['fulltext'][0] ?? '-',
            'bat'    => $e('battery.charge', '&thinsp;%'),
            'rt'     => $rt,
            'load'   => $e('ups.load', '&thinsp;%'),
            'vin'    => $e('input.voltage', '&thinsp;V'),
            'vout'   => $e('output.voltage', '&thinsp;V'),
        ];
    }
    return $out;
}

/* Command names (name => description) from `upscmd -l <ups>@<host>`; $target is already escaped. Empty on failure. */
function nut_upscmd_list($target) {
    $lines = [];
    exec(NUT_CLIENT_TIMEOUT . '/usr/bin/upscmd -l ' . $target . ' 2>/dev/null', $lines, $ret);
    $cmds = [];
    if ($ret === 0 && preg_match_all('/^(.*?) - (.*?)$/m', implode("\n", $lines), $m, PREG_SET_ORDER)) {
        foreach ($m as $c) $cmds[$c[1]] = $c[2];
    }
    return $cmds;
}

/* Outlets from upsc rows ("key: value") and the upscmd -l command names: outlet.N.* and outlet.group.N.*
 * variables, plus outlet.[group.]N.load.off|on|cycle commands. Returns [id => [desc, status, switchable, cmds[action => command]]]. */
function nut_parse_outlets($rows, $cmdnames) {
    $o = [];
    $blank = ['desc' => '', 'status' => '', 'switchable' => '', 'cmds' => []];
    foreach ($rows as $r) {
        if (preg_match('/^outlet\.((?:group\.)?\d+)\.(desc|name|status|switchable):\s*(.*)$/', $r, $m)) {
            $o[$m[1]] ??= $blank;
            $k = $m[2] === 'name' ? 'desc' : $m[2];
            if ($m[2] !== 'name' || $o[$m[1]]['desc'] === '') $o[$m[1]][$k] = trim($m[3]);
        }
    }
    foreach ($cmdnames as $c) {
        if (preg_match('/^outlet\.((?:group\.)?\d+)\.load\.(off|on|cycle)$/', $c, $m)) {
            $o[$m[1]] ??= $blank;
            $o[$m[1]]['cmds'][$m[2]] = $c;
        }
    }
    uksort($o, 'strnatcmp');
    return $o;
}
?>
