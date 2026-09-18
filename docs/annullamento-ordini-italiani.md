# Annullamento ordini italiani (localhost)

Nel dettaglio ordine il pulsante “Annulla ordine” richiede conferma ed è disponibile per gli amministratori quando l’ordine è Da preparare o In preparazione. L’ordine rimane visibile e filtrabile come Annullato; pagamento, prodotti e cronologia sono conservati. Non viene eseguito alcun rimborso.

Le modifiche alla gestione di un ordine annullato sono bloccate lato server. Il cambio registra operatore e data nella cronologia e verifica la versione per evitare modifiche obsolete. Ordini spediti o consegnati non sono annullabili.

Prima dell’annullamento vengono chiuse le sessioni Stripe ancora aperte. Se Stripe non risponde o un tentativo è ancora in creazione, l’annullamento viene bloccato e la pagina mostra un errore. Un eventuale pagamento già completato resta registrabile tramite webhook anche dopo l’annullamento, senza riattivare la spedizione. Nessun nuovo pagamento è consentito per l’ordine annullato.

Non sono necessarie nuove migrazioni per questa funzione. Rimborsi totali/parziali non ancora implementati.
