<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class PlatformAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $recipientName, public string $emailSubject, public string $emailBody)
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
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.platform-announcement',
            text: 'emails.platform-announcement-text',
            with: [
                'name' => $this->recipientName,
                'body' => $this->emailBody,
            ],
        );
    }
}
