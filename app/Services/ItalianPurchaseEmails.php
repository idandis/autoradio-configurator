<?php

namespace App\Services;

use App\Mail\ItalianPurchaseConfirmation;
use App\Models\ItalianOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class ItalianPurchaseEmails
{
    // Called within the same transaction that first confirms the payment.
    public function record(ItalianOrder $order): void
    {
        if (! $order->paid_at || DB::table('italian_order_emails')->where('italian_order_id', $order->id)->where('kind', 'purchase')->exists()) {
            return;
        }
        $mail = new ItalianPurchaseConfirmation($order->load('items'));
        $id = DB::table('italian_order_emails')->insertGetId([
            'italian_order_id' => $order->id, 'kind' => 'purchase', 'recipient' => $order->email,
            'subject' => $mail->envelope()->subject, 'html' => $mail->render(),
            'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::afterCommit(function () use ($id) {
            try {
                $this->prepare($id);
            } catch (\Throwable $exception) {
                // The scheduled retry processes the durable pending row.
                report($exception);
            }
        });
    }

    public static function configured(): bool
    {
        $mailer = config('mail.default');
        $transport = config("mail.mailers.$mailer.transport");

        return (bool) config('italian_checkout.mail_enabled')
            && in_array($transport, ['smtp', 'sendmail', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun'], true)
            && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false;
    }

    public function prepare(int $id): void
    {
        DB::transaction(function () use ($id) {
            $row = DB::table('italian_order_emails')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->status !== 'pending') {
                return;
            }
            $order = ItalianOrder::find($row->italian_order_id);
            if (! $order) {
                return;
            }
            if (! $order->is_test) {
                if (! app()->environment('production', 'testing') || ! config('italian_checkout.mail_enabled')) {
                    return;
                }
                if (! self::configured()) {
                    throw new \LogicException('Purchase email delivery is not configured.');
                }
                // The row lock serializes webhook/return/scheduler delivery attempts.
                // A failed transport leaves the durable row pending for the next run.
                $fromName = $order->checkout_locale === 'es' ? 'Autoradio Canario' : config('italian_checkout.mail_from_name');
                $sent = Mail::html($row->html, function ($message) use ($row, $fromName) {
                    $message->to($row->recipient)->subject($row->subject)
                        ->from(config('mail.from.address'), $fromName);
                    $message->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID',
                        'italian-purchase-'.$row->id.'@'.parse_url(config('italian_checkout.origin'), PHP_URL_HOST));
                });
                if (! $sent) {
                    throw new \RuntimeException('Purchase email was not accepted by the transport.');
                }
                DB::table('italian_order_emails')->where('id', $id)->update([
                    'status' => 'sent', 'prepared_at' => now(), 'updated_at' => now(),
                ]);

                return;
            }
            if (! app()->environment('local', 'testing')) {
                return;
            }
            $spanish = $order->checkout_locale === 'es';
            $message = (new Email)->from(new Address('anteprima@autoradioitaliano.it', $spanish ? 'Autoradio Canario' : 'Autoradio Italiano'))->to($row->recipient)
                ->subject(($spanish ? '[PRUEBA] ' : '[PROVA] ').$row->subject)->html($row->html)
                ->text(html_entity_decode(strip_tags(str_replace(['<br>', '</p>', '</tr>'], "\n", $row->html)), ENT_QUOTES, 'UTF-8'));
            if (! Storage::disk('local')->put('italian-order-emails/'.$id.'.eml', $message->toString())) {
                throw new \RuntimeException('Cannot save purchase email preview.');
            }
            DB::table('italian_order_emails')->where('id', $id)->update(['status' => 'preview', 'prepared_at' => now(), 'updated_at' => now()]);
        });
    }
}
