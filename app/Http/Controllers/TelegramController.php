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
    public static ?string $chatId = null;
    public static ?int $userId = null;
    public static ?int $user_id = null;
    public static ?string $command = null;
    public static ?string $content = null;
    public static ?array $updateData = null;
    public static $update;

    public function __construct(Api $telegram)
    {
        $this->adminChatId = explode(',', Options::where('key', 'admins')->value('data'));
        $this->telegram = $telegram;
    }

    public function webhook(Request $request)
    {
        $update = $this->telegram->getWebhookUpdates();
        file_put_contents('telegram_webhook_log.txt', json_encode($update->getRawData()) . "\n", FILE_APPEND);
        self::$update = $update;
        try {
            $this->handleUpdate();
            return response('ok', Response::HTTP_OK);
        } catch (\Exception $e) {
            Log::info($e);
            Log::error('Error in webhook: ' . $e->getMessage());
            return response('Service Unavailable', Response::HTTP_SERVICE_UNAVAILABLE);
        }
    }

    protected function handleUpdate()
    {
        if (self::$update->isType('message')) {
            $this->setUpdateData('message');
        } elseif (self::$update->isType('callback_query')) {
            $this->setUpdateData('callback_query');
        }
        $this->handleCommand();
    }

    protected function setUpdateData($type)
    {
        if ($type == 'callback_query') {
            self::$chatId = self::$update->getCallbackQuery()->getMessage()->getChat()->getId();
            self::$userId = self::$update->getCallbackQuery()->getFrom()->getId();
            self::$command = $this->getCommand(self::$update->getCallbackQuery()->getData());
            self::$content = $this->getContent(self::$update->getCallbackQuery()->getData());
        } elseif ($type == 'message') {
            self::$chatId = self::$update->getMessage()->getChat()->getId();
            self::$userId = self::$update->getMessage()->getFrom()->getId();
            self::$command = $this->getCommand(self::$update->getMessage()->getText());
            self::$content = $this->getContent(self::$update->getMessage()->getText());
        }
        self::$updateData = self::$update->toArray();
        self::$user_id = $this->getUserIdFromFile();
    }

    protected function handleCommand()
    {
        $commandHandlers = [
            '/start' => fn() => $this->handleStartCommand(),
            '/referral' => fn() => $this->handleReferral(),
            'referral' => fn() => $this->handleReferral(),
            '/support' => fn() => $this->handleSupport(),
            'support' => fn() => $this->handleSupportGet(),
            'deposit_get_transaction' => fn() => $this->handleDepositGet(),
            'broadcasting' => fn() => $this->handleBroadCastingGet(),
            'get_contact_for_login' => fn() => $this->handleGetContactForLogin(),
            'deposit' => fn() => $this->handleDeposit(),
            'withdraw' => fn() => $this->handleWithdraw(),
            'withdraw_get_transaction' => fn() => $this->handleWithdrawGet(),
            'back_to_main' => fn() => $this->handleStartCommand(),
            'broadcast' => fn() => $this->handleBroadcast(),
            'send_broadcasting' => fn() => $this->handleBroadCastingSend(),
        ];


        foreach ($commandHandlers as $key => $handler) {
            if (self::$command == $key) {
                $handler();
                return;
            }
        }

        $this->handleSpecialCommands();
    }

    protected function handleSpecialCommands()
    {
        $command = self::$command;
        if (strpos($command, 'confirm_deposit_') === 0) {
            $this->confirmDeposit(str_replace('confirm_deposit_', '', $command));
        } elseif (strpos($command, 'reject_deposit_') === 0) {
            $this->rejectDeposit(str_replace('reject_deposit_', '', $command));
        } elseif (strpos($command, 'confirm_withdraw_') === 0) {
            $this->confirmWithdraw(str_replace('confirm_withdraw_', '', $command));
        } elseif (strpos($command, 'reject_withdraw_') === 0) {
            $this->rejectWithdraw(str_replace('reject_withdraw_', '', $command));
        } else {
            $this->handleDynamicContent($command);
        }
    }

    protected function getCommand($input)
    {
        return $this->step() != 'default' ? $this->step() : $input;
    }

    protected function getContent($input)
    {
        return $this->step() != 'default' ? $input : '';
    }

    protected function handleDeposit()
    {
        $this->sendMessage('لطفا مبلغ واریزی خود را به همراه شناسه پرداخت ارسال کنید.');
        $this->step('deposit_get_transaction');
    }

    protected function handleStartCommand()
    {
        if (!$this->createUserIfNotExists()) {
            return;
        }
        $keyboard = $this->getMainMenuKeyboard();
        $this->sendMessage('به ربات خوش آمدید! لطفا از منوی زیر انتخاب کنید:', $keyboard);
    }

    protected function handleDepositGet()
    {
        if (preg_match('/(\d+)\n+(.+)/', self::$content, $matches)) {
            $this->processDeposit($matches[1], trim($matches[2]));
            $this->step('default');
            $this->handleStartCommand();
        } else {
            $this->sendInvalidFormatMessage();
        }
    }

    protected function handleWithdrawGet()
    {
        if (preg_match('/(\d+)/', self::$content, $matches)) {
            $this->processWithdraw($matches[1]);
            $this->step('default');
            $this->handleStartCommand();
        } else {
            $this->sendInvalidFormatMessage();
        }
    }

    protected function processDeposit($amount, $paymentId)
    {
        if (Options::get('transaction_channel'))
            $this->sendMessage(vprintf(Options::get('transaction_deposit_status_message[admin]'), [$amount, $paymentId]), Options::get('transaction_channel'));
        $this->sendMessage(Options::get('transaction_deposit_pending_message[user]'));
    }

    protected function processWithdraw($amount)
    {
        // if (Options::get('transaction_channel'))
        //     $this->sendMessage(vprintf(Options::get('transaction_deposit_status_message[admin]'), [$amount, $paymentId]), Options::get('transaction_channel'));
        $this->sendMessage(Options::get('transaction_deposit_pending_message[user]'));
    }

    protected function sendInvalidFormatMessage()
    {
        $this->sendMessage('فرمت ورودی نادرست است. لطفا دوباره تلاش کنید. مثال: 1000\n1234567890. برای کمک بیشتر، با پشتیبانی تماس بگیرید.');
    }

    protected function handleBroadCastingGet()
    {
        if (is_string(self::$content)) {
            $this->createBroadcastMessage('text', '', self::$content);
        } elseif (is_array(self::$content) && isset(self::$content['type'])) {
            $this->createBroadcastMessage(self::$content['type'], self::$content['data'], self::$content['caption'] ?? '');
        } else {
            $this->sendUnsupportedContentTypeMessage();
            return;
        }
        $this->step('default');
        $this->sendBroadcastReceivedMessage();
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

    protected function sendUnsupportedContentTypeMessage()
    {
        $this->sendMessage('نوع محتوای ارسال شده پشتیبانی نمی‌شود.');
    }

    protected function sendBroadcastReceivedMessage()
    {
        $keyboard = [
            [
                ['text' => 'ادامه', 'callback_data' => 'broadcasting'],
                ['text' => 'ارسال', 'callback_data' => 'send_broadcasting']
            ]
        ];
        $this->sendMessage('پیام شما دریافت شد.', new Keyboard(['inline_keyboard' => $keyboard]));
    }

    protected function handleBroadCastingSend()
    {
        $messages = Messages::where('section', 'broadcasting')->where('status', 'pending')->get();
        $users = User::all();

        if ($messages->isEmpty()) {
            $this->sendMessage('هیچ محتوایی برای ارسال یافت نشد.');
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
        $method = match ($message->type) {
            'image' => 'sendPhoto',
            'video' => 'sendVideo',
            'audio' => 'sendAudio',
            default => 'sendMessage',
        };

        $this->telegram->$method([
            'chat_id' => $userTgId,
            $message->type => $message->data,
            'caption' => $message->content,
        ]);
    }

    protected function handleWithdraw()
    {
        $this->sendMessage('لطفا مبلغ برداشت خود را به همراه شماره حساب ارسال کنید.');
        $this->step('withdraw_get_transaction');
    }

    protected function handleReferral()
    {
        $user = User::find(self::$user_id);
        if ($user) {
            $referralLink = url('/register?ref=' . $user->id);
            $this->sendMessage('لینک زیرمجموعه گیری شما: ' . $referralLink);
        } else {
            $this->sendMessage('کاربر یافت نشد.');
        }
        $this->step('default');
        $this->handleStartCommand();
    }

    protected function handleSupport()
    {
        $this->sendMessage('لطفا پیام خود را ارسال کنید تا پشتیبانی در اسرع وقت پاسخ دهد.');
        $this->step('support');
    }

    protected function handleSupportGet()
    {
        $content = self::$content;
        $this->sendMessage($content, chatId: $this->adminChatId[0]);
        $this->sendMessage('پیام شما با موفقیت به پشتیبانی ارسال شد.');
        $this->step('default');
        $this->handleStartCommand();
    }

    protected function handleDynamicContent($key)
    {
        $dynamicContents = DynamicContent::where('key', $key)->get();
        if ($dynamicContents->isEmpty()) {
            $this->sendMessage('محتوایی برای این دکمه وجود ندارد.');
            return;
        }

        foreach ($dynamicContents as $dynamicContent) {
            $this->sendDynamicContent($dynamicContent);
        }
    }

    protected function sendDynamicContent($dynamicContent)
    {
        $method = match ($dynamicContent->type) {
            'image' => 'sendPhoto',
            'video' => 'sendVideo',
            default => 'sendMessage',
        };

        $this->telegram->$method([
            'chat_id' => self::$chatId,
            $dynamicContent->type => $dynamicContent->content,
        ]);
    }

    protected function handleBroadcast()
    {
        $this->sendMessage('لطفا پیام مورد نظر برای ارسال همگانی را ارسال کنید:');
        $this->step('broadcasting');
    }

    protected function isAdmin()
    {
        return in_array(self::$chatId, $this->adminChatId);
    }

    protected function confirmDeposit($depositId)
    {
        $transaction = Transaction::find($depositId);
        if ($transaction && $transaction->type == 'deposit' && $transaction->status == 'pending') {
            $transaction->status = 'approved';
            $transaction->save();

            $user = User::find($transaction->user_id);
            if ($user) {
                $user->balance += $transaction->amount;
                $user->save();

                $this->sendMessage("واریز شما با شناسه {$depositId} تایید شد.", $transaction->user_id);
                $this->sendMessage("واریز با شناسه {$depositId} تایید شد.", $this->adminChatId);
            }
        }
    }

    protected function rejectDeposit($depositId)
    {
        $transaction = Transaction::find($depositId);
        if ($transaction && $transaction->type == 'deposit' && $transaction->status == 'pending') {
            $transaction->status = 'rejected';
            $transaction->save();

            $this->sendMessage("واریز شما با شناسه {$depositId} رد شد.", $transaction->user_id);
            $this->sendMessage("واریز با شناسه {$depositId} رد شد.", $this->adminChatId);
        }
    }

    protected function confirmWithdraw($withdrawId)
    {
        $transaction = Transaction::find($withdrawId);
        if ($transaction && $transaction->type == 'withdrawal' && $transaction->status == 'pending') {
            $user = User::find($transaction->user_id);
            if ($user && $user->balance >= $transaction->amount) {
                $user->balance -= $transaction->amount;
                $user->save();

                $transaction->status = 'approved';
                $transaction->save();

                $this->sendMessage("برداشت شما با شناسه {$withdrawId} تایید شد.", self::$userId);
                $this->sendMessage("برداشت با شناسه {$withdrawId} تایید شد.", $this->adminChatId);
            } else {
                $this->sendMessage('موجودی کافی برای انجام این برداشت وجود ندارد.');
            }
        }
    }

    protected function rejectWithdraw($withdrawId)
    {
        $transaction = Transaction::find($withdrawId);
        if ($transaction && $transaction->type == 'withdrawal' && $transaction->status == 'pending') {
            $transaction->status = 'rejected';
            $transaction->save();

            $this->sendMessage("برداشت شما با شناسه {$withdrawId} رد شد.", $transaction->user_id);
            $this->sendMessage("برداشت با شناسه {$withdrawId} رد شد.", $this->adminChatId);
        }
    }

    protected function getMainMenuKeyboard()
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

        if ($this->isAdmin()) {
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

    protected function createUserIfNotExists()
    {
        $user = $this->getUserIdFromFile();

        if (empty($user) || $user === -1) {
            $this->saveUserIdToFile(-1);
            $this->requestContact();
            $this->step('get_contact_for_login');
            return false;
        }
        return true;
    }

    protected function requestContact()
    {
        $keyboard = [
            [
                ['text' => 'ارسال شماره موبایل 📱', 'request_contact' => true]
            ]
        ];
        $this->sendMessage('لطفا برای استفاده از ربات، شماره موبایل خود را با استفاده از دکمه زیر ارسال کنید.', new Keyboard(['keyboard' => $keyboard, 'resize_keyboard' => true, 'one_time_keyboard' => true]));
    }

    protected function handleGetContactForLogin()
    {
        if (self::$update->getMessage()->getContact()) {
            $this->registerUser();
        } else {
            $this->sendMessage('لطفا شماره موبایل خود را با استفاده از دکمه ارسال کنید.');
        }
    }

    protected function registerUser()
    {
        $phoneNumber = self::$update->getMessage()->getContact()['phone_number'];

        try {
            $user = User::updateOrCreate(
                ['mobile' => $phoneNumber],
                [
                    'name' => self::$update->getMessage()->getContact()['first_name'] ?? '',
                    'mobile_verified_at' => now(),
                    'password' => bcrypt(self::$userId)
                ]
            );

            $sessionData = ['user_id' => $user->id];
            Sessions::updateOrCreate(
                ['key' => 'user_session', 'user_id' => $user->id],
                array_merge($sessionData, ['value' => self::$chatId])
            );
            Sessions::updateOrCreate(
                ['key' => 'user_tg_id', 'user_id' => $user->id],
                array_merge($sessionData, ['value' => self::$userId])
            );

            $this->saveUserIdToFile($user->id);

            if ($user->wasRecentlyCreated) {
                $this->sendMessage('شماره موبایل شما با موفقیت ثبت شد.');
            }

            $this->step('default');
            $this->handleStartCommand();
        } catch (\Exception | \PDOException $e) {
            Log::error('Error creating user or session: ' . $e->getMessage());
            $this->sendMessage('خطایی در ثبت اطلاعات شما رخ داده است. لطفا دوباره تلاش کنید.');
        }
    }

    protected function saveUserIdToFile($userId)
    {
        $filename = self::$chatId . '.txt';
        $directory = storage_path('app/chat/');
        $path = $directory . $filename;

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($path, $userId);
    }

    protected function getUserIdFromFile(): ?int
    {
        $filename = self::$chatId . '.txt';
        $path = storage_path('app/chat/' . $filename);
        if (file_exists($path)) {
            return (int) file_get_contents($path);
        }
        return null;
    }

    public function step(?string $step = ''): string
    {
        $filename = self::$chatId . '.txt';
        $directory = storage_path('app/step/');
        $path = $directory . $filename;

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        if (empty($step)) {
            return file_exists($path) ? file_get_contents($path) ?: 'default' : 'default';
        }

        file_put_contents($path, $step === 'default' ? '' : $step);
        return $step;
    }

    protected function sendMessage($text, $replyMarkup = null, $chatId = null)
    {
        $this->telegram->sendMessage([
            'chat_id' => self::$chatId,
            'text' => $text,
            'reply_markup' => $replyMarkup,
        ]);
    }
}
