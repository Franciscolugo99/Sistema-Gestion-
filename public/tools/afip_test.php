<?php
declare(strict_types=1);

function probe(string $url): void {
  echo "=== $url ===\n";
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 7,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_ENCODING => '',
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => 0,
    CURLOPT_HTTPHEADER => [
      'Accept: application/json',
      'User-Agent: FLUS/1.0 (+local)'
    ],
  ]);

  $body = curl_exec($ch);
  $errno = curl_errno($ch);
  $err   = curl_error($ch);
  $http  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  echo "errno=$errno\n";
  echo "http=$http\n";
  echo "err=$err\n";
  echo "body_start:\n" . substr((string)$body, 0, 500) . "\n\n";
}

probe("https://soa.afip.gob.ar/sr-padron/v2/persona/30703088534");
probe("https://soa.afip.gob.ar/sr-padron/v2/personas-juridicas/30703088534");
probe("https://afip.tangofactura.com/Rest/GetContribuyenteFull?cuit=30703088534");
