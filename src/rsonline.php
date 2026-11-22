<?php
/**
 * Klien HTTP sederhana untuk API RS Online (SIRS Kemenkes).
 * Header X-Timestamp dihitung ulang pada setiap request.
 */

function rsonline_headers() {
    return [
        'X-rs-id: '     . RS_ID,
        'X-Timestamp: ' . gmdate('U'),
        'X-pass: '      . RS_PASS,
        'Content-Type: application/json',
    ];
}

function rsonline_curl_options($method, $payload = null) {
    $opt = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => CURL_VERIFY_SSL,
        CURLOPT_SSL_VERIFYHOST => CURL_VERIFY_SSL ? 2 : 0,
        CURLOPT_TIMEOUT        => 40,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_HTTPHEADER     => rsonline_headers(),
    ];
    if (CURL_CAINFO !== '') $opt[CURLOPT_CAINFO] = CURL_CAINFO;
    if ($payload !== null)  $opt[CURLOPT_POSTFIELDS] = $payload;
    return $opt;
}

/** Satu request. @return array{http_code:int,curl_err:string,resp:string,ok:bool} */
function rsonline_request($method, $payload = null) {
    $ch = curl_init(BASE_URL);
    curl_setopt_array($ch, rsonline_curl_options($method, $payload));
    $resp = curl_exec($ch);
    $res = [
        'http_code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'curl_err'  => curl_error($ch),
        'resp'      => ($resp === false) ? '' : (string)$resp,
    ];
    curl_close($ch);
    $res['ok'] = ($res['http_code'] >= 200 && $res['http_code'] < 300 && $res['curl_err'] === '');
    return $res;
}

/**
 * Kirim banyak item paralel (cURL multi) per gelombang agar server Kemenkes
 * tidak dibanjiri. Setiap item wajib punya kunci 'payload' (JSON string).
 * @return array hasil dengan kunci indeks yang sama seperti $items
 */
function rsonline_send_batch(array $items, $method = 'PUT', $batch_size = 20) {
    $results = [];
    $chunks  = array_chunk($items, $batch_size, true);

    foreach ($chunks as $n => $chunk) {
        $mh = curl_multi_init();
        $handles = [];
        foreach ($chunk as $i => $item) {
            $ch = curl_init(BASE_URL);
            curl_setopt_array($ch, rsonline_curl_options($method, $item['payload']));
            curl_multi_add_handle($mh, $ch);
            $handles[$i] = $ch;
        }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            if ($running > 0) curl_multi_select($mh, 1);
        } while ($running > 0);

        foreach ($handles as $i => $ch) {
            $res = [
                'http_code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'curl_err'  => curl_error($ch),
                'resp'      => (string)curl_multi_getcontent($ch),
            ];
            $res['ok'] = ($res['http_code'] >= 200 && $res['http_code'] < 300 && $res['curl_err'] === '');
            $results[$i] = $res;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($n < count($chunks) - 1) usleep(300000); // jeda 0,3 dtk antar gelombang
    }
    return $results;
}
