<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Tenancy\TenantContext::class, function () {
            return new \App\Tenancy\TenantContext();
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            return rtrim(config('app.frontend_url'), '/') . '/reset-password?token=' . urlencode($token) . '&email=' . urlencode($user->email);
        });

        Event::listen(MessageSent::class, function (MessageSent $event): void {
            Log::info('Mail accepted by SMTP.', [
                'to' => collect($event->message->getTo())->map->getAddress()->all(),
                'from' => collect($event->message->getFrom())->map->getAddress()->all(),
                'subject' => $event->message->getSubject(),
                'message_id' => $event->message->getHeaders()->get('Message-ID')?->getBodyAsString(),
            ]);
        });
    }
}
