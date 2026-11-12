# AutoGenesi — Prezzo di vendita

Il costo di partenza è il prezzo fornitore della singola variante, espresso in EUR. Il costo fornitore resta invariato e separato dal prezzo di vendita.

| Costo fornitore P | Ricarico | Stima importazione aggiunta |
| --- | ---: | --- |
| P < 150 € | 110 € | No, come da prima fascia richiesta |
| 150 € ≤ P < 250 € | 150 € | Sì |
| 250 € ≤ P < 350 € | 170 € | Sì |
| 350 € ≤ P < 450 € | 180 € | Sì |
| P ≥ 450 € | 200 € | Sì |

**Prezzo finale = arrotondamento per eccesso all'euro di (costo + ricarico + stima importazione).** Un risultato già intero non viene incrementato. Nessun decimale nel prezzo di vendita; il costo fornitore conserva i centesimi. Le soglie esatte 150, 250, 350 e 450 appartengono alla fascia che inizia da quella cifra.

## Ricostruzione del preventivo allegato

Fonte: preventivo Correos del 17 luglio 2026 per un solo collo, documento allegato dall'utente `PresupuestoGeneradoLR289747242NL (1)(1).pdf`.

- Valore merce stampato: 210,39 €.
- Dazio esposto al 14%: 32,02 €.
- IGIC esposto al 7%: 18,25 €.
- DUA: 21,81 €; handling: 5,18 €; IGIC sui servizi: 1,89 €. Totale servizi: 28,88 €.
- Totale spese: 32,02 + 18,25 + 28,88 = **79,15 €**.

Il 14% di 210,39 € non produce 32,02 €. Per riprodurre i totali si usa una **base stimata P + 18,32 €**: per questo esempio 228,71 €. L'aggiustamento di 18,32 € è un'ipotesi compatibile con gli importi arrotondati, non un costo di trasporto documentato. Il PDF menziona aggiustamenti FOB per nolo/assicurazione, senza dettagliare questa differenza. Un solo documento non consente di stabilire una tariffa doganale universale.

**Aggiornamento richiesto: IVA al 21% al posto dell’IGIC al 7%, sia sull’importazione sia sui servizi Correos.** I valori del documento sopra rimangono quelli originali; la formula seguente è la simulazione commerciale aggiornata.

Formula commerciale di stima, per P ≥ 150 €:

1. B = P + 18,32 €.
2. Dazio stimato = arrotonda ai centesimi(B × 14%).
3. IVA importazione stimata = arrotonda ai centesimi((B + dazio) × 21%).
4. Servizi stimati = 21,81 + 5,18 + arrotonda ai centesimi((21,81 + 5,18) × 21%) = 32,66 €.
5. Stima importazione = dazio + IVA importazione + servizi.

Esempio con IVA 21%: dazio 32,02 €, IVA importazione 54,75 €, servizi 32,66 €, totale stima 119,43 €. **210,39 + 150 + 119,43 = 479,82 → 480 €**.

L'aggiustamento della base non viene sommato una seconda volta al prezzo: serve solo alla ricostruzione della stima. Sotto 150 € l'assenza della maggiorazione doganale è una regola commerciale richiesta, non una dichiarazione di esenzione fiscale.

Il calcolo usa centesimi interi e arrotondamento delle singole voci ai centesimi; soltanto il totale finale viene arrotondato all'euro per eccesso. I dettagli della stima restano nei dati della variante. Il prezzo finale resta modificabile manualmente.

### Abbinamento sigle OSSURET / HIZPO

Profilo specifico della tabella QS/T con Qualcomm 6115 / Allwinner A100 fornita dal gestore; non è una regola universale per tutte le marche o generazioni.

| Sigla | Nuclei | RAM GB | Memoria GB |
| --- | --- | --- | --- |
| QS1 | 8 | 3 | 32 |
| QS2 | 8 | 4 | 32 |
| QS4 | 8 | 4 | 32 |
| QS6 | 8 | 6 | 64 |
| QS8 | 8 | 8 | 128 |
| T1 | 4 | 1 | 32 |
| T2CP | 4 | 2 | 32 |
| T3CP | 4 | 2 | 64 |
| T0 | non determinati | 1 | 16 |

Attivare per OSSURET/HIZPO riconoscibili nel prodotto o nella fonte API. Per altri marchi richiedere evidenza della famiglia equivalente: più codici QS, un codice T2CP/T3CP e processore 6115/A100 nel contesto prodotto. Sigle isolate di marchi diversi non bastano. Non rinominare le varianti: conservare nome e SKU originali, registrando il profilo nell’audit dell’abbinamento.

QS2 e QS4 hanno la stessa memoria ma non sono intercambiabili automaticamente: usare il codice esatto se presente; altrimenti lasciare la scelta manuale. Nomi LOW/HIGH, CAM e risoluzioni esplicite restano vincoli. DVR/360 restano esclusi; specifiche esplicite discordanti con questa tabella richiedono verifica manuale. Non dedurre per T0 un numero di nuclei non indicato. Applicare i consueti controlli su disponibilità, unicità dell’abbinamento e preferenza del magazzino.


### Estensione seconda tabella: serie P

Per lo stesso profilo OSSURET/HIZPO e famiglie equivalenti documentate: P1 = 4Core 1GB/32GB; P2CP = 4Core 2GB/32GB; P3CP = 4Core 2GB/64GB. P1L = 1GB/16GB con nuclei non specificati: il prefisso P non autorizza a dedurre 4Core per questa variante.

P1/P2CP/P3CP nella seconda tabella riportano Android 13, mentre la precedente serie T riportava Android 14: non considerare P e T automaticamente intercambiabili sulla sola base della memoria. Questa estensione serve all’abbinamento dei codici, senza attribuire universalmente Android, chip radio o altre caratteristiche a prodotti diversi.


### Alias serie S / QS

Nel solo profilo OSSURET/HIZPO e famiglie equivalenti riconosciute, normalizzare S1→QS1, S2→QS2, S4→QS4, S6→QS6, S8→QS8 per il confronto. RAM/ROM e nuclei seguono la tabella QS. Conservare il nome fornitore originale e tutti i qualificatori (LOW/HIGH, CAM, risoluzione). Non estendere la mappa a sigle non documentate come S9 o ad altri marchi senza evidenza della famiglia. Se S6 e QS6 sono due SKU distinti senza altri elementi per distinguerli, mantenere l’ambiguità invece di sceglierne uno arbitrariamente.
