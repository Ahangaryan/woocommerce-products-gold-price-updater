<?php
/**
 * Persian (fa_IR) translations for Gold Price Updater.
 *
 * Embedded translations — no .po/.mo dependency.
 *
 * @package GoldPriceUpdater
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GPU_Translations {

	/** @var array<string, string> */
	private static $translations = array(
		// Header
		'Gold Price Updater'              => 'بروزرسان خودکار قیمت طلا',
		'Sync Now'                        => 'بروزرسانی کن',
		'Force Sync'                      => 'بروزرسانی اجباری',

		// Status Cards
		'Gold Price (18K)'                => 'قیمت طلا (۱۸ عیار)',
		'Live from API'                   => 'زنده از API',
		'Not connected'                   => 'اتصال برقرار نیست',
		'Effective Price'                 => 'قیمت مؤثر',
		'buffer'                          => 'بافر',
		'API Status'                      => 'وضعیت API',
		'Connected'                       => 'متصل',
		'Disconnected'                    => 'قطع شده',
		'Last Sync'                       => 'آخرین بروزرسانی',
		'Never synced'                    => 'هنوز بروزرسانی نشده',
		'All good'                        => 'همه چیز خوب است',

		// Progress
		'Product Update Progress'         => 'پیشرفت بروزرسانی محصولات',
		'Updated'                         => 'بروزرسانی شده',
		'Skipped'                         => 'رد شده',
		'Errors'                          => 'خطاها',
		'Pending'                         => 'در انتظار',

		// Tabs
		'General'                         => 'عمومی',
		'Pricing'                         => 'قیمت‌گذاری',
		'API'                             => 'API',
		'Sync'                            => 'بروزرسانی',
		'Advanced'                        => 'پیشرفته',

		// General Settings
		'General Settings'                => 'تنظیمات عمومی',
		'Enable Plugin'                   => 'فعال‌سازی افزونه',
		'Enable gold price auto-update'   => 'فعال‌سازی بروزرسانی خودکار قیمت طلا',
		'Price Mode'                      => 'حالت قیمت',
		'API only'                        => 'فقط API',
		'Manual only'                     => 'فقط دستی',
		'API with fallback to manual (Recommended)' => 'API با بازگشت به حالت دستی (توصیه شده)',
		'How the gold price is determined.' => 'نحوه تعیین قیمت طلا.',
		'Manual Price'                    => 'قیمت دستی',
		'Used in manual mode or as fallback when API fails.' => 'در حالت دستی یا به عنوان بازگشت در صورت خرابی API استفاده می‌شود.',
		'Price Buffer'                    => 'بافر قیمت',
		'Added to the gold price before product calculation.' => 'قبل از محاسبه محصول به قیمت طلا اضافه می‌شود.',
		'Gold Karat'                      => 'عیار طلا',
		'Target Currency'                 => 'ارز مقصد',
		'Max Price Change %'              => 'حداکثر تغییر قیمت %',
		'0 = disabled. Blocks sync if price changes more than this.' => '0 = غیرفعال. اگر قیمت بیشتر از این مقدار تغییر کند، بروزرسانی متوقف می‌شود.',
		'Sale Price Behavior'             => 'رفتار قیمت حراج',
		'Update regular price only'       => 'فقط قیمت عادی را بروزرسانی کن',
		'Skip products with active sale'  => 'محصولات دارای حراج فعال را رد کن',

		// Pricing
		'Pricing Formula'                 => 'فرمول قیمت‌گذاری',
		'Making Charge %'                 => 'اجرت ساخت %',
		'Labor cost percentage applied to gold value.' => 'درصد هزینه ساخت روی ارزش طلا اعمال می‌شود.',
		'Seller Profit %'                 => 'سود فروشنده %',
		'Applied to (gold value + making charge).' => 'روی (ارزش طلا + اجرت ساخت) اعمال می‌شود.',
		'VAT %'                           => 'مالیات %',
		'Applied to making charge only.'  => 'فقط روی اجرت ساخت اعمال می‌شود.',
		'Rounding Mode'                   => 'حالت گرد کردن',
		'None'                            => 'بدون',
		'Nearest'                         => 'نزدیک‌ترین',
		'Round Up'                        => 'گرد کردن به بالا',
		'Round Down'                      => 'گرد کردن به پایین',
		'e.g. step 1000 rounds to nearest/next 1,000.' => 'مثال: مرحله ۱۰۰۰ قیمت را به نزدیک‌ترین/بعدی ۱,۰۰۰ گرد می‌کند.',
		'Price Decimals'                  => 'اعشار قیمت',

		// API Settings
		'API Configuration'               => 'تنظیمات API',
		'Provider'                        => 'ارائه‌دهنده',
		'Select a preset or choose Custom.' => 'یک الگو انتخاب کنید یا سفارشی را انتخاب کنید.',
		'API URL'                         => 'آدرس API',
		'Auth Method'                     => 'روش احراز هویت',
		'API Key'                         => 'کلید API',
		'API Secret'                      => 'رمز API',
		'Request Method'                  => 'روش درخواست',
		'JSON Path'                       => 'مسیر JSON',
		'Dot notation to extract price from JSON response.' => 'نقطه‌گذاری برای استخراج قیمت از پاسخ JSON.',
		'Price Unit'                      => 'واحد قیمت',
		'API Currency'                    => 'ارز API',
		'Timeout (seconds)'               => 'زمان انتظار (ثانیه)',
		'Test API Connection'             => 'تست اتصال API',

		// Sync Settings
		'Sync Settings'                   => 'تنظیمات بروزرسانی',
		'Update Interval'                 => 'فاصله بروزرسانی',
		'Seconds between automatic syncs (min 60).' => 'ثانیه بین بروزرسانی‌های خودکار (حداقل ۶۰).',
		'Batch Size'                      => 'اندازه دسته',
		'Products processed per queue tick.' => 'محصولات پردازش شده در هر نوبت صف.',
		'Retry Count'                     => 'تعداد تلاش مجدد',
		'Retry Delay'                     => 'تأخیر تلاش مجدد',
		'Seconds between retry attempts.' => 'ثانیه بین تلاش‌های مجدد.',
		'Lock Timeout'                    => 'زمان انتظار قفل',
		'Prevents concurrent syncs.'      => 'از بروزرسانی‌های همزمان جلوگیری می‌کند.',

		// Advanced Settings
		'Advanced Settings'               => 'تنظیمات پیشرفته',
		'Debug Mode'                      => 'حالت اشکال‌زدایی',
		'Show response details in logs'   => 'نمایش جزئیات پاسخ در گزارش‌ها',
		'Logging'                         => 'گزارش‌گیری',
		'Enable activity logging'         => 'فعال‌سازی گزارش‌گیری فعالیت',
		'Log Retention'                   => 'نگهداری گزارش',
		'Days to keep log entries.'       => 'روزهای نگهداری ورودی‌های گزارش.',
		'Email on Failure'                => 'ایمیل در صورت خطا',
		'Send email after repeated sync failures' => 'ارسال ایمیل پس از خرابی‌های مکرر بروزرسانی',
		'Email Recipient'                 => 'گیرنده ایمیل',
		'GitHub Repo'                     => 'مخزن GitHub',
		'For automatic plugin updates.'   => 'برای بروزرسانی خودکار افزونه.',
		'Save Settings'                   => 'ذخیره تنظیمات',

		// Tables
		'Sync History'                    => 'تاریخچه بروزرسانی',
		'Recent Logs'                     => 'گزارش‌های اخیر',
		'Time'                            => 'زمان',
		'Source'                          => 'منبع',
		'Gold Price'                      => 'قیمت طلا',
		'Effective'                       => 'مؤثر',
		'Products'                        => 'محصولات',
		'Status'                          => 'وضعیت',
		'Level'                           => 'سطح',
		'Event'                           => 'رویداد',
		'Message'                         => 'پیام',

		// Notices / Messages
		'Settings saved.'                 => 'تنظیمات ذخیره شد.',
		'Settings saved'                  => 'تنظیمات ذخیره شد',
		'Connected. Current price: %s'    => 'اتصال برقرار شد. قیمت فعلی: %s',
		'Connection failed: %1$s (HTTP %2$d)' => 'اتصال ناموفق: %1$s (HTTP %2$d)',
		'Another sync is already running.' => 'یک بروزرسانی دیگر در حال اجرا است.',
		'Sync started — %d product(s) queued.' => 'بروزرسانی شروع شد — %d محصول در صف قرار گرفت.',
		'Permission denied.'              => 'دسترسی رد شد.',
		'API URL is not configured. Select a Provider below and save settings.' => 'آدرس API تنظیم نشده. در زیر یک ارائه‌دهنده انتخاب کرده و تنظیمات را ذخیره کنید.',
		'No syncs yet.'                   => 'هنوز بروزرسانی انجام نشده.',
		'No log entries.'                 => 'گزارشی موجود نیست.',
		'Seller Profit'                   => 'سود فروشنده',
		'Making Charge'                   => 'اجرت ساخت',

		// Footer
		'Made by'                         => 'ساخته شده توسط',

		// Admin language
		'Site Language'                   => 'زبان سایت',
	);

	/**
	 * Translate a string if the current locale is fa_IR.
	 *
	 * @param string $text English text.
	 *
	 * @return string
	 */
	public static function t( string $text ): string {
		$locale = get_locale();

		if ( 'fa_IR' !== $locale || ! isset( self::$translations[ $text ] ) ) {
			return $text;
		}

		return self::$translations[ $text ];
	}

	/**
	 * Translated text for use in echo contexts (escaped).
	 *
	 * @param string $text English text.
	 *
	 * @return string
	 */
	public static function e( string $text ): string {
		return esc_html( self::t( $text ) );
	}

	/**
	 * Whether the current locale is RTL.
	 *
	 * @return bool
	 */
	public static function is_rtl(): bool {
		return get_bloginfo( 'text_direction' ) === 'rtl';
	}
}
