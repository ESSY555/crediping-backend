<?php

namespace App\Mail;

use App\Models\User;
use App\Services\PasswordOtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class ChangePasswordOtpMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public User $user, public string $code)
    {
        $this->withSymfonyMessage(function (Email $message) {
            $from = (string) config('mail.from.address');
            $message->returnPath($from);
            $message->sender($from);
        });
    }

    public function envelope(): Envelope
    {
        $from = (string) config('mail.from.address');
        $name = (string) (config('mail.from.name') ?: config('app.name'));

        return new Envelope(
            from: new Address($from, $name),
            subject: 'Your Crediping password change code',
        );
    }

    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Auto-Response-Suppress' => 'All',
            ],
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.change-password-otp',
            text: 'emails.change-password-otp-text',
            with: [
                'name' => $this->user->name,
                'code' => $this->code,
                'minutes' => PasswordOtpService::TTL_MINUTES,
            ],
        );
    }
}
