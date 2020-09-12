<?php

namespace App\Http\Controllers\Gateway;

use App\Models\Payment;
use Auth;
use GuzzleHttp\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Response;

class EPay extends AbstractPayment
{

    public function purchase(Request $request): JsonResponse
    {
        $payment = $this->creatNewPayment(
            Auth::id(),
            $request->input('id'),
            $request->input('amount')
        );

        switch ($request->input('type')) {
            case 2:
                $type = 'qqpay';
                break;
            case 3:
                $type = 'wxpay';
                break;
            case 1:
            default:
                $type = 'alipay';
                break;
        }

        $data         = [
            'pid'          => sysConfig('epay_mch_id'),
            'type'         => $type,
            'notify_url'   => (sysConfig('website_callback_url') ?: sysConfig(
                    'website_url'
                )) . '/callback/notify?method=epay',
            'return_url'   => sysConfig('website_url') . '/invoices',
            'out_trade_no' => $payment->trade_no,
            'name'         => sysConfig('subject_name') ?: sysConfig(
                'website_name'
            ),
            'money'        => $payment->amount,
            'sign_type'    => 'MD5',
        ];
        $data['sign'] = $this->aliStyleSign($data, sysConfig('epay_key'));

        $url = sysConfig('epay_url') . 'submit.php?' . http_build_query($data);
        $payment->update(['url' => $url]);

        return Response::json(
            ['status' => 'success', 'url' => $url, 'message' => '创建订单成功!']
        );
    }

    public function notify(Request $request): void
    {
        if ($request->input('trade_status') === 'TRADE_SUCCESS'
            && $this->verify(
                $request->except('method'),
                sysConfig('epay_key'),
                $request->input('sign')
            )) {
            $payment = Payment::whereTradeNo($request->input('out_trade_no'))
                              ->first();
            if ($payment) {
                $ret = $payment->order->update(['status' => 2]);
                if ($ret) {
                    exit('SUCCESS');
                }
            }
        }
        exit('FAIL');
    }

    public function queryInfo(): JsonResponse
    {
        $request = (new Client())->get(
            sysConfig('epay_url') . 'api.php',
            [
                'query' => [
                    'act' => 'query',
                    'pid' => sysConfig('epay_mch_id'),
                    'key' => sysConfig('epay_key'),
                ],
            ]
        );
        if ($request->getStatusCode() == 200) {
            return Response::json(
                [
                    'status' => 'success',
                    'data'   => json_decode($request->getBody(), true),
                ]
            );
        }

        return Response::json(
            ['status' => 'fail', 'message' => '获取失败！请检查配置信息']
        );
    }

}
