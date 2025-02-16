<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class TelegramBotController extends Controller
{
    // Kanal ma'lumotlari (to'g'ridan-to'g'ri controller ichida)
    protected $channels = [
        [
            'id' => 'barnomahoyi_tojiki', // Kanal 1 username yoki ID
            'link' => 'https://t.me/barnomahoyi_tojiki' // Kanal 1 havolasi
        ],
        [
            'id' => 'mirkomil_kuhistoniy_blog', // Kanal 2 username yoki ID
            'link' => 'https://t.me/mirkomil_kuhistoniy_blog' // Kanal 2 havolasi
        ],
        // Qo'shimcha kanallar qo'shishingiz mumkin
    ];

    public function handle(Request $request)
    {
        Log::info('Telegramdan kelgan so‘rov: ', $request->all());

        $update = Telegram::commandsHandler(true);

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

    // Barcha kanallarga a'zoligini tekshirish
    $notSubscribedChannels = [];
    $buttons = [];

    foreach ($this->channels as $channel) {
        if (!$this->isUserMemberOfChannel($chatId, $userId, $channel['id'])) {
            $notSubscribedChannels[] = $channel['link'];
            $buttons[] = [['text' => "🔗 " . basename($channel['link']), 'url' => $channel['link']]];
        }
    }

    // Agar foydalanuvchi barcha kanallarga a'zo bo'lsa
    if (empty($notSubscribedChannels)) {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "Салом, $firstName! Ман як боти ёрдамчи ҳастам. Саволҳои худро ба ман нависед."
        ]);
        return;
    }

    // Agar foydalanuvchi ba'zi kanallarga a'zo bo'lmagan bo'lsa
    $messageText = "Салом, $firstName! Барои истифодаи бот, лутфан ба каналҳои зерин обуна шавед:";

    // Inline keyboard tugmalarni qo'shish
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
        $data = $callbackQuery['data'];

        // Agar foydalanuvchi "✅ Ман обуна шудам" tugmasini bossa
        if ($data == 'check_subscription') {
            // Foydalanuvchi barcha kanallarga a'zo ekanligini tekshiramiz
            $notSubscribedChannels = [];

            foreach ($this->channels as $channel) {
                if (!$this->isUserMemberOfChannel($chatId, $userId, $channel['id'])) {
                    $notSubscribedChannels[] = $channel['link'];
                }
            }

            if (empty($notSubscribedChannels)) {
                // Agar foydalanuvchi barcha kanallarga a'zo bo'lsa, unga xabar yuboramiz va tugmalarni olib tashlaymiz
                $this->saveUserSubscription($userId); // A'zolikni saqlash
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Ташаккур! Ҳоло шумо метавонед аз бот истифода баред. 🎉",
                    'reply_markup' => json_encode(['remove_keyboard' => true]) // Tugmalarni olib tashlash
                ]);
            } else {
                // Agar hali ham obuna bo‘lmagan kanallar bo‘lsa, yana eslatma yuboramiz
                $messageText = "Шумо ҳанӯз ба каналҳои зерин обуна нашудаед:\n";
                foreach ($notSubscribedChannels as $link) {
                    $messageText .= "- " . $link . "\n";
                }

                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => $messageText
                ]);
            }
        }
    }
protected function isUserMemberOfChannel($chatId, $userId, $channelId)
{
    $apiKey = env('TELEGRAM_BOT_TOKEN');

    $response = Http::get("https://api.telegram.org/bot{$apiKey}/getChatMember", [
        'chat_id' => $channelId,
        'user_id' => $userId
    ]);

    $data = $response->json();

    Log::info("🔍 Kanal a'zolik tekshiruvi: ", $data);

    return isset($data['result']['status']) && in_array($data['result']['status'], ['member', 'administrator', 'creator']);
}


    protected function saveUserSubscription($userId)
    {
        // Foydalanuvchi a'zoligini cache ga saqlash (30 kun muddat)
        Cache::put("user_subscribed_{$userId}", true, now()->addDays(30));
    }

    protected function isUserSubscribed($userId)
    {
        // Foydalanuvchi a'zoligini cache dan tekshirish
        return Cache::has("user_subscribed_{$userId}");
    }

    protected function handleGeminiRequest($chatId, $text)
    {
        // Google Gemini API bilan so‘rov yuborish
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
                        ['text' => $text] // Foydalanuvchi xabari tojik tilida bo'lishi kerak
                    ]
                ]
            ]
        ]);

        Log::info('Google Gemini API Response: ', $response->json());

        $geminiResponse = $response->json();

        // Xatolik bo‘lsa, foydalanuvchiga bildirish
        if (isset($geminiResponse['error'])) {
            Log::error('Google Gemini API xatosi: ' . json_encode($geminiResponse['error']));
            Telegram::sendMessage([
                'chat_id' => $chatId,
                'text' => "Хизмати AI ҳозир ифлос аст. Баъдтар кӯшиш кунед."
            ]);
            return;
        }

        // Javobni olish
        $replyText = $geminiResponse['candidates'][0]['content']['parts'][0]['text'] ?? "Бубахшед, ман ҷавоб дода натавонистам.";

        Log::info("Foydalanuvchiga javob yuborilmoqda: $replyText");

        // Telegramga javob qaytarish
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $replyText
        ]);
    }
}
