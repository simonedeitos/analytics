using System.Text.RegularExpressions;
using ADEtoDB.Models;

namespace ADEtoDB.Services;

public sealed class CadastralReferenceParser
{
    private static readonly Regex PrefixedReferenceRegex = new(@"^[A-Z0-9]{4}[._/\-](.+)$", RegexOptions.Compiled);
    private static readonly Regex StandardReferenceRegex = new(@"^(?:(?<sezione>[A-Z]{1,4})[._/\-])?(?<foglio>\d{1,6})[._/\-](?<particella>[A-Z0-9]{1,20})$", RegexOptions.Compiled);
    private static readonly Regex AttachedSectionRegex = new(@"^(?:[A-Z0-9]{4})?(?<sezione>[A-Z]{1,4})(?<foglio>\d{2,6})[._/\-](?<particella>[A-Z0-9]{1,20})$", RegexOptions.Compiled);
    private static readonly Regex WhitespaceRegex = new(@"\s+", RegexOptions.Compiled);
    private static readonly Regex SanitizerRegex = new(@"[^A-Z0-9._/\-]", RegexOptions.Compiled);

    public CadastralParts? Parse(string? nationalReference, string? label)
    {
        foreach (var candidate in new[] { nationalReference, label }) {
            if (string.IsNullOrWhiteSpace(candidate)) {
                continue;
            }

            var normalized = SanitizerRegex.Replace(
                WhitespaceRegex.Replace(candidate.Trim().ToUpperInvariant(), "."),
                string.Empty
            );

            if (string.IsNullOrWhiteSpace(normalized)) {
                continue;
            }

            var prefixedMatch = PrefixedReferenceRegex.Match(normalized);
            if (prefixedMatch.Success) {
                normalized = prefixedMatch.Groups[1].Value;
            }

            var standardMatch = StandardReferenceRegex.Match(normalized);
            if (standardMatch.Success) {
                return new CadastralParts
                {
                    Sezione = standardMatch.Groups["sezione"].Success ? standardMatch.Groups["sezione"].Value : null,
                    Foglio = standardMatch.Groups["foglio"].Value,
                    Particella = standardMatch.Groups["particella"].Value,
                };
            }

            var attachedMatch = AttachedSectionRegex.Match(normalized);
            if (attachedMatch.Success) {
                return new CadastralParts
                {
                    Sezione = attachedMatch.Groups["sezione"].Value,
                    Foglio = attachedMatch.Groups["foglio"].Value,
                    Particella = attachedMatch.Groups["particella"].Value,
                };
            }

            var tokens = normalized
                .Split(['.', '_', '/', '-'], StringSplitOptions.RemoveEmptyEntries | StringSplitOptions.TrimEntries);

            if (tokens.Length < 2) {
                continue;
            }

            var particella = tokens[^1];
            var foglio = tokens[^2];
            var sezione = tokens.Length > 2 ? string.Join('.', tokens.Take(tokens.Length - 2)) : null;

            if (foglio.All(char.IsDigit) && particella.All(value => char.IsLetterOrDigit(value))) {
                return new CadastralParts
                {
                    Sezione = string.IsNullOrWhiteSpace(sezione) ? null : sezione,
                    Foglio = foglio,
                    Particella = particella,
                };
            }
        }

        return null;
    }
}
