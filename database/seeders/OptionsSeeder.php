<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Options;

class OptionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Options::factory()->create([
            'key' => 'admins',
            'data' => '5482937915',
        ]);
        Options::factory()->create([
            'key' => 'channel_requirement',
            'data' => '1',
        ]);
        Options::factory()->create([
            'key' => 'transaction_channel',
            'data' => '',
        ]);
        Options::factory()->create([
            'key' => 'log_channel',
            'data' => '',
        ]);
        Options::factory()->create([
            'key' => 'support_channel',
            'data' => '',
        ]);
        // Options::factory()->create([
        //     'key' => '',
        //     'data' => '',
        // ]);
        Options::factory()->create([
            'key' => 'referral_bonus',
            'data' => '10',
        ]);
        Options::factory()->create([
            'key' => 'deposit_min',
            'data' => '10000',
        ]);
        Options::factory()->create([
            'key' => 'withdraw_min',
            'data' => '10000',
        ]);
        Options::factory()->create([
            'key' => 'withdraw_max',
            'data' => '50000000',
        ]);
        Options::factory()->create([
            'key' => 'deposit_max',
            'data' => '50000000',
        ]);

        // messages
        Options::factory()->create([
            'key' => 'transaction_deposit_status_message[admin]',
            'data' => 'تراکنش : شارژ\n- مقدار: <code>%d</code>\n- شناسه پرداخت: <code>%s</code>' // need %d , %s
        ]);
        Options::factory()->create([
            'key' => 'transaction_deposit_pending_message[user]',
            'data' => 'تراکنش ثبت شد و بعد از تایید ادمین مبلغ به صورت خودکار به حساب شما واریز میشود.'
        ]);
    }
}
