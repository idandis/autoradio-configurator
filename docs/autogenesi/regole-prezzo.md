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
