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
 */
require_once '/usr/local/emhttp/plugins/nut-dw/include/nut_config.php';
$resp = [];

/* action=list: the writable variables of the UPS (POST only, so the webGui CSRF token is checked like for commands)
 * action=set:  upsrw -s var=value with the admin credentials typed on the page; the value is checked against
 *              the variable's own definition (ENUM/RANGE/STRING length) read from the UPS again, not from the page. */
try {
    $ups_list = nut_ups_list($nut_name, $nut_ip);
    $action = $_POST["action"] ?? "";
    $ups = $_POST["nutups"] ?? "";

    if (!isset($ups_list[$ups])) {
        $resp["error"]["response"] = "Unknown UPS.";
    } elseif ($action !== "list" && $action !== "set") {
        $resp["error"]["response"] = "Missing required POST variables!";
    } else {
        $target = escapeshellarg($ups) . "@" . escapeshellarg($ups_list[$ups]);
        $rw_out = [];
        $rw_ret = -1;
        exec(NUT_CLIENT_TIMEOUT . "/usr/bin/upsrw $target 2>&1", $rw_out, $rw_ret);
        $vars = ($rw_ret === 0) ? nut_parse_upsrw($rw_out) : [];

        if ($rw_ret !== 0) {
            $resp["error"]["response"] = htmlspecialchars(implode(PHP_EOL, $rw_out));
        } elseif ($action === "list") {
            $resp["success"]["vars"] = $vars;
        } else {
            $name = $_POST["nutvar"] ?? "";
            $value = $_POST["nutvalue"] ?? "";
            $user = $_POST["nutcmduser"] ?? "";
            $pass = $_POST["nutcmdpass"] ?? "";

            if (!preg_match('/^[a-z0-9._]+$/', $name) || !isset($vars[$name])) {
                $resp["error"]["response"] = "Unknown or invalid variable name.";
            } elseif ($user === "") {
                $resp["error"]["response"] = "Missing required POST variables!";
            } elseif (($check = nut_check_upsrw_value($vars[$name], $value)) !== null) {
                $resp["error"]["response"] = htmlspecialchars($check);
            } else {
                $rw_out = [];
                exec("upsrw -s " . escapeshellarg("$name=$value") . " -u " . escapeshellarg($user) . " -p " . escapeshellarg($pass) . " $target 2>&1", $rw_out, $rw_ret);
                $resp[$rw_ret === 0 ? "success" : "error"]["response"] = htmlspecialchars(implode(PHP_EOL, $rw_out));
            }
        }
    }
}
catch (\Throwable $t) {
    error_log($t);
    $resp = [];
    $resp["error"]["response"] = $t->getMessage();
}

echo(json_encode($resp));
?>
