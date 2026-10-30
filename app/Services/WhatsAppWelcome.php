<?php

namespace App\Services;

class WhatsAppWelcome
{
    public function initialMessage(string $locale): string
    {
        $message = [
            'it' => "Ciao 👋 Benvenuto da AutoRadioCanario e grazie per averci scritto!\n\nIndicaci per favore:\n- La provincia da cui ci scrivi\n- La marca, il modello e l’anno della tua auto\n- E una foto del vano in cui si trova la radio attuale\nCon queste informazioni ti consiglieremo lo schermo migliore senza impegno e ti risponderemo il prima possibile.\nGrazie mille!",
            'es' => "Hola 👋 ¡Bienvenido a AutoRadioCanario y gracias por escribirnos!\n\nPor favor indícanos:\n- La provincia desde la que nos escribes\n- La marca, modelo y año de tu coche\n- Y una foto del hueco donde va la radio actual\nCon esa información te recomendaremos la mejor pantalla sin compromiso y te responderemos lo antes posible.\n¡Muchas gracias!",
            'en' => "Hello 👋 Welcome to AutoRadioCanario and thank you for contacting us!\n\nPlease tell us:\n- The province you are contacting us from\n- Your car’s make, model and year\n- And send a photo of the opening where the current radio is fitted\nWith this information, we will recommend the best screen with no obligation and reply as soon as possible.\nThank you very much!",
        ][$locale];
        $greeting = ['it' => 'Ciao 👋', 'es' => 'Hola 👋', 'en' => 'Hello 👋'][$locale];

        return $greeting.' '.app(WhatsAppAssistantSettings::class)->get()['welcome_messages'][$locale]."\n\n".explode("\n\n", $message, 2)[1];
    }

    public function greeting(string $text, string $locale): string
    {
        $greetings = ['buenos días', 'buenas tardes', 'buenas noches', 'buenas', 'good morning', 'good afternoon', 'good evening', 'buongiorno', 'buonasera', 'salve', 'ciao', 'hola', 'hello', 'hi'];
        foreach ($greetings as $greeting) {
            if (preg_match('/^[\s¡¿]*'.str_replace(['días', ' '], ['d[ií]as', '\s+'], preg_quote($greeting, '/')).'\b/ui', $text)) {
                return mb_strtoupper(mb_substr($greeting, 0, 1)).mb_substr($greeting, 1).'!';
            }
        }

        return ['it' => 'Ciao!', 'es' => '¡Hola!', 'en' => 'Hello!'][$locale];
    }

    public function onlyGreeting(string $text): bool
    {
        return (bool) preg_match('/^[\s¡¿]*(ciao|salve|buongiorno|buonasera|hola|buenos\s+d[ií]as|buenas(?:\s+(?:tardes|noches))?|hello|hi|good morning|good afternoon|good evening)[\s!.¡¿?]*$/ui', $text);
    }

    public function stripGreeting(string $text): string
    {
        return trim(preg_replace('/^[\s¡¿]*(?:ciao|salve|buongiorno|buonasera|hola|buenos\s+d[ií]as|buenas(?:\s+(?:tardes|noches))?|hello|hi|good morning|good afternoon|good evening)\b[\s!,.:;-]*/ui', '', $text));
    }
}
