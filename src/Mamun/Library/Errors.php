<?php

namespace App\Library;

use Illuminate\Http\Response;

class Errors
{
    protected static $errors = [];

    public static function has() {
        return !empty(self::$errors);
    }

    public static function add($key, $replace = [], $message = null)
    {
        $message = $message ?? __($key);

        if (is_array($replace) && $message !== $key) {
            foreach ($replace as $search => $value) {
                $message = str_replace($search, __($value), $message);
            }
        }
        
        self::$errors[$key] = $message;
    }

    public static function get($key)
    {
        return self::$errors[$key] ?? null;
    }

    public static function all()
    {
        $messages = array_values(self::$errors);
        $codes = array_keys(self::$errors);
        $count = count(self::$errors);

        if ($count > 1) {
            return [
                'code' => $codes,
                'message' => implode(PHP_EOL, $messages),
            ];
        }

        if ($count === 1) {
            return [
                'code' => $codes[0] ?? 'unknown',
                'message' => $messages[0] ?? 'unknown',
            ];
        }

        return [
            'code' => 'unknown',
            'message' => 'unknown',
        ];
    }

    public static function clear()
    {
        self::$errors = [];
    }

    public static function lang($request)
    {
        $locale = $request->header('accept-language');
        if (!in_array($locale, ['uz', 'en', 'ru'])) {
            $locale = 'en';
        }
        app()->setLocale($locale);
    }

    public static function flush($key = null, $replace = [], $message = null)
    {
        if ($key) {
            self::add($key, $replace, $message);
        }

        $errors = self::all();
        self::clear();
        return $errors;
    }

    public static function response($status = 400, $key = null, $replace = [], $message = null)
    {
        $data = [
            'status' => $status, 
            'errors' => self::flush($key, $replace, $message),
            'data' => [],
        ];

        return response($data, $status);
    }

}