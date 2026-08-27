# ADEtoDB

Tool companion Windows Forms per preprocessare localmente gli archivi ADE e generare un file `.sql` compatibile con la terza modalità di import di AnalyticsPRO.

## Prerequisiti

- Windows 10/11
- Visual Studio 2022 con workload **.NET desktop development**
- SDK .NET 8

## Contenuto

```text
tools/ADEtoDB/
  ADEtoDB.sln
  ADEtoDB/
    ADEtoDB.csproj
    Program.cs
    MainForm.cs
    MainForm.Designer.cs
    MainForm.resx
    Models/
    Services/
```

## Build

1. Apri `tools/ADEtoDB/ADEtoDB.sln` in Visual Studio.
2. Se richiesto, consenti il restore dei pacchetti/runtime di targeting Windows.
3. Compila il progetto `ADEtoDB` in configurazione Debug o Release.

## Utilizzo

1. Prepara una cartella radice, ad esempio `D:\ADE\`, con sottocartelle per regione:
   ```text
   D:\ADE\
     Lombardia\
       BS.zip
       BG.zip
     Lazio\
       RM.zip
   ```
2. Avvia `ADEtoDB`.
3. Seleziona la cartella radice.
4. Facoltativo: attiva **"Genera un file SQL separato per provincia"** se non vuoi l'output unico predefinito.
5. Clicca **Avvia elaborazione**.
6. Attendi il completamento: il log live mostra regione, provincia, comune e avanzamento.
7. Salva il file `.sql` generato (oppure i file, se hai scelto la suddivisione per provincia).

## Output generato

Il tool:

- estrae ricorsivamente ZIP provinciali e ZIP comunali annidati
- analizza i file `*_ple.gml` con la stessa logica del parser PHP di AnalyticsPRO
- interpreta i riferimenti catastali con o senza separatore tra sezione e foglio (`M393B000300.369`)
- genera WKT in ordine assi **`lat lng`** per `ST_GeomFromText(..., 4326)`
- produce:
  - `INSERT ... ON DUPLICATE KEY UPDATE` multi-riga per `cadastral_comuni`
  - statement `INSERT INTO cadastral_parcels ... SELECT id FROM cadastral_comuni ... ON DUPLICATE KEY UPDATE` per le particelle

## Flusso end-to-end con AnalyticsPRO

1. Genera localmente il file SQL con ADEtoDB.
2. Caricalo su AnalyticsPRO:
   - direttamente dal tab **Importa file SQL pre-elaborato**, oppure
   - via FTP/file manager in `analyticspro/storage/manual_upload/` e poi selezionalo dallo stesso tab.
3. Avvia il job.
4. Monitora il log live nella pagina admin **Import cartografia ADE**.
