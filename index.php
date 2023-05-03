<?php

// api token and account id
const CF_API_TOKEN = '';
const CF_ACCOUNT = '';

$headers = [
    'Authorization: Bearer ' . CF_API_TOKEN,
    'Content-Type: application/json'
];

$nameServers = [];

$domains = file('domains.txt', FILE_IGNORE_NEW_LINES);

function getDnsRecords($domain)
{
    return [
        // dns records array
        [
            'name' => $domain,
            'content' => '', // ip address 
            'type' => 'A',
            'proxied' => true,
        ]
    ];
}

function errorHandler($response)
{
    $responseJson = json_decode($response);
    if ($responseJson->success === false) {
        var_dump($responseJson->errors[0]->message);
    };
}

function setUpDomains($headers, $domain)
{
    // adding a domain and getting its id and NS

    $ch = curl_init();

    $options = array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => 1,
        CURLOPT_URL => 'https://api.cloudflare.com/client/v4/zones/',
        CURLOPT_POSTFIELDS => json_encode([
            "account" => [
                "id" => CF_ACCOUNT
            ],
            "name" => $domain,
            "jump_start" => true
        ]),
    );
    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    errorHandler($response);

    $nameServers = json_decode($response)->result->name_servers;
    $domainId = json_decode($response)->result->id;

    curl_close($ch);

    // changing domain's settings

    //create the multiple cURL handle
    $mh = curl_multi_init();

    $chArr = [];

    //getting DNS records array
    $dnsRecords = getDnsRecords($domain);

    // adding DNS records
    for ($i = 0; $i < count($dnsRecords); $i++) {
        $chArr[$i] = curl_init();
        $options = array(
            CURLOPT_POST => 1,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_URL => 'https://api.cloudflare.com/client/v4/zones/' . $domainId . '/dns_records',
            CURLOPT_POSTFIELDS => json_encode($dnsRecords[$i])
        );
        curl_setopt_array($chArr[$i], $options);
        curl_multi_add_handle($mh, $chArr[$i]);
    }

    // other settings

    $patchRequests = array(
        [
            'endpoint' => 'settings/ssl',
            'value' => 'flexible'
        ],
        [
            'endpoint' => 'settings/security_level',
            'value' => 'low'
        ],
        [
            'endpoint' => 'settings/browser_check', // browser integrity
            'value' => 'off'
        ],
        [
            'endpoint' => 'settings/automatic_https_rewrites',
            'value' => 'off'
        ],
        [
            'endpoint' => 'settings/always_use_https',
            'value' => 'on'
        ],
        [
            'endpoint' => 'settings/brotli',
            'value' => 'off'
        ],

    );

    foreach ($patchRequests as $request) {
        $chArr[$request['endpoint']] = curl_init();
        $options = array(
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => 'https://api.cloudflare.com/client/v4/zones/' . $domainId . '/' . $request['endpoint'],
            CURLOPT_POSTFIELDS => json_encode(["value" => $request['value']])
        );
        curl_setopt_array($chArr[$request['endpoint']], $options);
        curl_multi_add_handle($mh, $chArr[$request['endpoint']]);
    }

    //execute the multi handle
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            curl_multi_select($mh);
        }
    } while ($active && $status == CURLM_OK);

    // get content and remove handles
    foreach ($chArr as $ch) {
        $response = curl_multi_getcontent($ch);
        errorHandler($response);
        curl_multi_remove_handle($mh, $ch);
    }

    // close multi handle
    curl_multi_close($mh);

    return $nameServers;
}

for ($i = 0; $i < count($domains); $i++) {
    $nameServers[$domains[$i]] = setUpDomains($headers, $domains[$i]);
    // executing the main function setUpDomains for each domain and returning NS
}

$file = 'name_servers.txt';
file_put_contents($file, print_r($nameServers, true));
