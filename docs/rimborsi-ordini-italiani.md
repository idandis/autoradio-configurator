# Rimborsi italiani — localhost e Stripe sandbox

Nel dettaglio di un ordine di prova pagato è disponibile la sezione Rimborsi. Inserire l’importo in euro (virgola o punto, massimo due decimali), oppure scegliere Tutto il residuo, quindi Rimborsa e confermare. La richiesta interagisce con Stripe. Non cambia la gestione della spedizione e non annulla l’ordine.

Sono mostrati totale rimborsato, residuo disponibile e singoli tentativi. Gli importi in attesa sono riservati; solo i rimborsi riusciti aggiornano il pagamento a Rimborsato parzialmente o Rimborsato. Aggiorna stato rimborsi importa lo stato attuale da Stripe, inclusi rimborsi eseguiti nella dashboard Stripe sul pagamento associato.

La funzione richiede un amministratore, ambiente local/testing, ordine di prova in EUR e credenziali test. Verifica pagamento, importo ricevuto e collegamento all’ordine presso Stripe. Le richieste usano una chiave di idempotenza persistente e importi in centesimi; la versione dell’ordine impedisce invii da pagine obsolete. Un esito di rete incerto conserva la richiesta e permette di riprovare con la stessa chiave. Oltre 23 ore una richiesta incerta senza risposta resta bloccata per riconciliazione, evitando il riuso di una chiave scaduta.

Migrazione: `2026_09_15_120000_create_italian_order_refunds.php`, già applicata in locale.
Il relay Stripe locale deve includere `refund.created,refund.updated,refund.failed,charge.refunded` oltre agli eventi di pagamento. Webhook firmati e verificati nuovamente via API; notifiche ripetute non duplicano registrazioni. Le transazioni restano nella cronologia Stripe.

Verifiche: 45 test, 502 asserzioni (checkout, gestione, pagamenti, rimborsi); build completata. Typecheck generale con errori preesistenti, nessun errore nei file checkout/ordini/Stripe. Rimborso effettivamente eseguito in sandbox: 100 centesimi sull’ordine locale 6, confermato succeeded; ordine ancora cancelled, pagamento partially_refunded. I tre eventi refund.created/charge.refunded/refund.updated hanno ricevuto HTTP 200.

Riferimenti: https://docs.stripe.com/api/refunds/create e https://docs.stripe.com/refunds.
Nessuna pubblicazione su Aruba e nessun rimborso live.
