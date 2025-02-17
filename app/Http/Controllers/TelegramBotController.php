<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramBotController extends Controller
{
    // Kanal ma'lumotlari
    protected $channels = [
        [
            'id' => '@barnomahoyi_tojiki', // Kanal ID (username o‘rniga ID ishlatish yaxshiroq)
            'link' => 'https://t.me/barnomahoyi_tojiki'
        ],
        [
            'id' => '@mirkomil_kuhistoniy_blog', // Username formatida
            'link' => 'https://t.me/mirkomil_kuhistoniy_blog'
        ]
    ];

    public function handle(Request $request)
    {
        Log::info('Telegramdan kelgan so‘rov: ', $request->all());

        // Bu qatorni olib tashlang yoki false qilib qo‘ying
        // $update = Telegram::commandsHandler(false);

        $update = Telegram::getWebhookUpdates();

        // Agar callback_query bo'lsa
        if (isset($update['callback_query'])) {
            $this->handleCallbackQuery($update['callback_query']);
            return;
        }

        // Foydalanuvchi xabarini olish
        $message = $update->getMessage();
        if (!$message || !$message->getText()) {
            Log::warning('Xabar topilmadi yoki bo‘sh.');
            return;
        }

        $text = $message->getText();
        $chatId = $message->getChat()->getId();
        $userId = $message->getFrom()->getId();

        Log::info("Foydalanuvchi [$chatId] xabar yubordi: $text");

        // Agar foydalanuvchi `/start` yuborsa
        if ($text == '/start') {
            $this->handleStartCommand($chatId, $userId);
            return;
        }

        // Google Gemini API bilan so‘rov yuborish
        $this->handleGeminiRequest($chatId, $text);
    }


    protected function handleStartCommand($chatId, $userId)
    {
        $message = Telegram::getChat(['chat_id' => $chatId]);
        $firstName = $message['first_name'] ?? 'Дӯст';

        Telegram::sendChatAction([
            'chat_id' => $chatId,
            'action' => 'typing'
        ]);

        // Oldin cache tekshiramiz
        if ($this->isUserSubscribed($userId)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Салом, $firstName! Ман як боти ёрдамчи ҳастам. Саволҳои худро ба ман нависед."
            ]);
            return;
        }


        // Kanalga aʼzo ekanligini tekshiramiz
        $notSubscribedChannels = [];
        $buttons = [];

        foreach ($this->channels as $channel) {
            if (!$this->isUserMemberOfChannel($userId, $channel['id'])) {
                $notSubscribedChannels[] = $channel['link'];
                $buttons[] = [['text' => "🔗 " . basename($channel['link']), 'url' => $channel['link']]];
            }
        }

        if (empty($notSubscribedChannels)) {
            $this->saveUserSubscription($userId);

            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Салом, $firstName! Ман як боти ёрдамчи ҳастам. Саволҳои худро ба ман нависед."
            ]);
            return;
        }


        // Kanalga a'zo bo'lishni talab qilish
        $messageText = "Салом, $firstName! Барои истифодаи бот, лутфан каналларга аъзо бўлинг:";

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $messageText,
            'reply_markup' => json_encode([
                'inline_keyboard' => array_merge($buttons, [
                    [['text' => "✅ Ман обуна шудам", 'callback_data' => 'check_subscription']]
                ])
            ])
        ]);
    }

    protected function handleCallbackQuery($callbackQuery)
    {
        $chatId = $callbackQuery['message']['chat']['id'];
        $userId = $callbackQuery['from']['id'];

        $notSubscribedChannels = [];
        foreach ($this->channels as $channel) {
            if (!$this->isUserMemberOfChannel($userId, $channel['id'])) {
                $notSubscribedChannels[] = $channel['link'];
            }
        }

        if (!empty($notSubscribedChannels)) {
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Сиз ҳали ҳамма каналларга аъзо эмассиз. Илтимос, қуйидаги каналларга аъзо бўлинг:\n\n" . implode("\n", $notSubscribedChannels)
            ]);
            return;
        }

        $this->saveUserSubscription($userId);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "Ташаккур! Ҳоло шумо метавонед аз бот истифода баред. 🎉",
            'reply_markup' => json_encode(['remove_keyboard' => true])
        ]);
    }

    protected function isUserMemberOfChannel($userId, $channelId)
    {
        $apiKey = env('TELEGRAM_BOT_TOKEN');

        $response = Http::get("https://api.telegram.org/bot{$apiKey}/getChatMember", [
            'chat_id' => $channelId,
            'user_id' => $userId
        ]);

        $data = $response->json();
        Log::info("🔍 Telegram API javobi: " . json_encode($data));

        if (!isset($data['ok']) || !$data['ok']) {
            Log::error("❌ API xato berdi: " . json_encode($data));
            return false;
        }

        return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
    }

    protected function saveUserSubscription($userId)
    {
        Cache::put("user_subscribed_{$userId}", true, now()->addDays(30));
    }

    protected function isUserSubscribed($userId)
    {
        return Cache::has("user_subscribed_{$userId}");
    }

    protected function handleGeminiRequest($chatId, $text)
    {
        $apiKey = env('GEMINI_API_KEY');
        if (!$apiKey) {
            Log::error('GEMINI_API_KEY .env faylda mavjud emas.');
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Хизмати AI ҳозир ифлос аст. Баъдтар кӯшиш кунед."
            ]);
            return;
        }

        $response = Http::post('https://generativelanguage.googleapis.com/v1beta/models/gemini-pro:generateContent?key=' . $apiKey, [
            'contents' => [
                [
                    'parts' => [
                        ['text' => $text]
                    ]
                ]
            ]
        ]);

        Log::info('Google Gemini API Response: ', $response->json());

        $geminiResponse = $response->json();
        if (isset($geminiResponse['error'])) {
            Log::error('Google Gemini API xatosi: ' . json_encode($geminiResponse['error']));
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Хизмати AI ҳозир ифлос аст. Баъдтар кӯшиш кунед."
            ]);
            return;
        }

        $replyText = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? "Бубахшед, ман ҷавоб дода натавонистам.";

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $replyText
        ]);
    }
}
