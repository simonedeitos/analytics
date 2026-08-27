using System.Globalization;
using System.Text;
using ADEtoDB.Models;

namespace ADEtoDB.Services;

public sealed class SqlGeneratorService : IDisposable
{
    private readonly string _outputDirectory;
    private readonly bool _splitByProvince;
    private readonly int _comuneBatchSize;
    private readonly Dictionary<string, OutputState> _outputs = new(StringComparer.OrdinalIgnoreCase);
    private bool _completed;

    public SqlGeneratorService(string outputDirectory, bool splitByProvince, int comuneBatchSize = 250)
    {
        _outputDirectory = outputDirectory;
        _splitByProvince = splitByProvince;
        _comuneBatchSize = Math.Max(1, comuneBatchSize);
        Directory.CreateDirectory(_outputDirectory);
    }

    public void AppendComune(ComuneGmlSet comune)
    {
        var output = GetOutputState(comune);
        EnsureSectionHeader(output, comune);
        output.PendingComuneValues.Add(BuildComuneValues(comune));
        if (output.PendingComuneValues.Count >= _comuneBatchSize) {
            FlushComuni(output);
        }
    }

    public void AppendParcel(
        ComuneGmlSet comune,
        CadastralParts parts,
        string polygonWkt,
        string pointWkt,
        decimal? areaMq,
        string sourceFile)
    {
        var output = GetOutputState(comune);
        EnsureSectionHeader(output, comune);
        FlushComuni(output);

        if (!string.Equals(output.CurrentComuneCode, comune.CodCatastale, StringComparison.OrdinalIgnoreCase)) {
            output.Writer.WriteLine();
            output.Writer.WriteLine($"-- Comune {comune.CodCatastale} · {comune.NomeComune}");
            output.CurrentComuneCode = comune.CodCatastale;
        }

        output.Writer.WriteLine(BuildParcelStatement(comune, parts, polygonWkt, pointWkt, areaMq, sourceFile));
    }

    public IReadOnlyList<GeneratedSqlFile> Complete()
    {
        if (_completed) {
            return _outputs.Values
                .Select(state => new GeneratedSqlFile(state.FileName, state.TempPath))
                .ToList();
        }

        foreach (var output in _outputs.Values) {
            FlushComuni(output);
            output.Writer.Flush();
            output.Writer.Dispose();
        }

        _completed = true;
        return _outputs.Values
            .Select(state => new GeneratedSqlFile(state.FileName, state.TempPath))
            .ToList();
    }

    public void Dispose()
    {
        if (_completed) {
            return;
        }

        foreach (var output in _outputs.Values) {
            FlushComuni(output);
            output.Writer.Dispose();
        }

        _completed = true;
    }

    private OutputState GetOutputState(ComuneGmlSet comune)
    {
        var key = _splitByProvince
            ? $"{SanitizeFileName(comune.RegionName)}_{SanitizeFileName(comune.ProvinceSigla)}"
            : "ALL";

        if (_outputs.TryGetValue(key, out var existing)) {
            return existing;
        }

        var fileName = _splitByProvince
            ? $"ade_preprocessed_{SanitizeFileName(comune.RegionName)}_{SanitizeFileName(comune.ProvinceSigla)}.sql"
            : "ade_preprocessed_import.sql";

        var tempPath = Path.Combine(_outputDirectory, fileName);
        var writer = new StreamWriter(tempPath, append: false, new UTF8Encoding(encoderShouldEmitUTF8Identifier: false));
        var output = new OutputState(fileName, tempPath, writer);
        output.Writer.WriteLine("-- SQL generato da ADEtoDB");
        output.Writer.WriteLine("-- Compatibile con AnalyticsPRO / MySQL / MariaDB");
        output.Writer.WriteLine();
        _outputs[key] = output;
        return output;
    }

    private static void EnsureSectionHeader(OutputState output, ComuneGmlSet comune)
    {
        if (!string.Equals(output.CurrentRegion, comune.RegionName, StringComparison.Ordinal)) {
            FlushComuni(output);
            output.Writer.WriteLine();
            output.Writer.WriteLine("-- ==================================================");
            output.Writer.WriteLine($"-- Regione: {comune.RegionName}");
            output.CurrentRegion = comune.RegionName;
            output.CurrentProvince = null;
            output.CurrentComuneCode = null;
        }

        if (!string.Equals(output.CurrentProvince, comune.ProvinceSigla, StringComparison.OrdinalIgnoreCase)) {
            FlushComuni(output);
            output.Writer.WriteLine($"-- Provincia: {comune.ProvinceSigla}");
            output.CurrentProvince = comune.ProvinceSigla;
            output.CurrentComuneCode = null;
        }
    }

    private static void FlushComuni(OutputState output)
    {
        if (output.PendingComuneValues.Count == 0) {
            return;
        }

        output.Writer.WriteLine("INSERT INTO cadastral_comuni (provincia_sigla, cod_catastale, nome_comune, map_gml_filename, ple_gml_filename)");
        output.Writer.WriteLine("VALUES");
        output.Writer.WriteLine(string.Join("," + Environment.NewLine, output.PendingComuneValues));
        output.Writer.WriteLine("ON DUPLICATE KEY UPDATE");
        output.Writer.WriteLine("    nome_comune = VALUES(nome_comune),");
        output.Writer.WriteLine("    map_gml_filename = VALUES(map_gml_filename),");
        output.Writer.WriteLine("    ple_gml_filename = VALUES(ple_gml_filename),");
        output.Writer.WriteLine("    updated_at = NOW();");
        output.Writer.WriteLine();
        output.PendingComuneValues.Clear();
    }

    private static string BuildComuneValues(ComuneGmlSet comune)
    {
        return $"    ('{EscapeSql(comune.ProvinceSigla)}', '{EscapeSql(comune.CodCatastale)}', '{EscapeSql(comune.NomeComune)}', {SqlStringOrNull(comune.MapFileName)}, '{EscapeSql(comune.PleFileName)}')";
    }

    private static string BuildParcelStatement(
        ComuneGmlSet comune,
        CadastralParts parts,
        string polygonWkt,
        string pointWkt,
        decimal? areaMq,
        string sourceFile)
    {
        var sezione = parts.Sezione is null ? "NULL" : $"'{EscapeSql(parts.Sezione)}'";
        var areaValue = areaMq.HasValue ? areaMq.Value.ToString(CultureInfo.InvariantCulture) : "NULL";

        return
            "INSERT INTO cadastral_parcels (comune_id, cod_catastale, sezione, foglio, particella, geom, interior_point, area_mq, source_file)" + Environment.NewLine +
            $"SELECT id, '{EscapeSql(comune.CodCatastale)}', {sezione}, '{EscapeSql(parts.Foglio)}', '{EscapeSql(parts.Particella)}', " +
            $"ST_GeomFromText('{EscapeSql(polygonWkt)}', 4326), ST_GeomFromText('{EscapeSql(pointWkt)}', 4326), {areaValue}, '{EscapeSql(sourceFile)}'" + Environment.NewLine +
            $"FROM cadastral_comuni WHERE cod_catastale = '{EscapeSql(comune.CodCatastale)}'" + Environment.NewLine +
            "ON DUPLICATE KEY UPDATE" + Environment.NewLine +
            "    geom = VALUES(geom)," + Environment.NewLine +
            "    interior_point = VALUES(interior_point)," + Environment.NewLine +
            "    area_mq = VALUES(area_mq)," + Environment.NewLine +
            "    source_file = VALUES(source_file);";
    }

    private static string EscapeSql(string value)
    {
        return value
            .Replace("\\", "\\\\", StringComparison.Ordinal)
            .Replace("'", "''", StringComparison.Ordinal);
    }

    private static string SqlStringOrNull(string? value)
    {
        return string.IsNullOrWhiteSpace(value) ? "NULL" : $"'{EscapeSql(value)}'";
    }

    private static string SanitizeFileName(string value)
    {
        foreach (var invalid in Path.GetInvalidFileNameChars()) {
            value = value.Replace(invalid, '_');
        }

        return value.Replace(' ', '_');
    }

    private sealed class OutputState
    {
        public OutputState(string fileName, string tempPath, StreamWriter writer)
        {
            FileName = fileName;
            TempPath = tempPath;
            Writer = writer;
        }

        public string FileName { get; }

        public string TempPath { get; }

        public StreamWriter Writer { get; }

        public string? CurrentRegion { get; set; }

        public string? CurrentProvince { get; set; }

        public string? CurrentComuneCode { get; set; }

        public List<string> PendingComuneValues { get; } = [];
    }
}

public sealed record GeneratedSqlFile(string FileName, string TempPath);
