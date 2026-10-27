<?php

namespace App\Services;

class WhatsAppWelcome
{
    public function greeting(string $text, string $locale): string
    {
        $greetings = ['buenos días', 'buenas tardes', 'buenas noches', 'good morning', 'good afternoon', 'good evening', 'buongiorno', 'buonasera', 'salve', 'ciao', 'hola', 'hello', 'hi'];
        foreach ($greetings as $greeting) {
            if (preg_match('/^\s*'.preg_quote($greeting, '/').'\b/ui', $text)) {
                return mb_strtoupper(mb_substr($greeting, 0, 1)).mb_substr($greeting, 1).'!';
            }
        }

        return ['it' => 'Ciao!', 'es' => '¡Hola!', 'en' => 'Hello!'][$locale];
    }

    public function onlyGreeting(string $text): bool
    {
        return (bool) preg_match('/^\s*(ciao|salve|buongiorno|buonasera|hola|buenos días|buenas tardes|buenas noches|hello|hi|good morning|good afternoon|good evening)[\s!.¡¿?]*$/ui', $text);
    }

    public function stripGreeting(string $text): string
    {
        return trim(preg_replace('/^\s*[¡]?(?:ciao|salve|buongiorno|buonasera|hola|buenos días|buenas tardes|buenas noches|hello|hi|good morning|good afternoon|good evening)\b[\s!,.:;-]*/ui', '', $text));
    }
}
