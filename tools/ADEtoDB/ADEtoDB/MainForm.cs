using ADEtoDB.Models;
using ADEtoDB.Services;

namespace ADEtoDB;

public partial class MainForm : Form
{
    private CancellationTokenSource? _processingCancellation;
    private string? _workingDirectory;
    private IReadOnlyList<GeneratedSqlFile> _generatedFiles = Array.Empty<GeneratedSqlFile>();

    public MainForm()
    {
        InitializeComponent();
        UpdateUiState(isRunning: false);
        AppendLog("Pronto. Seleziona la cartella radice che contiene le regioni ADE.");
    }

    protected override void OnFormClosed(FormClosedEventArgs e)
    {
        _processingCancellation?.Cancel();
        TryDeleteWorkingDirectory();
        base.OnFormClosed(e);
    }

    private async void StartButton_Click(object? sender, EventArgs e)
    {
        await StartProcessingAsync();
    }

    private void CancelButton_Click(object? sender, EventArgs e)
    {
        _processingCancellation?.Cancel();
    }

    private void BrowseButton_Click(object? sender, EventArgs e)
    {
        using var dialog = new FolderBrowserDialog
        {
            Description = "Seleziona la cartella che contiene le sottocartelle delle regioni ADE.",
            UseDescriptionForTitle = true,
            InitialDirectory = Directory.Exists(rootPathTextBox.Text) ? rootPathTextBox.Text : Environment.GetFolderPath(Environment.SpecialFolder.MyDocuments),
        };

        if (dialog.ShowDialog(this) == DialogResult.OK) {
            rootPathTextBox.Text = dialog.SelectedPath;
        }
    }

    private void SaveButton_Click(object? sender, EventArgs e)
    {
        SaveGeneratedFiles();
    }

    private async Task StartProcessingAsync()
    {
        var rootPath = rootPathTextBox.Text.Trim();
        if (!Directory.Exists(rootPath)) {
            MessageBox.Show(this, "Seleziona una cartella radice valida.", "ADEtoDB", MessageBoxButtons.OK, MessageBoxIcon.Warning);
            return;
        }

        TryDeleteWorkingDirectory();
        _workingDirectory = Path.Combine(Path.GetTempPath(), "ADEtoDB", Guid.NewGuid().ToString("N"));
        Directory.CreateDirectory(_workingDirectory);
        _generatedFiles = Array.Empty<GeneratedSqlFile>();
        logTextBox.Clear();
        AppendLog($"Cartella radice: {rootPath}");
        UpdateUiState(isRunning: true);

        _processingCancellation = new CancellationTokenSource();
        var progress = new Progress<ProcessingStatus>(status => UpdateProgress(status));

        try {
            var generatedFiles = await Task.Run(
                () => ProcessRootPath(rootPath, splitByProvinceCheckBox.Checked, progress, _processingCancellation.Token),
                _processingCancellation.Token
            );

            _generatedFiles = generatedFiles;
            UpdateProgress(new ProcessingStatus("Elaborazione completata.", 1, 1, IsIndeterminate: false));
            AppendLog($"File SQL generati: {_generatedFiles.Count}.");
            UpdateUiState(isRunning: false);

            if (_generatedFiles.Count > 0) {
                saveButton.Enabled = true;
                SaveGeneratedFiles();
            }
        } catch (OperationCanceledException) {
            AppendLog("Elaborazione annullata.");
            statusLabel.Text = "Operazione annullata.";
            progressBar.Style = ProgressBarStyle.Blocks;
            progressBar.Value = 0;
            UpdateUiState(isRunning: false);
        } catch (Exception exception) {
            AppendLog("ERRORE: " + exception.Message);
            statusLabel.Text = "Errore durante l'elaborazione.";
            progressBar.Style = ProgressBarStyle.Blocks;
            progressBar.Value = 0;
            UpdateUiState(isRunning: false);
            MessageBox.Show(this, exception.Message, "ADEtoDB", MessageBoxButtons.OK, MessageBoxIcon.Error);
        } finally {
            _processingCancellation?.Dispose();
            _processingCancellation = null;
        }
    }

    private IReadOnlyList<GeneratedSqlFile> ProcessRootPath(
        string rootPath,
        bool splitByProvince,
        IProgress<ProcessingStatus> progress,
        CancellationToken cancellationToken)
    {
        var extractionService = new ZipExtractionService();
        var parserService = new GmlParserService();
        var referenceParser = new CadastralReferenceParser();
        var geometryService = new GeometryService();

        progress.Report(new ProcessingStatus("Scansione cartelle regioni ed estrazione ZIP in corso…", null, null, IsIndeterminate: true));

        var comuneSets = extractionService.ExtractAndDiscover(
            rootPath,
            Path.Combine(_workingDirectory ?? throw new InvalidOperationException("Working directory non inizializzata."), "work"),
            message => progress.Report(new ProcessingStatus(message, null, null, IsIndeterminate: true)),
            cancellationToken
        );

        if (comuneSets.Count == 0) {
            throw new InvalidOperationException("Nessun file ADE compatibile trovato nella cartella selezionata.");
        }

        progress.Report(new ProcessingStatus($"Comuni individuati: {comuneSets.Count}. Avvio generazione SQL…", 0, comuneSets.Count, IsIndeterminate: false));

        using var sqlGenerator = new SqlGeneratorService(
            Path.Combine(_workingDirectory!, "output"),
            splitByProvince
        );

        var processedComuni = 0;
        var generatedParcels = 0;

        foreach (var comuneSet in comuneSets) {
            cancellationToken.ThrowIfCancellationRequested();

            progress.Report(new ProcessingStatus(
                $"Elaboro {comuneSet.RegionName} / {comuneSet.ProvinceSigla} / {comuneSet.NomeComune} ({comuneSet.CodCatastale})…",
                processedComuni,
                comuneSets.Count,
                IsIndeterminate: false
            ));

            var parcels = parserService.ParseCadastralParcelsGml(comuneSet.PlePath);
            sqlGenerator.AppendComune(comuneSet);

            var writtenForComune = 0;
            var skippedForComune = 0;

            foreach (var parcel in parcels) {
                cancellationToken.ThrowIfCancellationRequested();

                var parts = referenceParser.Parse(parcel.NationalReference, parcel.Label);
                if (parts is null) {
                    skippedForComune++;
                    continue;
                }

                if (parcel.Points.Count < 4) {
                    skippedForComune++;
                    continue;
                }

                var polygonWkt = geometryService.PolygonToWkt(parcel.Points);
                if (string.IsNullOrWhiteSpace(polygonWkt)) {
                    skippedForComune++;
                    continue;
                }

                var interior = geometryService.ComputePolygonInteriorPoint(parcel.Points);
                if (interior.Point is null) {
                    skippedForComune++;
                    continue;
                }

                var pointWkt = geometryService.PointToWkt(interior.Point.Value);
                if (string.IsNullOrWhiteSpace(pointWkt)) {
                    skippedForComune++;
                    continue;
                }

                sqlGenerator.AppendParcel(
                    comuneSet,
                    parts,
                    polygonWkt,
                    pointWkt,
                    parcel.AreaMq,
                    Path.GetFileName(comuneSet.PlePath)
                );

                writtenForComune++;
                generatedParcels++;
            }

            processedComuni++;
            progress.Report(new ProcessingStatus(
                $"Comune pronto: {comuneSet.NomeComune} ({comuneSet.CodCatastale}) · particelle SQL {writtenForComune}, scartate {skippedForComune}.",
                processedComuni,
                comuneSets.Count,
                IsIndeterminate: false
            ));
        }

        var generatedFiles = sqlGenerator.Complete();
        progress.Report(new ProcessingStatus(
            $"Generazione completata. Totale particelle SQL scritte: {generatedParcels}.",
            comuneSets.Count,
            comuneSets.Count,
            IsIndeterminate: false
        ));

        return generatedFiles;
    }

    private void SaveGeneratedFiles()
    {
        if (_generatedFiles.Count == 0) {
            MessageBox.Show(this, "Nessun file SQL disponibile da salvare.", "ADEtoDB", MessageBoxButtons.OK, MessageBoxIcon.Information);
            return;
        }

        if (_generatedFiles.Count == 1) {
            var file = _generatedFiles[0];
            using var dialog = new SaveFileDialog
            {
                Filter = "SQL files (*.sql)|*.sql|All files (*.*)|*.*",
                FileName = file.FileName,
                Title = "Salva file SQL generato",
            };

            if (dialog.ShowDialog(this) != DialogResult.OK) {
                return;
            }

            File.Copy(file.TempPath, dialog.FileName, overwrite: true);
            AppendLog("File salvato in " + dialog.FileName);
            return;
        }

        using var folderDialog = new FolderBrowserDialog
        {
            Description = "Seleziona la cartella di destinazione per i file SQL generati.",
            UseDescriptionForTitle = true,
            InitialDirectory = rootPathTextBox.Text,
        };

        if (folderDialog.ShowDialog(this) != DialogResult.OK) {
            return;
        }

        foreach (var file in _generatedFiles) {
            var destination = Path.Combine(folderDialog.SelectedPath, file.FileName);
            File.Copy(file.TempPath, destination, overwrite: true);
        }

        AppendLog($"File salvati in {folderDialog.SelectedPath}");
    }

    private void UpdateProgress(ProcessingStatus status)
    {
        if (status.IsIndeterminate || !status.Current.HasValue || !status.Total.HasValue || status.Total <= 0) {
            progressBar.Style = ProgressBarStyle.Marquee;
            progressBar.MarqueeAnimationSpeed = 25;
        } else {
            progressBar.Style = ProgressBarStyle.Blocks;
            progressBar.MarqueeAnimationSpeed = 0;
            progressBar.Maximum = status.Total.Value;
            progressBar.Value = Math.Max(progressBar.Minimum, Math.Min(status.Current.Value, progressBar.Maximum));
        }

        statusLabel.Text = status.Message;
        AppendLog(status.Message);
    }

    private void UpdateUiState(bool isRunning)
    {
        browseButton.Enabled = !isRunning;
        rootPathTextBox.Enabled = !isRunning;
        splitByProvinceCheckBox.Enabled = !isRunning;
        startButton.Enabled = !isRunning;
        cancelButton.Enabled = isRunning;
        saveButton.Enabled = !isRunning && _generatedFiles.Count > 0;
    }

    private void AppendLog(string message)
    {
        logTextBox.AppendText($"[{DateTime.Now:yyyy-MM-dd HH:mm:ss}] {message}{Environment.NewLine}");
        logTextBox.SelectionStart = logTextBox.TextLength;
        logTextBox.ScrollToCaret();
    }

    private void TryDeleteWorkingDirectory()
    {
        if (string.IsNullOrWhiteSpace(_workingDirectory) || !Directory.Exists(_workingDirectory)) {
            return;
        }

        try {
            Directory.Delete(_workingDirectory, recursive: true);
        } catch {
            // Ignora cleanup best-effort.
        }
    }

    private sealed record ProcessingStatus(string Message, int? Current, int? Total, bool IsIndeterminate);
}
