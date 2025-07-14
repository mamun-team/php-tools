<?php

namespace App\Library;

use App\Library\Mamun;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Carbon\Carbon;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Mail;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Lang;
use Stichoza\GoogleTranslate\GoogleTranslate;

use Illuminate\Support\Facades\Log;

class Utility {

    const sms_key = '249214dd957e08d4fe7f60ec6172ad55ea25';

    private $http;

    public function __construct() {
        $this->http = Mamun::Http();
    }

    public function getLangID($value) {
        switch ($value) {
            case 'uz':
                return 1042;
                break;
            case 'ru':
                return 1043;
                break;
            default:
                return 1044;
                break;
        }
    }

    public function translate($key, $to_lang = null) {
        // Laravel lang faylida mavjud bo‘lsa, uni qaytaradi
        if (Lang::has($key)) {
            return __($key);
        }

        // Agar mavjud bo‘lmasa va til ko‘rsatilgan bo‘lsa, GoogleTranslate dan foydalanadi
        if ($to_lang) {
            try {
                if ($to_lang === 'auto') {
                    $to_lang = app()->getLocale();
                }
                $text = $key;
                $key = $this->clean($text, 'slug');
                $path = storage_path("framework/temp/{$to_lang}_auto.json");
                $translations = file_exists($path) ? json_decode(file_get_contents($path), true) : [];

                if (isset($translations[$key])) {
                    return $translations[$key];
                }

                $translated = trim(GoogleTranslate::trans($text, $to_lang, 'auto'));
                $translations[$key] = $translated;
                file_put_contents($path, json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

                return $translated;

            } catch (\Exception $e) {
                // Google Translate ishlamasa, asl matnni qaytaradi
                return $key;
            }
        }

        // Agar til ko‘rsatilmagan bo‘lsa, asl matnni qaytaradi
        return $key;
    }

    // Berilgan $keys ro‘yxatidagi har bir matn kaliti uchun $this->translate($key) orqali tarjimani olib keladi va associative array ([$key => tarjima]) shaklida qaytaradi.
    public function getLang($keys, $to_lang = null) {
        if ($keys) {
            return collect($keys)->mapWithKeys(fn($key) => [$key => $this->translate($key, $to_lang)])->toArray();
        }
        return [];
    }

    // Berilgan $data (odatda object) ichidagi content, items kabi bo‘limlardan matn kalitlarini yig‘ib, ularning tarjimalarini $data->translations degan yangi propertyga joylaydi.
    public function translations(&$data, $keys = true, $skip_keys = [], $to_lang = null) {
        
        if (empty($data)) return;

        if ($keys === true) {
            $keys = ['content', 'items'];
        } elseif (!is_array($keys) || count($keys) === 0) {
            return;
        }

        if ($skip_keys === true) {
            $skip_keys = ['options'];
        } elseif (!is_array($skip_keys)) {
            return;
        }

        // 🧱 translations ni yuqoriga qo‘shamiz
        if (!isset($data->translations)) {
            $data = (object) array_merge(
                ['translations' => (object) []],
                (array) $data
            );
        }

        foreach ($keys as $key) {
            if (!property_exists($data, $key) || in_array($key, $skip_keys)) continue;

            $value = $data->{$key};
            $fields = [];

            // 🔹 Ko‘p qatorli (array yoki collection)
            if (
                (is_array($value) && isset($value[0])) ||
                ($value instanceof \Illuminate\Support\Collection && !$value->isEmpty())
            ) {
                $first = $value instanceof \Illuminate\Support\Collection
                    ? $value->first()
                    : $value[0];

                if ($first && (is_array($first) || is_object($first))) {
                    $fields = array_keys((array) $first);
                }
            }

            // 🔹 Oddiy object (content)
            elseif (is_array($value) || is_object($value)) {
                $fields = array_keys((array) $value);
            }

            if (!empty($fields)) {
                // skip_keys asosida maydonlarni filtrlaymiz
                $filtered = array_filter($fields, fn($f) => !in_array($f, $skip_keys));
                
                if (!empty($filtered)) {
                    $data->translations->$key = $this->getLang($filtered, $to_lang);
                }
            }
        }
    }

    // Berilgan obyekt (yoki massiv) ichidagi ma’lum kalitdagi (title, description, message va h.k.) matnlarni tarjima qiladi.
    public function translateElement(&$obj, $keys = ['title', 'comment', 'help', 'description', 'message', 'info'], $to_lang = null) {
        if (is_null($obj) || empty($keys)) return;

        // Associative array yoki objectni key => value tarzida iteratsiya qilamiz
        $items = is_object($obj) ? get_object_vars($obj) : (array) $obj;

        foreach ($items as $key => &$value) {
            // 🔹 Tarjima qilinadigan maydon
            if ((in_array($key, $keys, true) || in_array('*', $keys, true)) && is_string($value)) {
                $translated = $this->translate($value, $to_lang);

                if (is_object($obj)) {
                    $obj->$key = $translated;
                } else {
                    $obj[$key] = $translated;
                }
            }

            // 🔹 options.label maxsus holat
            if ($key === 'options' && in_array('options', $keys, true) && is_array($value)) {
                foreach ($value as &$opt) {
                    if (is_array($opt) && isset($opt['label']) && is_string($opt['label'])) {
                        $opt['label'] = $this->translate($opt['label'], $to_lang);
                    } elseif (is_object($opt) && property_exists($opt, 'label') && is_string($opt->label)) {
                        $opt->label = $this->translate($opt->label, $to_lang);
                    } elseif (is_string($opt)) {
                        $opt = $this->translate($opt, $to_lang);
                    }
                }
                unset($opt);
            }

            // 🔁 Rekursiv chaqiriq
            if (is_array($value) || is_object($value)) {
                $this->translateElement($value, $keys, $to_lang);
                if (is_object($obj)) {
                    $obj->$key = $value;
                } else {
                    $obj[$key] = $value;
                }
            }
        }
    }

    public function getPaginatedItems($query, $page = 1, $limit = 10, $default = null, $translate_keys = null, $skip_keys = []) {
        // 📦 Default qiymatni object shaklida tayyorlab olamiz
        $data = (object) ($default ?: []);

        // ❌ Agar null bo‘lsa, bo‘sh object qaytariladi
        if (!$query) {
            $this->translations($data, $translate_keys, $skip_keys);
            return $data;
        }

        // 🧾 Sahifalash qiymatlarini aniqlab olamiz
        $page = ($page && $page > 0) ? (int)$page : 1;
        $limit = ($limit && $limit > 0) ? min((int) $limit, 200) : 10;

        // 🧠 1. Agar bu Query Builder yoki Eloquent bo‘lsa — paginate
        if ($query instanceof \Illuminate\Database\Query\Builder || $query instanceof \Illuminate\Database\Eloquent\Builder) {
            $offset = ($page - 1) * $limit;

            if (!empty($query->groups) || str_contains($query->toSql(), 'having')) {
                $rawSql = $query->toSql();
                $bindings = $query->getBindings();        
                $totalCount = DB::table(DB::raw("({$rawSql}) as sub"))
                    ->mergeBindings($query)
                    ->count();
            } else {
                $totalCount = $query->count();
            }

            $data->items = $query->offset($offset)->limit($limit)->get();
            $this->undot($data->items);

            $data->pagination = [
                'totalCount' => $totalCount,
                'pageSize' => $limit,
                'pageCount' => ceil($totalCount / $limit),
                'page' => $page,
            ];

            $this->translations($data, $translate_keys, $skip_keys);
            return $data;
        }

        // 📚 2. Agar bu array yoki Collection bo‘lsa — qo‘lda paginate qilamiz
        if ((is_array($query) && isset($query[0]) && (is_array($query[0]) || is_object($query[0]))) || ($query instanceof \Illuminate\Support\Collection && $query->isNotEmpty() && (is_array($query->first()) || is_object($query->first())))) {
            $items = collect($query);
            $data->items = $items->forPage($page, $limit)->values();
            $this->undot($data->items);

            $data->pagination = [
                'totalCount' => $items->count(),
                'pageSize' => $limit,
                'pageCount' => ceil($items->count() / $limit),
                'page' => $page,
            ];

            $this->translations($data, $translate_keys, $skip_keys);
            return $data;
        }
        
        $data->content = $query;
        $this->translations($data, $translate_keys, $skip_keys);
        return $data;
    }

    public function SMS($target, $phone, $vars = []) {
        $res = $this->http->getRequest('https://mamun-api.satt.uz/api/setSMS', [
            "target" => $target,
            "phone" => $phone,
            "vars" => implode('|', $vars),
            "password" => self::sms_key
        ]);
        return isset($res);
    }

    public function createTokenWithExpiry($user, $name, $days = null) {
        // Faqat shu nomdagi tokenlarni o‘chirish (barcha tokenlarni emas)
        $user->tokens()->where('name', $name)->delete();

        $tokenResult = $user->createToken($name); // Token yaratish
        $token = $tokenResult->token; // Token obyektini olish

        if ($days) {
            $expiryDate = Carbon::now()->addDays($days);
            $token->expires_at = $expiryDate;
            $token->save();
        } else {
            $expiryDate = $token->expires_at; // Agar `$days` bo‘lmasa, Passport default muddati ishlatiladi
        }

        // Obyekt sifatida natijani qaytarish
        return (object) [
            'token' => $tokenResult->accessToken,
            'expires_at' => $expiryDate->format('Y-m-d H:i:s')
        ];
    }

    public function jsonToToken($data, $secret, $ttl = 900) {
        $data['_exp'] = time() + $ttl; // Tokenning yaroqlilik muddati
        $data['_key'] = hash_hmac('sha256', 'auth', $secret); // Shaxsiy kalit
        return Crypt::encrypt(json_encode($data));
    }

    public function tokenToJson($token, $secret) {
        try {
            $data = json_decode(Crypt::decrypt($token), true);
            
            // Kalit tekshiriladi
            if (!isset($data['_key']) || $data['_key'] !== hash_hmac('sha256', 'auth', $secret)) {
                return null;
            }
    
            // Vaqt tekshiruvi
            if (!isset($data['_exp']) || time() > $data['_exp']) {
                return null;
            }

            unset($data['_key'], $data['_exp']);
            return $data;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function AuthUser($guard = 'api') {

        if (Auth::guard($guard)->check()) {
            return Auth::guard($guard)->user();
        }
        
        return null;
    }

    public function AuthRole($allowedRoles = [], $adminRoles = []) {
        $user = $this->AuthUser();
        if (!$user) return [null, false];

        $userId = $user->id;

        // 1. user_data dan role_id lar (faqat active)
        $userDataRoles = DB::table('user_data')
            ->where('user_id', $userId)
            ->where('active', true)
            ->pluck('role_id')
            ->toArray();

        // 2. user_roles dan role_id lar (faqat active + full_access)
        $userRoles = DB::table('user_roles')
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('full_access', true)
            ->pluck('role_id')
            ->toArray();

        // 3. Hammasini umumiy qilib array birlashtirish
        $roles = array_unique(array_merge($userDataRoles, $userRoles));

        // 4. Adminmi?
        $isAdmin = !empty(array_intersect($roles, $adminRoles));

        // 5. Ruxsat berilgan rolga egami?
        if (!empty($allowedRoles) && empty(array_intersect($roles, $allowedRoles))) {
            return [null, $isAdmin];
        }

        return [$userId, $isAdmin];
    }

    public function Validator($request, $rules, $default) {
        $validator = Validator::make($request, $rules);
        if ($validator->stopOnFirstFailure()->fails()) {
            return [];
        }
        return $this->ValidatorDefault($validator, $default);
    }

    public function ValidatorValue($key, $value, $rule, $default = null) {
        // Agar nullable va qiymat null bo‘lsa, avtomatik valid deb qabul qilamiz
        if (str_contains($rule, 'nullable') && is_null($value)) {
            return true;
        }

        $validator = Validator::make([$key => $value], [$key => $rule]);

        if ($validator->stopOnFirstFailure()->fails()) {
            return func_num_args() > 3 ? $default : false;
        }
        
        return $value;
    }

    public function ValidatorDefault($validator, $params) {
        $fields = [];
        $defaults = [];
        foreach ($params as $key => $value) {
            if (is_int($key)) {
                $fields[] = $value; 
            } else {
                $fields[] = $key; 
                $defaults[$key] = $value;
            }
        }
        // Safe only bilan faqat kerakli maydonlarni olish        
        // $validated = $validator->safe()->only($fields); // files.* => error
        $validated = array_intersect_key($validator->validated(), array_flip($fields));
        // Default qiymatlarni qo‘llash (agar mavjud bo‘lmasa yoki null bo‘lsa)
        foreach ($defaults as $key => $value) {
            $validated[$key] = $validated[$key] ?? (is_array($value) ? $value : (isset($validated[$value]) ? $validated[$value] : $value));
        }
        // Placeholderlarni almashtirish (agar comment kabi string maydon bo‘lsa)
        foreach ($validated as $key => $value) {
            if (is_string($value)) {
                $validated[$key] = preg_replace_callback('/\[(.*?)\]/', function ($matches) use ($validated) {
                    return $validated[$matches[1]] ?? $matches[0];
                }, $value);
            }
        }
        return $validated;
    }

    public function getDBValue($query, $field = null, $json_field = null) {
        $data = null;
        if ($query) {
            if ($field) {
                $data = $query->value($field);
                if ($json_field) {
                    $data = data_get(json_decode($data, true), $json_field);
                }
            } else {
                $data = $query->first();
            }
        }
        return $data;
    }

    public function interleaveGroupKey($items, $group_key) {
        $grouped = $items->groupBy($group_key);
        $maxCount = $grouped->max->count();
        $interleaved = collect();

        for ($i = 0; $i < $maxCount; $i++) {
            foreach ($grouped as $group) {
                if (isset($group[$i])) {
                    $interleaved->push($group[$i]);
                }
            }
        }

        return $interleaved;
    }

    public function random($min, $max, $precision = 2) {
        $random = $min + mt_rand() / mt_getrandmax() * ($max - $min);
        return round($random, $precision);
    }

    public function percentage($part, $total, $precision = 2,$around = 0) {
        if ($total == 0) {
            return 0;
        }
        $percentage = ($part / $total) * 100;
        if ($around > 0) {
            return round($percentage, $precision) - $this->random(0, $around, $precision);
        } else {
            return round($percentage, $precision);
        }
    }

    public function createTempFile($extension, $prefix = 'temp', $secure = true) {
        $tempPath = storage_path('framework/temp');

        if (!file_exists($tempPath)) {
            mkdir($tempPath, 0777, true);
        }

        if (!is_writable($tempPath)) {
            // throw new \RuntimeException("❌ Papkaga yozib bo‘lmadi: $tempPath");
            return null;
        }

        $baseName = $prefix . ($secure ? Str::random(20) : uniqid());

        // Agar array bo‘lsa — bir nechta nom qaytariladi
        if (is_array($extension)) {
            return array_map(function ($ext) use ($tempPath, $baseName) {
                return $tempPath . DIRECTORY_SEPARATOR . $baseName . '.' . ltrim($ext, '.');
            }, $extension);
        }

        // Aks holda — bitta nom qaytadi
        return $tempPath . DIRECTORY_SEPARATOR . $baseName . '.' . ltrim($extension, '.');
    }

    public function convertDocPdf($template, $replaces, &$pdf_file, $qrcode = null, $prefix = 'convert') {
        
        $files = $this->createTempFile(['pdf', 'docx', 'png'], $prefix);
        [$pdf_file, $doc_file, $qrcode_file] = $files;

        if (!file_exists($template)) {
            return false;
        }

        try {
            $word = new TemplateProcessor($template);

            // 🔄 Replacements
            foreach ($replaces as $key => $value) {
                $word->setValue($key, $this->isValidValue($value,'-'));
            }

            // 📷 QR code (optional)
            if ($qrcode) {
                $generator = Mamun::QRCode($qrcode);
                $image = $generator->render_image();

                if ($image) {
                    imagepng($image, $qrcode_file);
                    $word->setImageValue('qrcode', [
                        'path' => $qrcode_file,
                        'width' => 150,
                        'height' => 150,
                        'ratio' => false
                    ]);
                    imagedestroy($image);
                    @unlink($qrcode_file); // ✅ fail-safe unlink
                }
            }

            // 💾 Save as DOCX
            $word->saveAs($doc_file);
            unset($word);

            // 📤 Convert to PDF via LibreOffice
            if (file_exists($doc_file)) {
                $cmd = 'libreoffice --headless --convert-to pdf --outdir ' . escapeshellarg(dirname($pdf_file)) . ' ' . escapeshellarg($doc_file);
                exec($cmd, $output, $res);

                @unlink($doc_file); // clean up DOCX
            }

            return file_exists($pdf_file);
        } catch (\Throwable $e) {
            // logger()->error("convertDocPdf error: " . $e->getMessage());
            return false;
        }
    }

    public function fileResponse($filePath, $deleteAfter = false) {
        
        if (!file_exists($filePath)) {
            return [];
        }
    
        // 🧤 Fayl kontentini o‘qish xavfsiz
        $contentRaw = @file_get_contents($filePath);
        if ($contentRaw === false) {
            return [];
        }
    
        $content = base64_encode($contentRaw);
    
        // 🛡 MIME va extension fallback bilan
        $mime = File::mimeType($filePath) ?? 'application/octet-stream';
        $extension = File::extension($filePath) ?? 'bin';
        $size = filesize($filePath) ?? 0; // baytlarda
    
        // 🧠 Avtomatik nom
        $defaultName = match ($extension) {
            'pdf', 'docx', 'xlsx' => 'document',
            'png', 'jpg', 'jpeg' => 'image',
            default => 'file',
        };
    
        $fileName = $defaultName . '-' . basename($filePath);
    
        // 🧹 Faylni o‘chirish (agar kerak bo‘lsa)
        if ($deleteAfter) {
            @unlink($filePath);
        }
    
        return [
            'name' => $fileName,
            'mime' => $mime,
            'extension' => $extension,
            'size' => $size,
            'content' => $content,
        ];
    }

    public function addElement(&$arr, $value, $flatten = true) {

        if (is_null($value)) return;

        if (is_null($arr)) {
            $arr = $flatten && is_array($value) ? $value : [$value];
            return;
        }
    
        if (is_array($arr)) {
            if ($flatten && is_array($value)) {
                $arr = array_merge($arr, $value); // massivni ichiga yoyamiz
            } else {
                $arr[] = $value; // oddiy qo‘shamiz
            }
            return;
        }
    
        if (is_object($arr)) {
            if (!isset($arr->items) || !is_array($arr->items)) {
                $arr->items = [];
            }
    
            if ($flatten && is_array($value)) {
                $arr->items = array_merge($arr->items, $value);
            } else {
                $arr->items[] = $value;
            }
            return;
        }
    
        // primitive bo‘lsa → array shakliga o‘tkazamiz
        $arr = $flatten && is_array($value) ? array_merge([$arr], $value) : [$arr, $value];
    }

    public function sendMail($to, $subject, $body, $isHtml = true, $attachments = []) {
        try {
            $recipient = is_object($to) && method_exists($to, 'getEmailForNotification')
                ? $to->getEmailForNotification()
                : (is_array($to) ? $to : [$to]);
    
            Mail::send([], [], function (Message $message) use ($recipient, $subject, $body, $isHtml, $attachments) {
                $message->to($recipient)
                        ->subject($subject);
    
                if ($isHtml) {
                    $message->html($body); // ✅ Laravel 10+ uchun to‘g‘ri metod
                } else {
                    $message->text($body); // Oddiy matn uchun
                }
    
                foreach ($attachments as $name => $path) {
                    $message->attach($path, ['as' => $name]);
                }
            });
    
            return true;
        } catch (\Throwable $e) {
            \Log::error('Mail Error: ' . $e->getMessage());
            return false;
        }
    }

    public function maskValue($value) {

        if (!is_string($value) || trim($value) === '') {
            return '***';
        }
    
        $value = trim($value);
    
        // 1. Email
        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            [$name, $domain] = explode('@', $value);
            $len = strlen($name);
            if ($len <= 2) {
                $masked = str_repeat('*', $len);
            } else {
                $masked = substr($name, 0, 2) . str_repeat('*', $len - 3) . substr($name, -1);
            }
            return $masked . '@' . $domain;
        }
    
        // 2. Telefon (raqamli, 10–15 belgili)
        if (preg_match('/^[0-9]{10,15}$/', $value)) {
            $len = strlen($value);
            if ($len <= 4) return str_repeat('*', $len);
            return substr($value, 0, 5) . str_repeat('*', $len - 7) . substr($value, -2);
        }
    
        // 3. URL
        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            $host = $parts['host'] ?? '***';
            $path = $parts['path'] ?? '';
            $maskedPath = preg_replace('/[^\\/]+$/', '***', $path);
            return $host . $maskedPath;
        }
    
        // 4. File nomi (oxiri .pdf/.docx/.jpg ...)
        if (preg_match('/[^\\/]+\\.(\w+)$/', $value, $matches)) {
            $filename = basename($value);
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $nameOnly = basename($filename, '.' . $ext);
            $len = strlen($nameOnly);
            if ($len <= 2) {
                $masked = str_repeat('*', $len);
            } else {
                $masked = substr($nameOnly, 0, 2) . str_repeat('*', $len - 3) . substr($nameOnly, -1);
            }
            return $masked . '.' . $ext;
        }
    
        // 5. General string
        $len = strlen($value);
        if ($len <= 2) return str_repeat('*', $len);
        return substr($value, 0, 2) . str_repeat('*', $len - 3) . substr($value, -1);
    }

    public function clean($value, $type = null) {
        // 1. Umuman yaroqsiz bo‘lsa (null, bo‘sh, false, [] va h.k.)
        if (!isset($value) || (empty($value) && !is_numeric($value))) {
            return null;
        }

        // 2. Agar array bo‘lsa — har bir element uchun alohida ishlov
        if (is_array($value)) {
            $results = [];
            foreach ($value as $key => $item) {
                $detectedType = $type ?? $key;
                $results[$key] = $this->clean($item, $detectedType);
            }
            return $results;
        }

        if (is_null($type)) return null;

        // 3. Agar string bo‘lmasa → faqat 'text' uchun o‘zini qaytar
        if (!is_string($value)) {
            return $type === 'text' ? $value : null;
        }

        // 4. Normalize: whitespace'larni tozalash
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (!$value) return null;

        switch ($type) {
            case 'text':
                return $value;

            case 'full_name':
                $cleaned = Str::title(mb_strtolower($value));
                $words = explode(' ', $cleaned);
                $wordCount = count($words);
                if ($wordCount < 2 || $wordCount > 4) return null;
                if (count(array_unique($words)) === 1) return null;
                return $cleaned;

            case 'phone':
                $cleaned = preg_replace('/[^0-9]/', '', $value);
                return (strlen($cleaned) >= 9 && strlen($cleaned) <= 15) ? $cleaned : null;

            case 'user_name':
                $cleaned = strtolower(trim($value));
                if (!preg_match('/^[a-z][a-z0-9_.]{2,29}$/', $cleaned)) return null;
                return $cleaned;

            case 'file_name':
                $cleaned = preg_replace('/[^A-Za-z0-9._-]/', '_', $value);
                $cleaned = preg_replace('/_+/', '_', $cleaned);
                return strtolower($cleaned);

            case 'slug':
                $slug = strtolower($value);
                $slug = preg_replace('/[:]/', '', $slug); // :days → days
                $slug = preg_replace('/[^a-z0-9]+/i', '_', $slug); // belgilar → _
                $slug = trim($slug, '_');
                return $slug;

            default:
                return null;
        }
    }

    private function isValidValue($value, $default = null) {
        $isValid = !is_null($value) && !is_array($value) && !is_object($value) && trim((string)$value) !== '';
        return func_num_args() > 1 ? ($isValid ? $value : $default) : $isValid;
    }

    public function isValidJson($string, $default = null) {
        if (!is_string($string)) {
            return func_num_args() > 1 ? $default : false;
        }

        json_decode($string);
        $isValid = json_last_error() === JSON_ERROR_NONE;

        return func_num_args() > 1 ? ($isValid ? $string : $default) : $isValid;
    }
    
    public function undot(&$data, $separator = '.') {

        if ($data instanceof \Illuminate\Http\Request) {
            $array = $data->all();
            $this->undot($array, $separator);
            $data->merge($array);
            return;
        }

        if (is_string($data) && $this->isValidJson($data)) {
            $data = json_decode($data, true);
        }

        if (is_object($data) && !($data instanceof \Illuminate\Support\Collection)) {
            $data = (array) $data;
        }

        if ($data instanceof \Illuminate\Support\Collection) {
            $items = [];
            foreach ($data as $key => $value) {
                $this->undot($value, $separator);
                $items[$key] = is_array($value) ? (object) $value : $value;
            }
            $data = collect($items);
            return;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_array($value) || is_object($value) || $value instanceof \Illuminate\Support\Collection) {
                    $this->undot($value, $separator);
                    $data[$key] = $value;
                }

                if (is_string($key) && str_contains($key, $separator)) {
                    data_set($data, str_replace($separator, '.', $key), $value);
                    unset($data[$key]);
                }
            }
        }
    }

    public function castBoolean(&$data, $fields = []) {
        if (is_array($data)) {
            foreach ($data as $key => &$value) {
                if (in_array($key, $fields) || stripos($key, 'is_') === 0 || is_bool($value)) {
                    $value = (bool) $value;
                } elseif (is_array($value) || is_object($value)) {
                    $this->castBoolean($value, $fields);
                }
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => &$value) {
                if (in_array($key, $fields) || stripos($key, 'is_') === 0 || is_bool($value)) {
                    $value = (bool) $value;
                } elseif (is_array($value) || is_object($value)) {
                    $this->castBoolean($value, $fields);
                }
            }
        }
    }

}