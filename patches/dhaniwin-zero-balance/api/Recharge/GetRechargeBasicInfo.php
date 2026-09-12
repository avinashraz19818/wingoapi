<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

$endpoint = 'Recharge/GetRechargeBasicInfo';
$override = api_get_override($endpoint);
if ($override) {
    $decoded = api_json_decode_lenient((string) $override['content']);
    if ($decoded['ok']) {
        $payload = $decoded['data'];
        api_refresh_times($payload);
        api_emit($payload);
    }
}

$payload = [
        'data' => [
            'gameSaasBalance' => [
                [
                    'vendorCode' => 'ARGame',
                    'balance' => 0,
                    'currency' => 'INR',
                    'tenantId' => 6006,
                    'userId' => 60060000132257,
                ],
                [
                    'vendorCode' => 'PlatForm',
                    'balance' => 0.0,
                    'currency' => 'INR',
                    'tenantId' => 6006,
                    'userId' => 60060000132257,
                ],
            ],
            'goodsList' => [],
            'advisementList' => [],
            'onGoingOrder' => null,
            'amountCoding' => 4.11,
            'classicBonusDetails' => null,
        ],
        'code' => 0,
        'msg' => 'Succeed',
        'msgCode' => 0,
        'serverTime' => 1780315478163,
    ];

api_refresh_times($payload);
api_emit($payload);
