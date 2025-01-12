import TelegramMiniAppLayout from '@/Layouts/TelegramMiniAppLayout';
import { Head } from '@inertiajs/react';
import { useEffect } from 'react';

export default function Dashboard() {
    useEffect(() => {
        console.log(window.Telegram.WebApp);
    }, []);
    return (
        <TelegramMiniAppLayout>
            <Head title="پنل مدیریت تلگرام">
                <script
                    src="https://telegram.org/js/telegram-web-app.js?56"
                    async
                ></script>
            </Head>

            <div className="py-12">
                <div className="mx-auto max-w-7xl sm:px-6 lg:px-8">
                    <div className="overflow-hidden bg-white shadow-sm sm:rounded-lg dark:bg-gray-800">
                        <div className="p-6 text-gray-900 dark:text-gray-100">
                            به پنل مدیریت خوش آمدید!
                        </div>
                        <div className="p-6">
                            <button className="rounded bg-blue-500 px-4 py-2 text-white">
                                ارسال پیام همگانی
                            </button>
                        </div>
                        <div className="p-6">
                            <button className="rounded bg-green-500 px-4 py-2 text-white">
                                مدیریت کاربران
                            </button>
                        </div>
                        <div className="p-6">
                            <button className="rounded bg-red-500 px-4 py-2 text-white">
                                گزارشات
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </TelegramMiniAppLayout>
    );
}
