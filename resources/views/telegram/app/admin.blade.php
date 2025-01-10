<div class="container">
    <h1>مدیریت ربات تلگرام</h1>
    <div class="menu">
        <ul>
            <li><a href="{{ route('telegram.mini_app.admin') }}">داشبورد</a></li>
            <li><a href="{{ route('telegram.set') }}">تنظیم وب‌هوک</a></li>
            <li><a href="{{ route('telegram.info') }}">اطلاعات وب‌هوک</a></li>
            <li><a href="{{ route('db.show', ['table' => 'users']) }}">نمایش کاربران</a></li>
            <li><a href="{{ route('db.show', ['table' => 'transactions']) }}">نمایش تراکنش‌ها</a></li>
        </ul>
    </div>
    <div class="content">
        <p>به بخش مدیریت ربات تلگرام خوش آمدید. از منوی بالا برای مدیریت و مشاهده اطلاعات استفاده کنید.</p>
    </div>
</div>
