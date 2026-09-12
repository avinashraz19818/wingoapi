<?php
require_once dirname(__DIR__) . '/_bootstrap.php';

$endpoint = 'User/GetUserFinancialList';
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
            'list' => [
                [
                    'id' => '20260601120345693mg482',
                    'orderNo' => '20260601120345678mg481',
                    'vendorCode' => 'ARGame',
                    'type' => 'GameTransOut',
                    'subType' => '',
                    'amount' => 0.0,
                    'backAmount' => 0.0,
                    'createTime' => 1780315425693,
                    'remark' => '',
                ],
                [
                    'id' => '20260601120312738nh541',
                    'orderNo' => '20260601120312738nh540',
                    'vendorCode' => 'ARLottery',
                    'type' => 'GameTransIn',
                    'subType' => '',
                    'amount' => -0.0,
                    'backAmount' => 0,
                    'createTime' => 1780315392738,
                    'remark' => '',
                ],
                [
                    'id' => '20260601062327508mf644',
                    'orderNo' => '20260601062327493mf642',
                    'vendorCode' => 'ARGame',
                    'type' => 'GameTransOut',
                    'subType' => '',
                    'amount' => 0.0,
                    'backAmount' => 0.0,
                    'createTime' => 1780295007508,
                    'remark' => '',
                ],
                [
                    'id' => '20260601062325232ng350',
                    'orderNo' => '20260601062325232ng349',
                    'vendorCode' => 'ARLottery',
                    'type' => 'GameTransIn',
                    'subType' => '',
                    'amount' => -0.0,
                    'backAmount' => 0,
                    'createTime' => 1780295005232,
                    'remark' => '',
                ],
                [
                    'id' => '20260601052744621nh573',
                    'orderNo' => 'LD260601052738852nhmshEga4h',
                    'vendorCode' => '',
                    'type' => 'LuckyDoubleReward',
                    'subType' => '',
                    'amount' => 3.83,
                    'backAmount' => 0.0,
                    'createTime' => 1780291664626,
                    'remark' => '',
                ],
                [
                    'id' => '20260529192320887mf875',
                    'orderNo' => '20260529192320873mf874',
                    'vendorCode' => 'ARGame',
                    'type' => 'GameTransOut',
                    'subType' => '',
                    'amount' => 0.34,
                    'backAmount' => 0.62,
                    'createTime' => 1780082600887,
                    'remark' => '',
                ],
                [
                    'id' => '20260529192315257nh388',
                    'orderNo' => 'CW20260529192315252nh387',
                    'vendorCode' => '',
                    'type' => 'CodeWashing',
                    'subType' => 'Electronic',
                    'amount' => 0.28,
                    'backAmount' => 0.28,
                    'createTime' => 1780082595257,
                    'remark' => '洗码返水-Electronic',
                ],
                [
                    'id' => '20260529191624795mg674',
                    'orderNo' => '20260529191624795mg673',
                    'vendorCode' => 'JILI',
                    'type' => 'GameTransIn',
                    'subType' => '',
                    'amount' => -108.54,
                    'backAmount' => 0,
                    'createTime' => 1780082184795,
                    'remark' => '',
                ],
                [
                    'id' => '20260529191616216ng204',
                    'orderNo' => 'LD260529191610051nimshEga4h',
                    'vendorCode' => '',
                    'type' => 'LuckyDoubleReward',
                    'subType' => '',
                    'amount' => 5.96,
                    'backAmount' => 108.54,
                    'createTime' => 1780082176222,
                    'remark' => '',
                ],
                [
                    'id' => '20260529191554011m9944',
                    'orderNo' => 'RC260529191523616nhmshEga4h',
                    'vendorCode' => '',
                    'type' => 'Recharge',
                    'subType' => '',
                    'amount' => 100,
                    'backAmount' => 102.58,
                    'createTime' => 1780082154017,
                    'remark' => '异步回调',
                ],
            ],
            'pageNo' => 1,
            'totalPage' => 3,
            'totalCount' => 21,
        ],
        'code' => 0,
        'msg' => 'Succeed',
        'msgCode' => 0,
        'serverTime' => 1780315647683,
    ];

api_refresh_times($payload);
api_emit($payload);
