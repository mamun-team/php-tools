<?php

namespace App\Library;

use App\Library\Http;
use App\Library\Hemis;
use App\Library\Bot;
use App\Library\Utility;
use App\Library\QRCode;
use App\Library\Caching;
use App\Library\Cloudinary;

class Mamun {

    public static function Http() {
        return new Http();
    }

    public static function Hemis() {
        return new Hemis();
    }

    public static function Bot($token, $data = null) {
        return new Bot($token, $data);
    }

    public static function Utility() {
        return new Utility();
    }

    public static function QRCode($data, $options = []) {
        return new QRCode($data, $options);
    }

    public static function Caching($place, $ttl = null) {
        return new Caching($place, $ttl);
    }

    public static function Cloudinary($folder = 'uploads') {
        return new Cloudinary($folder);
    }

}