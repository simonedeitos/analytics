using System.IO.Compression;
using ADEtoDB.Models;

namespace ADEtoDB.Services;

public sealed class ZipExtractionService
{
    public IReadOnlyList<ComuneGmlSet> ExtractAndDiscover(
        string rootPath,
        string workingDirectory,
        Action<string>? log,
        CancellationToken cancellationToken)
    {
        if (!Directory.Exists(rootPath)) {
            throw new DirectoryNotFoundException($"Cartella radice non trovata: {rootPath}");
        }

        Directory.CreateDirectory(workingDirectory);
        var comuni = new List<ComuneGmlSet>();

        foreach (var regionDirectory in Directory.GetDirectories(rootPath).OrderBy(path => path, StringComparer.OrdinalIgnoreCase)) {
            cancellationToken.ThrowIfCancellationRequested();

            var regionName = Path.GetFileName(regionDirectory);
            log?.Invoke($"Regione corrente: {regionName}");

            foreach (var provinceZip in Directory.GetFiles(regionDirectory, "*.zip", SearchOption.TopDirectoryOnly).OrderBy(path => path, StringComparer.OrdinalIgnoreCase)) {
                cancellationToken.ThrowIfCancellationRequested();

                var provinceSigla = Path.GetFileNameWithoutExtension(provinceZip).ToUpperInvariant();
                log?.Invoke($"Provincia corrente: {provinceSigla}.zip");

                var provinceTarget = Path.Combine(workingDirectory, SanitizeSegment(regionName), SanitizeSegment(provinceSigla));
                Directory.CreateDirectory(provinceTarget);

                ExtractNestedZip(provinceZip, provinceTarget, log, cancellationToken);
                comuni.AddRange(DiscoverComuneGmlSets(provinceTarget, regionName, provinceSigla));
            }
        }

        return comuni
            .OrderBy(entry => entry.RegionName, StringComparer.OrdinalIgnoreCase)
            .ThenBy(entry => entry.ProvinceSigla, StringComparer.OrdinalIgnoreCase)
            .ThenBy(entry => entry.CodCatastale, StringComparer.OrdinalIgnoreCase)
            .ToList();
    }

    private static void ExtractNestedZip(
        string zipPath,
        string targetDirectory,
        Action<string>? log,
        CancellationToken cancellationToken)
    {
        using var archive = ZipFile.OpenRead(zipPath);
        var targetRoot = Path.GetFullPath(targetDirectory);
        var comparison = OperatingSystem.IsWindows() ? StringComparison.OrdinalIgnoreCase : StringComparison.Ordinal;
        foreach (var entry in archive.Entries) {
            cancellationToken.ThrowIfCancellationRequested();

            var fullDestinationPath = BuildSafeDestinationPath(targetRoot, entry.FullName, comparison);

            var destinationDirectory = Path.GetDirectoryName(fullDestinationPath);
            if (!string.IsNullOrWhiteSpace(destinationDirectory)) {
                Directory.CreateDirectory(destinationDirectory);
            }

            if (string.IsNullOrEmpty(entry.Name)) {
                Directory.CreateDirectory(fullDestinationPath);
                continue;
            }

            entry.ExtractToFile(fullDestinationPath, overwrite: true);
            if (fullDestinationPath.EndsWith(".zip", StringComparison.OrdinalIgnoreCase)) {
                var nestedTarget = Path.Combine(Path.GetDirectoryName(fullDestinationPath) ?? targetDirectory, Path.GetFileNameWithoutExtension(fullDestinationPath));
                Directory.CreateDirectory(nestedTarget);
                log?.Invoke($"Estraggo archivio comune: {Path.GetFileName(fullDestinationPath)}");
                ExtractNestedZip(fullDestinationPath, nestedTarget, log, cancellationToken);
            }
        }
    }

    private static IReadOnlyList<ComuneGmlSet> DiscoverComuneGmlSets(string rootDirectory, string regionName, string provinceSigla)
    {
        var pleFiles = Directory
            .GetFiles(rootDirectory, "*_ple.gml", SearchOption.AllDirectories)
            .OrderBy(path => path, StringComparer.OrdinalIgnoreCase)
            .ToList();

        var results = new List<ComuneGmlSet>();
        foreach (var plePath in pleFiles) {
            var filename = Path.GetFileName(plePath);
            var baseName = filename[..^"_ple.gml".Length];
            var parts = baseName.Split('_', 2, StringSplitOptions.TrimEntries);
            if (parts.Length != 2) {
                continue;
            }

            var mapPath = Path.Combine(Path.GetDirectoryName(plePath) ?? string.Empty, baseName + "_map.gml");
            results.Add(new ComuneGmlSet
            {
                RegionName = regionName,
                ProvinceSigla = provinceSigla,
                CodCatastale = parts[0].Trim().ToUpperInvariant(),
                NomeComune = parts[1].Replace('_', ' ').Trim(),
                PlePath = plePath,
                MapPath = File.Exists(mapPath) ? mapPath : null,
                PleFileName = filename,
                MapFileName = File.Exists(mapPath) ? Path.GetFileName(mapPath) : null,
            });
        }

        return results;
    }

    private static string SanitizeSegment(string value)
    {
        foreach (var invalid in Path.GetInvalidFileNameChars()) {
            value = value.Replace(invalid, '_');
        }

        return value.Replace(' ', '_');
    }

    private static string BuildSafeDestinationPath(string targetRoot, string entryPath, StringComparison comparison)
    {
        var normalizedEntryPath = entryPath
            .Replace('/', Path.DirectorySeparatorChar)
            .Replace('\\', Path.DirectorySeparatorChar);

        if (Path.IsPathRooted(normalizedEntryPath)) {
            throw new InvalidDataException($"ZIP entry non valida con path assoluto: {entryPath}");
        }

        var pathSegments = normalizedEntryPath.Split(Path.DirectorySeparatorChar, StringSplitOptions.RemoveEmptyEntries);
        if (pathSegments.Any(segment => segment is "." or "..")) {
            throw new InvalidDataException($"ZIP entry non valida con path traversal: {entryPath}");
        }

        var fullDestinationPath = Path.GetFullPath(Path.Combine(targetRoot, Path.Combine(pathSegments)));
        var allowedPrefix = targetRoot.EndsWith(Path.DirectorySeparatorChar)
            ? targetRoot
            : targetRoot + Path.DirectorySeparatorChar;

        if (!fullDestinationPath.StartsWith(allowedPrefix, comparison) && !string.Equals(fullDestinationPath, targetRoot, comparison)) {
            throw new InvalidDataException($"ZIP entry non valida fuori dalla directory di estrazione: {entryPath}");
        }

        return fullDestinationPath;
    }
}
