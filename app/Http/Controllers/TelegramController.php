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

class TelegramController extends Controller
{
    protected Api $telegram;
    protected array|null $adminChatId;

    public function __construct(Api $telegram)
    {
        $this->adminChatId = explode(',', env('TELEGRAM_ADMIN_CHAT_ID', ''));
        $this->telegram = $telegram;
    }

    public function webhook(Request $request)
    {
        $update = $this->telegram->getWebhookUpdates();

        if ($update->isType('message')) {
            $message = $update->getMessage();
            $chatId = $message->getChat()->getId();
            $text = $message->getText();
            $userId = $message->getFrom()->getId();

            if ($text == '/start') {
                $this->handleStartCommand($chatId, $userId);
            } else {
                $this->handleMessage($chatId, $text, $userId);
            }
        } elseif ($update->isType('callback_query')) {
            $callbackQuery = $update->getCallbackQuery();
            $data = $callbackQuery->getData();
            $chatId = $callbackQuery->getMessage()->getChat()->getId();
            $userId = $callbackQuery->getFrom()->getId();
            $this->handleCallbackQuery($chatId, $data, $userId);
        }
        return 'ok';
    }

    protected function handleStartCommand($chatId, $userId)
    {
        $register = $this->createUserIfNotExists($chatId, $userId);
        if (!$register)
            return;
        $keyboard = $this->getMainMenuKeyboard($chatId);
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'به ربات خوش آمدید! لطفا از منوی زیر انتخاب کنید:',
            'reply_markup' => $keyboard,
        ]);
    }

    protected function handleMessage($chatId, $text, $userId)
    {
        $command = $this->step('', $chatId) != 'default' ? $this->step('', $chatId) : $text;
        $content = $this->step('', $chatId) != 'default' ? $text : '';
        switch (true) {
            case str_contains($command, '/referral'):
                $this->handleReferral($chatId, $userId);
                break;
            case str_contains($command, '/support'):
                $this->handleSupport($chatId, $userId);
                break;
            case str_contains($command, 'deposit'):
            case $command === 'deposit':
                $this->handleDepositGet($chatId, $userId, $content);
                break;
            case $command === 'broadcasting':
                $this->handleBroadCastingGet($chatId, $userId, $content);
                break;
            case $command === 'get_contact_for_login':
                $this->handleGetContactForLogin($chatId, $userId, $content);
                break;
            default:
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => "متوجه پیام شما نشدم. لطفا از منو استفاده کنید.\ncommand: {$command}\n{$this->step('', $chatId)}",
                ]);
                break;
        }
    }

    protected function handleCallbackQuery($chatId, $data, $userId)
    {
        $command = $this->step('', $chatId) != 'default' ? $this->step('', $chatId) : $data;
        $content = $this->step('', $chatId) != 'default' ? $data : '';
        switch (true) {
            case $command === 'deposit':
                $this->handleDeposit($chatId, $userId);
                break;
            case $command === 'withdraw':
                $this->handleWithdraw($chatId, $userId);
                break;
            case $command === 'referral':
                $this->handleReferral($chatId, $userId);
                break;
            case $command === 'support':
                $this->handleSupport($chatId, $userId);
                break;
            case $command === 'back_to_main':
                $this->handleStartCommand($chatId, $userId);
                break;
            case $command === 'broadcast':
                $this->handleBroadcast($chatId);
                break;
            case $command === 'send_broadcasting':
                $this->handleBroadCastingSend($chatId);
                break;
            case strpos($command, 'confirm_deposit_') === 0:
                $depositId = str_replace('confirm_deposit_', '', $command);
                $this->confirmDeposit($chatId, $depositId, $userId);
                break;
            case strpos($command, 'reject_deposit_') === 0:
                $depositId = str_replace('reject_deposit_', '', $command);
                $this->rejectDeposit($chatId, $depositId, $userId);
                break;
            case strpos($command, 'confirm_withdraw_') === 0:
                $withdrawId = str_replace('confirm_withdraw_', '', $command);
                $this->confirmWithdraw($chatId, $withdrawId, $userId);
                break;
            case strpos($command, 'reject_withdraw_') === 0:
                $withdrawId = str_replace('reject_withdraw_', '', $command);
                $this->rejectWithdraw($chatId, $withdrawId, $userId);
                break;
            default:
                $this->handleDynamicContent($chatId, $command);
        }
    }

    protected function handleDeposit($chatId, $userId)
    {
        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => 'لطفا مبلغ واریزی خود را به همراه شناسه پرداخت ارسال کنید.',
        ]);
        $this->step('deposit', $chatId);
    }
    protected function handleDepositGet($chatId, $userId, $content)
    {
        preg_match('/(\d+)\n+(.+)/', $content, $matches);
        if (!empty($matches)) {
            $amount = $matches[1];
            $paymentId = trim($matches[2]);
            $this->telegram->sendMessage([
                'chat_id' => Options::get('transaction_channel'),
                'text' => vprintf(Options::get('transaction_deposit_status_message[admin]'), [
                    $amount,
                    $paymentId
                ]),
            ]);
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => Options::get('transaction_deposit_pending_message[user]'),
            ]);
            $this->step('default', $chatId);
            $this->handleStartCommand($chatId, $userId);
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'فرمت ورودی نادرست است. لطفا دوباره تلاش کنید. مثال: 1000\n1234567890. برای کمک بیشتر، با پشتیبانی تماس بگیرید.',
            ]);
        }
    }


    protected function handleBroadCastingGet($chatId, $userId, $content)
    {
        if (is_string($content)) {
            Messages::create([
                'section' => 'broadcasting',
                'status' => 'pending',
                'type' => 'text',
                'data' => '',
                'content' => $content,
            ]);
        } elseif (is_array($content) && isset($content['type'])) {
            switch ($content['type']) {
                case 'image':
                    Messages::create([
                        'section' => 'broadcasting',
                        'status' => 'pending',
                        'type' => 'image',
                        'data' => $content['data'],
                        'content' => $content['caption'] ?? '',
                    ]);
                    break;
                case 'video':
                    Messages::create([
                        'section' => 'broadcasting',
                        'status' => 'pending',
                        'type' => 'video',
                        'data' => $content['data'],
                        'content' => $content['caption'] ?? '',
                    ]);
                    break;
                case 'audio':
                    Messages::create([
                        'section' => 'broadcasting',
                        'status' => 'pending',
                        'type' => 'audio',
                        'data' => $content['data'],
                        'content' => $content['caption'] ?? '',
                    ]);
                    break;
                default:
                    return $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'نوع محتوای ارسال شده پشتیبانی نمی‌شود.',
                    ]);
            }
        } else {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'نوع محتوای ارسال شده پشتیبانی نمی‌شود.',
            ]);
        }
        $this->step('default', $chatId);
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
        if ($messages->isNotEmpty()) {
            foreach ($users as $user) {
                $user_tg_id = $user->get('id');
                foreach ($messages as $message) {
                    switch ($message->type) {
                        case 'image':
                            $this->telegram->sendPhoto([
                                'chat_id' => $user_tg_id,
                                'photo' => $message->data,
                                'caption' => $message->content,
                            ]);
                            break;
                        case 'video':
                            $this->telegram->sendVideo([
                                'chat_id' => $user_tg_id,
                                'video' => $message->data,
                                'caption' => $message->content,
                            ]);
                            break;
                        case 'audio':
                            $this->telegram->sendAudio([
                                'chat_id' => $user_tg_id,
                                'audio' => $message->data,
                                'caption' => $message->content,
                            ]);
                            break;
                        default:
                            $this->telegram->sendMessage([
                                'chat_id' => $user_tg_id,
                                'text' => $message->content,
                            ]);
                    }
                }
            }
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'هیچ محتوایی برای ارسال یافت نشد.',
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
        $dynamicContents = DynamicContent::where('key', $key);
        if ($dynamicContents) {
            foreach ($dynamicContents as $dynamicContent) {
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
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'محتوایی برای این دکمه وجود ندارد.',
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
        $dynamic_buttons = DynamicButton::where('status', 'active')->get();
        foreach ($dynamic_buttons as $button) {
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
                ['text' => 'تنظیمات ⚙️', 'callback_data' => 'settings'],
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
            $this->step('get_contact_for_login', $chatId);
            return false;
        }
        return true;
    }

    protected function handleGetContactForLogin($chatId, $userId, $content)
    {
        if (isset($content['contact']) && $content['contact']['user_id'] == $userId) {
            $phoneNumber = $content['contact']['phone_number'];

            $user = User::firstOrCreate(
                ['mobile' => $phoneNumber],
                [
                    'name' => $content['contact']['first_name'] ?? '',
                    'mobile' => $phoneNumber,
                    'mobile_verified_at' => now(),
                    'password' => bcrypt($userId)
                ]
            );

            Sessions::updateOrCreate(
                ['key' => 'user_session', 'user_id' => $user],
                ['value' => $chatId, 'user_id' => $user]
            );
            Sessions::updateOrCreate(
                ['key' => 'user_tg_id', 'user_id' => $user],
                ['value' => $userId, 'user_id' => $user]
            );

            if ($user->wasRecentlyCreated) {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'شماره موبایل شما با موفقیت ثبت شد.',
                ]);
            }

            $this->step('default', $chatId);
            $this->handleStartCommand($chatId, $userId);
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'لطفا شماره موبایل خود را با استفاده از دکمه ارسال کنید.',
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
