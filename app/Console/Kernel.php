<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $env = config('app.env');
        $email = config('mail.username');

        if ($env === 'live') {
            //Scheduling backup, specify the time when the backup will get cleaned & time when it will run.
            
            $schedule->command('backup:clean')->daily()->at('01:00');
            $schedule->command('backup:run')->daily()->at('01:30');


            //Schedule to create recurring invoices
            $schedule->command('pos:generateSubscriptionInvoices')->dailyAt('23:30');
            $schedule->command('pos:updateRewardPoints')->dailyAt('23:45');

            $schedule->command('pos:autoSendPaymentReminder')->dailyAt('8:00');

            $schedule->command('pos:generateRecurringExpense')->dailyAt('02:00');

            // Schedule Shopify sync (daily at 3:00 AM)
            $schedule->command('shopify:sync --type=all')->dailyAt('03:00');

        }

        if ($env === 'demo') {
            //IMPORTANT NOTE: This command will delete all business details and create dummy business, run only in demo server.
            $schedule->command('pos:dummyBusiness')
                    ->cron('0 */3 * * *')
                    //->everyThirtyMinutes()
                    ->emailOutputTo($email);
        }
    }

    /**
     * Register the Closure based commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        
        // Load Shopify module commands - use file_exists to avoid autoloading issues
        // $shopifyCommandPath = base_path('Modules/Shopify/Console/Commands/SyncShopifyData.php');
        // if (file_exists($shopifyCommandPath)) {
        //     try {
        //         // Use autoload with false flag to prevent triggering class loading if not loaded yet
        //         if (class_exists(\Modules\Shopify\Console\Commands\SyncShopifyData::class, false)) {
        //             $this->commands([
        //                 \Modules\Shopify\Console\Commands\SyncShopifyData::class,
        //             ]);
        //         } else {
        //             // If class not loaded yet, require the file explicitly
        //             require_once $shopifyCommandPath;
        //             if (class_exists(\Modules\Shopify\Console\Commands\SyncShopifyData::class)) {
        //                 $this->commands([
        //                     \Modules\Shopify\Console\Commands\SyncShopifyData::class,
        //                 ]);
        //             }
        //         }
        //     } catch (\Exception $e) {
        //         // Silently fail if class can't be loaded to prevent hanging
        //         // Log error if needed: \Log::error('Failed to load Shopify command: ' . $e->getMessage());
        //     }
        // }
        
        require base_path('routes/console.php');
    }
}
