<?php

namespace App\Library;

use App\Library\Mamun;

class Bot 
{
    private $token;
    private $url;
    private $http;
    private $utility;

    public $data;
    public $chat_id;
    public $text;
    public $callback_data;

    public function __construct($token, $data) {
        $this->token = $token;
        $this->url = "https://api.telegram.org/bot{$token}/";
        $this->http = Mamun::Http();
        $this->utility = Mamun::Utility();
        $this->getData($data);
    }

    private function getData($data) {
        
        $this->data = null;
        $this->chat_id = null;
        $this->text = null;
        $this->callback_data = null;

        if ($data) {
            $this->data = (object) [];
            $this->data->contact = null;

            if ($data->has('callback_query')) {
                $this->data->chat_id = $data->input('callback_query.message.chat.id');
                $this->data->message_from = $data->input('callback_query.from.username') ?? $data->input('callback_query.from.first_name');
                $this->data->message_id = $data->input('callback_query.message.message_id');
                $this->data->text = $data->input('callback_query.message.text');
                $this->data->callback_id = $data->input('callback_query.id');
                $this->data->callback_data = $data->input('callback_query.data');
            } else {
                $this->data->chat_id = $data->input('message.chat.id');
                $this->data->message_from = $data->input('message.from.username') ?? $data->input('message.from.first_name');
                $this->data->message_id = $data->input('message.message_id');
                $this->data->text = $data->input('message.text');
                $this->data->forward_from = $data->exists('message.forward_from');
                $this->data->document_id = $data->input('message.document.file_id');                
                
                if ($data->has('message.contact')) {
                    $this->data->contact = (object) [];
                    $this->data->contact->phone_number = $this->utility->clean($data->input('message.contact.phone_number'), 'phone');
                    $this->data->contact->full_name = $this->utility->clean($data->input('message.contact.first_name') ." ". $data->input('message.contact.last_name'), 'full_name');
                    $this->data->contact->user_id = $data->input('message.contact.user_id');
                }
            }

            if ($this->data->chat_id and $this->data->chat_id>0) {
                $this->chat_id = $this->data->chat_id;
                // $this->text = $this->utility->clean($this->data->text, 'text');
                $this->callback_data = $this->data->callback_data ?? null;
            } else {
                $this->data = null;
            }
        }
    }

    private function getRequest($command, $param = []) {
        return $this->http->getRequest($this->url . $command, $param);        
    }

    private function buildButtons($buttons, $column) {
        if ($column === 0) {
            $avgLen = array_sum(array_map(fn($i) => mb_strlen($i['text'] ?? ''), $buttons)) / count($buttons);

            if ($avgLen > 25) $column = 1;
            elseif ($avgLen > 15) $column = 2;
            elseif ($avgLen > 8) $column = 3;
            else $column = 4;
        }

        if ($buttons && isset($buttons[0]) && array_is_list($buttons)) {
            if (isset($buttons[0]['text'])) {
                return array_chunk($buttons, $column);
            }
        }
        return $buttons;
    }

    public function getChatMember($channel_ids, $chat_id = null) {
        if (is_array($chat_id)) {
            $nonMembers = [];
            foreach ($chat_id as $item) {
                if (!$this->getChatMember($channel_ids, $item)) {
                    $nonMembers[] = $item;
                }
            }        
            return $nonMembers;
        } else {
            foreach ($channel_ids as $channel_id) {
                $param = ['chat_id' => $channel_id, 'user_id' => $chat_id ?? $this->chat_id];
                $res = json_decode($this->getRequest('getChatMember', $param));
                if (!$res || !$res->ok || !isset($res->result->status) || !in_array($res->result->status, ['member', 'administrator', 'creator'])) {
                    return false;
                }
            }
            return true;
        }
    }

    public function sendChatAction($action = 'typing') {
        $param = ['chat_id' => $this->chat_id, 'action' => $action];
        return $this->getRequest('sendChatAction', $param);
    }

    public function sendMessage($text, $buttons = null, $inline = false, $reply = false, $reply_message_id = null, $chat_id = null, $parse_mode = 'HTML', $column = 0) {
        $param = [
            'chat_id' => $chat_id ?? $this->chat_id, 
            'text' => $text, 
            'parse_mode' => $parse_mode, 
            'disable_web_page_preview' => true
        ];

        if ($reply) {
            $param['reply_to_message_id'] = $this->data->message_id;
            if ($reply_message_id) {
                $param['reply_to_message_id'] = $reply_message_id;
            }
        }

        if ($buttons) {
            $buttons = $this->buildButtons($buttons, $column);
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]);
        } elseif ($buttons === []) {
            $param['reply_markup'] = json_encode([
                'remove_keyboard' => true
            ]);
        }
        return $this->getRequest('sendMessage', $param);
    }

    private function sendPhoto($photo, $caption = null, $buttons = null, $inline = false) {
        $param = ['chat_id' => $this->chat_id, 'photo' => $photo, 'parse_mode' => 'HTML'];
        if ($caption) {
            $param['caption'] = $caption;
        }
        if ($buttons) {
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false                    
            ]);
        }
        return $this->getRequest('sendPhoto', $param);
    }

    private function sendDocument($document, $caption = null, $buttons = null, $inline = false, $reply = false, $reply_message_id = null, $chat_id = null) {
        $param = ['chat_id' => $this->chat_id, 'document' => $document, 'parse_mode' => 'HTML'];
        if ($caption) {
            $param['caption'] = $caption;
        }
        if ($chat_id) {
            $param['chat_id'] = $chat_id;
        }
        if ($reply) {
            $param['reply_to_message_id'] = $this->data->message_id;
            if ($reply_message_id) {
                $param['reply_to_message_id'] = $reply_message_id;
            }
        }
        if ($buttons) {
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false                    
            ]);
        }
        return $this->getRequest('sendDocument', $param);
    }

    private function sendVideo($video, $caption = null, $buttons = null, $inline = false) {
        $param = ['chat_id' => $this->chat_id, 'video' => $video, 'parse_mode' => 'HTML'];
        if ($caption) {
            $param['caption'] = $caption;
        }
        if ($buttons) {
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false                    
            ]);
        }
        return $this->getRequest('sendVideo', $param);
    }

    public function editMessageText($text, $buttons = null, $inline = false, $chat_id = null, $message_id = null, $parse_mode = 'HTML', $column = 0) {
        $param = [
            'chat_id' => $chat_id ?? $this->chat_id,
            'message_id' => $message_id ?? $this->data->message_id,
            'text' => $text,
            'parse_mode' => $parse_mode,
            'disable_web_page_preview' => true,
        ];

        if ($buttons) {
            $buttons = $this->buildButtons($buttons, $column);
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false
            ]);
        } elseif ($buttons === []) {
            $param['reply_markup'] = json_encode([
                'remove_keyboard' => true
            ]);
        }

        return $this->getRequest('editMessageText', $param);
    }

    private function editMessageReplyMarkup($buttons = null, $inline = false) {
        $param = ['chat_id' => $this->chat_id, 'message_id' => $this->message_id];
        if ($buttons) {
            $param['reply_markup'] = json_encode([
                ($inline ? "inline_keyboard" : "keyboard") => $buttons,
                'resize_keyboard' => true,
                'one_time_keyboard' => false                    
            ]);
        }

        return $this->getRequest('editMessageReplyMarkup', $param);
    }

    public function deleteMessage($chat_id = null, $message_id = null) {
        $param = [
            'chat_id' => $chat_id ?? $this->chat_id, 
            'message_id' => $message_id ?? $this->data->message_id
        ];
        return $this->getRequest('deleteMessage', $param);
    }

    public function answerCallback($text = null) {
        if (!isset($this->data->callback_id)) {
            return false;
        }

        $param = ['callback_query_id' => $this->data->callback_id];

        if (!empty($text)) {
            $param['text'] = $text;
            $param['show_alert'] = true;
        }

        return $this->getRequest('answerCallbackQuery', $param);
    }

    public function isOverloaded($cache, $limit = 0, $prefix = null) {

        // 🔁 Limit avtomatik aniqlansin agar 0 berilgan bo‘lsa
        if ($limit == 0) {
            $hour = now()->hour;    
            $limit = match (true) {
                $hour >= 8 && $hour <= 12 => 200,   // ertalab
                $hour > 12 && $hour <= 17 => 250,   // tushdan keyin
                $hour > 17 && $hour <= 21 => 300,   // kechga yaqin
                default => 350,                     // kechasi eng yumshoq
            };
        }

        $pending = $cache->get('pending_update_count', function () {
            $res = json_decode($this->getRequest('getWebhookInfo'));
            return $res->result->pending_update_count ?? 0;
        }, 20, null, $prefix);

        return $pending > $limit;
    }

    public function getChat($chat_id = null) {
        $param = ['chat_id' => $chat_id ?? $this->chat_id];
        $res = json_decode($this->getRequest('getChat', $param));

        if ($res && isset($res->ok) && $res->ok && isset($res->result)) {
            return $res->result;
        }
        return null;
    }

    public function getUserLink($chat_id, $default, $cache = null, $ttl = null) {
        if ($cache && $chat_id) {
            $username = $cache->get("bot:admin:$chat_id", function () use ($chat_id) {
                return $this->getChat($chat_id)->username ?? null;
            }, $ttl ?? 3600);
        } else {
            $username = $this->getChat($chat_id)->username ?? null;
        }
        return "https://t.me/" . ($username ?? $default ?? '');
    }

}