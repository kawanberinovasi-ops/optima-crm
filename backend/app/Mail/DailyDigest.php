<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/** Ringkasan follow-up yang jatuh tempo dan telat, dikirim setiap pagi (SRS 4.2). */
class DailyDigest extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  Collection<int, array{name: string, next: string, due: string, late: bool}>  $items */
    public function __construct(public User $user, public Collection $items, public int $late) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'OPTIMA CRM: '.$this->items->count().' follow-up hari ini'.($this->late ? ', '.$this->late.' telat' : ''));
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-digest', with: ['url' => rtrim(config('app.url'), '/').'/#/mywork']);
    }
}
