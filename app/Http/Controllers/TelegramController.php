<?php

namespace App\Http\Controllers;

use App\Models\Options;
use Telegram\Bot\Api;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Transaction;
use App\Models\DynamicContent;
use App\Models\DynamicButton;
use App\Models\Messages;
use App\Models\TgSession as Sessions;
use Telegram\Bot\Keyboard\Keyboard;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class TelegramController extends Controller
{
    protected Api $telegram;
    protected ?array $adminChatId;

    public function __construct(Api $telegram)
    {
        $this->adminChatId = explode(',', Options::where('key', 'admins')->value('data'));
        $this->telegram = $telegram;
        Log::debug('TelegramController initialized with adminChatId: ' . json_encode($this->adminChatId));
    }

    public function webhook(Request $request)
    {
        try {
            $update = $this->telegram->getWebhookUpdates();
            if ($update == null || empty($update)) {
                return response('test', 200);
            }
            Log::debug('Received webhook update: ' . json_encode($update->getRawData()));
            file_put_contents('telegram_webhook_log.txt', json_encode($update->getRawData()) . "\n", FILE_APPEND);

            if ($update->isType('message')) {
                Log::debug('Processing message update');
                $this->processUpdate($update->getMessage(), 'message');
            } elseif ($update->isType('callback_query')) {
                Log::debug('Processing callback query update');
                $this->processUpdate($update->getCallbackQuery(), 'callback_query');
            }
            return response('ok', Response::HTTP_OK);
        } catch (\Telegram\Bot\Exceptions\TelegramSDKException $e) {
            Log::error('Telegram SDK Error in webhook: ' . $e->getMessage());
            return response('Service Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Illuminate\Database\QueryException $e) {
            Log::error('Database Query Error in webhook: ' . $e->getMessage());
            return response('Service Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        } catch (\Exception $e) {
            Log::error('General Error in webhook: ' . $e->getMessage());
            return response('Service Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    protected function processUpdate($update, $type)
    {
        try {
            $chat = $update->getChat();
            $from = $update->getFrom();

            if (is_null($chat) || is_null($from)) {
                throw new \Exception('Chat or From object is null');
            }

            $chatId = $chat->getId();
            $userId = $from->getId();

            if ($type === 'message') {
                $text = $update->getText();
                $this->handleCommand($chatId, $text, $userId);
            } elseif ($type === 'callback_query') {
                $data = $update->getData();
                $this->handleCommand($chatId, $data, $userId);
            }
        } catch (\Exception $e) {
            Log::error('Error processing update: ' . $e->getMessage());
        }
    }

    protected function handleCommand($chatId, $input, $userId)
    {
        $command = $this->getCommand($chatId, $input);
        $content = $this->getContent($chatId, $input);

        $commandHandlers = [
            '/start' => fn() => $this->handleStartCommand($chatId, $userId),
            '/referral' => fn() => $this->handleReferral($chatId, $userId),
            'referral' => fn() => $this->handleReferral($chatId, $userId),
            '/support' => fn() => $this->handleSupport($chatId, $userId),
            'support' => fn() => $this->handleSupport($chatId, $userId),
            'deposit_get_transaction' => fn() => $this->handleDepositGet($chatId, $userId, $content),
            'broadcasting' => fn() => $this->handleBroadCastingGet($chatId, $userId, $content),
            'get_contact_for_login' => fn() => $this->handleGetContactForLogin($chatId, $userId, $content),
            'deposit' => fn() => $this->handleDeposit($chatId, $userId),
            'withdraw' => fn() => $this->handleWithdraw($chatId, $userId),
            'back_to_main' => fn() => $this->handleStartCommand($chatId, $userId),
            'broadcast' => fn() => $this->handleBroadcast($chatId),
            'send_broadcasting' => fn() => $this->handleBroadCastingSend($chatId),
        ];

        foreach ($commandHandlers as $key => $handler) {
            if (str_contains($command, $key) || $command === $key) {
                $handler();
                return;
            }
        }

        if (strpos($command, 'confirm_deposit_') === 0) {
            $this->confirmDeposit($chatId, str_replace('confirm_deposit_', '', $command), $userId);
        } elseif (strpos($command, 'reject_deposit_') === 0) {
            $this->rejectDeposit($chatId, str_replace('reject_deposit_', '', $command), $userId);
        } elseif (strpos($command, 'confirm_withdraw_') === 0) {
            $this->confirmWithdraw($chatId, str_replace('confirm_withdraw_', '', $command), $userId);
        } elseif (strpos($command, 'reject_withdraw_') === 0) {
            $this->rejectWithdraw($chatId, str_replace('reject_withdraw_', '', $command), $userId);
        } else {
            $this->handleDynamicContent($chatId, $command);
        }
    }

    protected function getCommand($chatId, $input)
    {
        return !empty($this->step(chatId: $chatId)) || $this->step(chatId: $chatId) != 'default' ? $this->step(chatId: $chatId) : $input;
    }

    protected function getContent($chatId, $input)
    {
        return !empty($this->step(chatId: $chatId)) || $this->step(chatId: $chatId) != 'default' ? $input : '';
    }

    protected function sendUnknownCommandMessage($chatId, $command)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "متوجه پیام شما نشدم. لطفا از منو استفاده کنید.\ncommand: {$command}\n{$this->step('', $chatId)}",
        ]);
    }

    protected function handleDeposit($chatId, $userId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا مبلغ واریزی خود را به همراه شناسه پرداخت ارسال کنید.',
        ]);
        $this->step('deposit_get_transaction', $chatId);
    }

    protected function handleStartCommand($chatId, $userId)
    {
        if (!$this->createUserIfNotExists($chatId, $userId)) {
            return;
        }
        $keyboard = $this->getMainMenuKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'به ربات خوش آمدید! لطفا از منوی زیر انتخاب کنید:',
            'reply_markup' => $keyboard,
        ]);
    }

    protected function handleDepositGet($chatId, $userId, $content)
    {
        if (preg_match('/(\d+)\n+(.+)/', $content, $matches)) {
            $this->processDeposit($chatId, $matches[1], trim($matches[2]));
            $this->step('default', $chatId);
            $this->handleStartCommand($chatId, $userId);
        } else {
            $this->sendInvalidFormatMessage($chatId);
        }
    }

    protected function processDeposit($chatId, $amount, $paymentId)
    {
        $this->telegram->sendMessage([
            'chat_id' => Options::get('transaction_channel'),
            'text' => vprintf(Options::get('transaction_deposit_status_message[admin]'), [$amount, $paymentId]),
        ]);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => Options::get('transaction_deposit_pending_message[user]'),
        ]);
    }

    protected function sendInvalidFormatMessage($chatId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'فرمت ورودی نادرست است. لطفا دوباره تلاش کنید. مثال: 1000\n1234567890. برای کمک بیشتر، با پشتیبانی تماس بگیرید.',
        ]);
    }

    protected function handleBroadCastingGet($chatId, $userId, $content)
    {
        if (is_string($content)) {
            $this->createBroadcastMessage('text', '', $content);
        } elseif (is_array($content) && isset($content['type'])) {
            $this->createBroadcastMessage($content['type'], $content['data'], $content['caption'] ?? '');
        } else {
            $this->sendUnsupportedContentTypeMessage($chatId);
            return;
        }
        $this->step('default', $chatId);
        $this->sendBroadcastReceivedMessage($chatId);
    }

    protected function createBroadcastMessage($type, $data, $content)
    {
        Messages::create([
            'section' => 'broadcasting',
            'status' => 'pending',
            'type' => $type,
            'data' => $data,
            'content' => $content,
        ]);
    }

    protected function sendUnsupportedContentTypeMessage($chatId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'نوع محتوای ارسال شده پشتیبانی نمی‌شود.',
        ]);
    }

    protected function sendBroadcastReceivedMessage($chatId)
    {
        $keyboard = [
            [
                ['text' => 'ادامه', 'callback_data' => 'broadcasting'],
                ['text' => 'ارسال', 'callback_data' => 'send_broadcasting']
            ]
        ];
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'پیام شما دریافت شد.',
            'reply_markup' => new Keyboard(['inline_keyboard' => $keyboard])
        ]);
    }

    protected function handleBroadCastingSend($chatId)
    {
        $messages = Messages::where('section', 'broadcasting')->where('status', 'pending')->get();
        $users = User::all();

        if ($messages->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'هیچ محتوایی برای ارسال یافت نشد.',
            ]);
            return;
        }

        foreach ($users as $user) {
            $userTgId = $user->get('id');
            foreach ($messages as $message) {
                $this->sendBroadcastMessage($userTgId, $message);
            }
        }
    }

    protected function sendBroadcastMessage($userTgId, $message)
    {
        switch ($message->type) {
            case 'image':
                $this->telegram->sendPhoto([
                    'chat_id' => $userTgId,
                    'photo' => $message->data,
                    'caption' => $message->content,
                ]);
                break;
            case 'video':
                $this->telegram->sendVideo([
                    'chat_id' => $userTgId,
                    'video' => $message->data,
                    'caption' => $message->content,
                ]);
                break;
            case 'audio':
                $this->telegram->sendAudio([
                    'chat_id' => $userTgId,
                    'audio' => $message->data,
                    'caption' => $message->content,
                ]);
                break;
            default:
                $this->telegram->sendMessage([
                    'chat_id' => $userTgId,
                    'text' => $message->content,
                ]);
        }
    }

    protected function handleWithdraw($chatId, $userId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا مبلغ برداشت خود را به همراه شماره حساب ارسال کنید.',
        ]);
        $this->step('withdraw', $chatId);
    }

    protected function handleReferral($chatId, $userId)
    {
        $user = User::find($userId);
        if ($user) {
            $referralLink = url('/register?ref=' . $user->id);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'لینک زیرمجموعه گیری شما: ' . $referralLink,
            ]);
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'کاربر یافت نشد.',
            ]);
        }
    }

    protected function handleSupport($chatId, $userId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا پیام خود را ارسال کنید تا پشتیبانی در اسرع وقت پاسخ دهد.',
        ]);
        $this->step('support', $chatId);
    }

    protected function handleDynamicContent($chatId, $key)
    {
        $dynamicContents = DynamicContent::where('key', $key)->get();
        if ($dynamicContents->isEmpty()) {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'محتوایی برای این دکمه وجود ندارد.',
            ]);
            return;
        }

        foreach ($dynamicContents as $dynamicContent) {
            $this->sendDynamicContent($chatId, $dynamicContent);
        }
    }

    protected function sendDynamicContent($chatId, $dynamicContent)
    {
        switch ($dynamicContent->type) {
            case 'image':
                $this->telegram->sendPhoto([
                    'chat_id' => $chatId,
                    'photo' => $dynamicContent->content,
                ]);
                break;
            case 'video':
                $this->telegram->sendVideo([
                    'chat_id' => $chatId,
                    'video' => $dynamicContent->content,
                ]);
                break;
            default:
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => $dynamicContent->content,
                ]);
        }
    }

    protected function handleBroadcast($chatId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا پیام مورد نظر برای ارسال همگانی را ارسال کنید:',
        ]);
        $this->step('broadcasting', $chatId);
    }

    protected function isAdmin($chatId)
    {
        return in_array($chatId, $this->adminChatId);
    }

    protected function confirmDeposit($chatId, $depositId, $userId)
    {
        $transaction = Transaction::find($depositId);
        if ($transaction && $transaction->type == 'deposit' && $transaction->status == 'pending') {
            $transaction->status = 'approved';
            $transaction->save();

            $user = User::find($transaction->user_id);
            if ($user) {
                $user->balance += $transaction->amount;
                $user->save();

                $this->telegram->sendMessage([
                    'chat_id' => $transaction->user_id,
                    'text' => "واریز شما با شناسه {$depositId} تایید شد.",
                ]);

                $this->telegram->sendMessage([
                    'chat_id' => $this->adminChatId,
                    'text' => "واریز با شناسه {$depositId} تایید شد.",
                ]);
            }
        }
    }

    protected function rejectDeposit($chatId, $depositId, $userId)
    {
        $transaction = Transaction::find($depositId);
        if ($transaction && $transaction->type == 'deposit' && $transaction->status == 'pending') {
            $transaction->status = 'rejected';
            $transaction->save();

            $this->telegram->sendMessage([
                'chat_id' => $transaction->user_id,
                'text' => "واریز شما با شناسه {$depositId} رد شد.",
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $this->adminChatId,
                'text' => "واریز با شناسه {$depositId} رد شد.",
            ]);
        }
    }

    protected function confirmWithdraw($chatId, $withdrawId, $userId)
    {
        $transaction = Transaction::find($withdrawId);
        if ($transaction && $transaction->type == 'withdrawal' && $transaction->status == 'pending') {
            $user = User::find($transaction->user_id);
            if ($user && $user->balance >= $transaction->amount) {
                $user->balance -= $transaction->amount;
                $user->save();

                $transaction->status = 'approved';
                $transaction->save();

                $this->telegram->sendMessage([
                    'chat_id' => $userId,
                    'text' => "برداشت شما با شناسه {$withdrawId} تایید شد.",
                ]);

                $this->telegram->sendMessage([
                    'chat_id' => $this->adminChatId,
                    'text' => "برداشت با شناسه {$withdrawId} تایید شد.",
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'موجودی کافی برای انجام این برداشت وجود ندارد.',
                ]);
            }
        }
    }

    protected function rejectWithdraw($chatId, $withdrawId, $userId)
    {
        $transaction = Transaction::find($withdrawId);
        if ($transaction && $transaction->type == 'withdrawal' && $transaction->status == 'pending') {
            $transaction->status = 'rejected';
            $transaction->save();

            $this->telegram->sendMessage([
                'chat_id' => $transaction->user_id,
                'text' => "برداشت شما با شناسه {$withdrawId} رد شد.",
            ]);

            $this->telegram->sendMessage([
                'chat_id' => $this->adminChatId,
                'text' => "برداشت با شناسه {$withdrawId} رد شد.",
            ]);
        }
    }

    protected function getMainMenuKeyboard($chatId)
    {
        $keyboard = [
            [
                ['text' => 'واریز 💰', 'callback_data' => 'deposit'],
                ['text' => 'برداشت 💸', 'callback_data' => 'withdraw'],
            ],
            [
                ['text' => 'زیرمجموعه گیری 👥', 'callback_data' => 'referral'],
                ['text' => 'پشتیبانی 🛠️', 'callback_data' => 'support'],
            ]
        ];
        $dynamicButtons = DynamicButton::where('status', 'active')->get();
        foreach ($dynamicButtons as $button) {
            $keyboard[] = [
                ['text' => $button->value, 'callback_data' => $button->slug],
            ];
        }

        if ($this->isAdmin($chatId)) {
            $keyboard[] = [
                ['text' => 'تراکنش ها 📊', 'callback_data' => 'show_transactions'],
                ['text' => 'کاربران 👤', 'callback_data' => 'show_users'],
            ];
            $keyboard[] = [
                ['text' => 'تنظیمات ⚙️', 'web_app' => ['url' => env("TELEGRAM_MINI_APP_ADMIN_URL")]],
            ];
            $keyboard[] = [
                ['text' => 'پخش پیام همگانی 📢', 'callback_data' => 'broadcast'],
            ];
        }
        return new Keyboard(['inline_keyboard' => $keyboard]);
    }

    protected function createUserIfNotExists($chatId, $userId)
    {
        $session = Sessions::where(['key' => 'user_session', 'value' => $chatId])->first();
        $user = $session ? $session->user_id : null;

        if (!$user) {
            $this->requestContact($chatId);
            $this->step('get_contact_for_login', $chatId);
            return false;
        }
        return true;
    }

    protected function requestContact($chatId)
    {
        $keyboard = [
            [
                ['text' => 'ارسال شماره موبایل 📱', 'request_contact' => true]
            ]
        ];
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا برای استفاده از ربات، شماره موبایل خود را با استفاده از دکمه زیر ارسال کنید.',
            'reply_markup' => new Keyboard(['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => true])
        ]);
    }

    protected function handleGetContactForLogin($chatId, $userId, $content)
    {
        if (isset($content['user_id']) && $content['user_id'] == $userId) {
            $this->registerUser($chatId, $userId, $content);
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'لطفا شماره موبایل خود را با استفاده از دکمه ارسال کنید.',
            ]);
        }
    }

    protected function registerUser($chatId, $userId, $content)
    {
        $phoneNumber = $content['phone_number'];

        try {
            $user = User::updateOrCreate(
                ['mobile' => $phoneNumber],
                [
                    'name' => $content['first_name'] ?? '',
                    'mobile_verified_at' => now(),
                    'password' => bcrypt($userId)
                ]
            );

            $sessionData = ['user_id' => $user->id];
            Sessions::updateOrCreate(
                ['key' => 'user_session', 'user_id' => $user->id],
                array_merge($sessionData, ['value' => $chatId])
            );
            Sessions::updateOrCreate(
                ['key' => 'user_tg_id', 'user_id' => $user->id],
                array_merge($sessionData, ['value' => $userId])
            );

            if ($user->wasRecentlyCreated) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'شماره موبایل شما با موفقیت ثبت شد.',
                ]);
            }

            $this->step('default', $chatId);
            $this->handleStartCommand($chatId, $userId);
        } catch (\Exception | \PDOException $e) {
            Log::error('Error creating user or session: ' . $e->getMessage());
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'خطایی در ثبت اطلاعات شما رخ داده است. لطفا دوباره تلاش کنید.',
            ]);
        }
    }

    public function step(?string $step = '', ?string $chatId): string
    {
        $session = Sessions::where(['key' => 'user_session', 'value' => $chatId])->first();
        $userId = $session->user_id ?? null;
        $key = ['key' => 'step', 'user_id' => $userId];

        if (empty($step)) {
            return Sessions::where($key)->value('value') ?? 'default';
        }

        $value = $step === 'default' ? '' : $step;
        $session = Sessions::updateOrCreate($key, ['value' => $value, 'chat_id' => $chatId]);

        return $session->value;
    }
}
