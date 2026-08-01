<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SuspiciousLoginAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public int $failedLoginCount,
        public int $windowHours,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Security alert: {$this->failedLoginCount} failed login attempts",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.suspicious-login-alert',
        );
    }
}
