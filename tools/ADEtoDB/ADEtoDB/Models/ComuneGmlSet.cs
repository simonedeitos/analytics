namespace ADEtoDB.Models;

public sealed class ComuneGmlSet
{
    public required string RegionName { get; init; }

    public required string ProvinceSigla { get; init; }

    public required string CodCatastale { get; init; }

    public required string NomeComune { get; init; }

    public required string PlePath { get; init; }

    public string? MapPath { get; init; }

    public required string PleFileName { get; init; }

    public string? MapFileName { get; init; }
}
