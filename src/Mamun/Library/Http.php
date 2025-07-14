<?php

namespace App\Library;

use App\Library\Mamun;

class Http 
{

    public function getRequest($url, $param = null, $token = null, $method = 'POST') {
        $result = null;
        if ($url and $method) {
            $curl = curl_init();
            curl_setopt($curl, CURLOPT_URL, $url);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
            if ($token) curl_setopt($curl, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
            if ($param) curl_setopt($curl, CURLOPT_POSTFIELDS, $param);
            $result = curl_exec($curl);
            curl_close($curl);
        }
        return $result;
    }

}