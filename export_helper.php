<?php
/**
 * Helper: ejecuta un script Python pasando datos JSON por stdin
 * Devuelve el output binario (xlsx o pdf)
 */
function run_python_export(string $pyCode, array $data): string {
    $tmpPy   = tempnam(sys_get_temp_dir(), 'exp') . '.py';
    $tmpJson = tempnam(sys_get_temp_dir(), 'dat') . '.json';

    file_put_contents($tmpPy,   $pyCode);
    file_put_contents($tmpJson, json_encode($data, JSON_UNESCAPED_UNICODE));

    $cmd    = 'python3 ' . escapeshellarg($tmpPy) . ' < ' . escapeshellarg($tmpJson) . ' 2>/tmp/py_err.txt';
    $output = '';
    $handle = popen($cmd, 'r');
    if ($handle) {
        while (!feof($handle)) $output .= fread($handle, 8192);
        pclose($handle);
    }

    @unlink($tmpPy);
    @unlink($tmpJson);

    if (empty($output)) {
        $err = @file_get_contents('/tmp/py_err.txt');
        die('Error al generar archivo: ' . htmlspecialchars($err));
    }
    return $output;
}
